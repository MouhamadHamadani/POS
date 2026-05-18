<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Services\CurrencyService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReturnController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CurrencyService $currency,
    ) {}

    public function index(Request $request): View
    {
        $q = ReturnTransaction::with('sale:id,receipt_number', 'user:id,name', 'approver:id,name');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $returns = $q->latest()->paginate(25)->withQueryString();
        return view('returns.index', compact('returns'));
    }

    public function searchSale(): View
    {
        return view('returns.search-sale');
    }

    public function findSale(Request $request): RedirectResponse
    {
        $data = $request->validate(['query' => 'required|string|max:120']);
        $term = trim($data['query']);

        $sale = Sale::where('receipt_number', $term)
            ->orWhereHas('customer', fn ($w) => $w->where('phone', $term))
            ->whereNotIn('status', [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD])
            ->latest()
            ->first();

        if (!$sale) {
            return back()->withErrors(['query' => "No sale found for '{$term}'."]);
        }

        return redirect()->route('returns.create', ['sale' => $sale->id]);
    }

    public function create(Sale $sale): View
    {
        if (in_array($sale->status, [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD, Sale::STATUS_REFUNDED], true)) {
            abort(403, 'This sale cannot be returned (voided / on hold / fully refunded).');
        }
        $sale->load('items.product:id,name,sku,track_stock', 'customer');
        return view('returns.create', compact('sale'));
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        if (in_array($sale->status, [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD, Sale::STATUS_REFUNDED], true)) {
            return redirect()->route('returns.index')->withErrors(['sale' => 'Sale cannot be returned.']);
        }

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.sale_item_id' => 'required|integer|exists:sale_items,id',
            'items.*.qty_returned' => 'required|numeric|min:0',
            'items.*.restock' => 'nullable|boolean',
            'items.*.condition' => 'nullable|in:good,damaged,expired',
            'reason' => 'required|in:defective,wrong_item,customer_preference,other',
            'reason_note' => 'nullable|string|max:500',
            'refund_method' => 'required|in:cash_usd,cash_lbp,account,exchange',
            'notes' => 'nullable|string|max:1000',
        ]);

        $positiveItems = collect($data['items'])->filter(fn ($i) => (float) $i['qty_returned'] > 0);
        if ($positiveItems->isEmpty()) {
            return back()->withErrors(['items' => 'Select at least one item with a positive return quantity.']);
        }

        // Validate qty against remaining (sale_item.qty - returned_qty) and compute refund.
        $refundAmount = 0.0;
        $perLine = [];
        foreach ($positiveItems as $i) {
            $saleItem = SaleItem::where('id', $i['sale_item_id'])->where('sale_id', $sale->id)->first();
            if (!$saleItem) {
                return back()->withErrors(['items' => 'Invalid line item.']);
            }
            $remaining = (float) $saleItem->qty - (float) $saleItem->returned_qty;
            $qty = (float) $i['qty_returned'];
            if ($qty > $remaining + 0.0001) {
                return back()->withErrors([
                    'items' => "Quantity exceeds remaining for {$saleItem->product_name} (remaining: " . rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.') . ').',
                ]);
            }
            $perUnit = (float) $saleItem->qty > 0 ? (float) $saleItem->line_total_usd / (float) $saleItem->qty : 0;
            $lineRefund = round($perUnit * $qty, 4);
            $refundAmount += $lineRefund;
            // Default restock to true — matches the UI checkbox which is checked by default.
            // Caller must explicitly pass restock=0/false (e.g. damaged goods) to skip restocking.
            $restock = array_key_exists('restock', $i)
                ? filter_var($i['restock'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : true;
            $perLine[$saleItem->id] = [
                'sale_item' => $saleItem,
                'qty' => $qty,
                'refund' => $lineRefund,
                'restock' => $restock,
                'condition' => $i['condition'] ?? 'good',
            ];
        }

        // Credit refund needs a customer.
        if ($data['refund_method'] === 'account' && !$sale->customer_id) {
            return back()->withErrors(['refund_method' => 'Account credit requires a customer on the original sale.']);
        }

        $user = $request->user();
        $isAutoApprove = in_array($user->role, ['admin', 'manager'], true);

        $return = DB::transaction(function () use ($sale, $perLine, $data, $user, $isAutoApprove, $refundAmount) {
            $rt = ReturnTransaction::create([
                'return_number' => $this->nextReturnNumber(),
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'reason' => $data['reason'],
                'reason_note' => $data['reason_note'] ?? null,
                'refund_method' => $data['refund_method'],
                'refund_amount_usd' => round($refundAmount, 4),
                'refund_amount_lbp' => $data['refund_method'] === 'cash_lbp' ? $this->currency->usdToLbp($refundAmount) : 0,
                'status' => $isAutoApprove ? ReturnTransaction::STATUS_APPROVED : ReturnTransaction::STATUS_PENDING,
                'approved_by' => $isAutoApprove ? $user->id : null,
                'approved_at' => $isAutoApprove ? now() : null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($perLine as $row) {
                $rt->items()->create([
                    'sale_item_id' => $row['sale_item']->id,
                    'product_id' => $row['sale_item']->product_id,
                    'qty_returned' => $row['qty'],
                    'refund_amount_usd' => $row['refund'],
                    'restock' => $row['restock'],
                    'condition' => $row['condition'],
                ]);
            }

            AuditLog::record($user->id, 'return_create', ReturnTransaction::class, $rt->id, null, [
                'sale' => $sale->receipt_number,
                'amount' => $refundAmount,
                'auto_approved' => $isAutoApprove,
            ]);

            if ($isAutoApprove) {
                $this->processApproval($rt->fresh('items'));
            }

            return $rt;
        });

        $msg = $isAutoApprove
            ? "Return {$return->return_number} processed ($" . number_format((float) $return->refund_amount_usd, 2) . ' refunded).'
            : "Return {$return->return_number} created — pending manager approval.";

        return redirect()->route('returns.show', $return)->with('success', $msg);
    }

    public function show(ReturnTransaction $return): View
    {
        $return->load('sale.customer', 'items.product:id,name,sku', 'user:id,name', 'approver:id,name');
        return view('returns.show', ['return' => $return]);
    }

    public function approve(Request $request, ReturnTransaction $return): RedirectResponse
    {
        if (!in_array($request->user()->role, ['admin', 'manager'], true)) {
            abort(403);
        }
        if ($return->status !== ReturnTransaction::STATUS_PENDING) {
            return back()->withErrors(['status' => 'Return is not pending.']);
        }

        DB::transaction(function () use ($return, $request) {
            $return->update([
                'status' => ReturnTransaction::STATUS_APPROVED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);
            $this->processApproval($return->fresh('items'));
            AuditLog::record($request->user()->id, 'return_approve', ReturnTransaction::class, $return->id);
        });

        return back()->with('success', "Return {$return->return_number} approved.");
    }

    public function reject(Request $request, ReturnTransaction $return): RedirectResponse
    {
        if (!in_array($request->user()->role, ['admin', 'manager'], true)) {
            abort(403);
        }
        if ($return->status !== ReturnTransaction::STATUS_PENDING) {
            return back()->withErrors(['status' => 'Return is not pending.']);
        }

        $return->update([
            'status' => ReturnTransaction::STATUS_REJECTED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        AuditLog::record($request->user()->id, 'return_reject', ReturnTransaction::class, $return->id);
        return back()->with('success', 'Return rejected.');
    }

    /**
     * Apply the approved return: restock inventory, mark sale items, update sale status,
     * handle account-credit refund method.
     */
    private function processApproval(ReturnTransaction $return): void
    {
        foreach ($return->items as $ri) {
            if ($ri->restock && $ri->product) {
                $this->inventory->restockFromReturn($ri->product, (float) $ri->qty_returned, $return->user_id, $return->id);
            }
            SaleItem::where('id', $ri->sale_item_id)->increment('returned_qty', (float) $ri->qty_returned);
        }

        $sale = $return->sale->fresh('items');
        $allReturned = $sale->items->every(fn ($i) => (float) $i->returned_qty >= (float) $i->qty - 0.0001);
        $someReturned = $sale->items->contains(fn ($i) => (float) $i->returned_qty > 0);

        $sale->update([
            'status' => match (true) {
                $allReturned => Sale::STATUS_REFUNDED,
                $someReturned => Sale::STATUS_PARTIAL_REFUND,
                default => $sale->status,
            },
        ]);

        // Account credit: reduces what the customer owes us (balance can go negative = we owe them).
        if ($return->refund_method === 'account' && $sale->customer) {
            $sale->customer->decrement('balance', (float) $return->refund_amount_usd);
        }

        $return->update(['status' => ReturnTransaction::STATUS_COMPLETED]);
    }

    private function nextReturnNumber(): string
    {
        $prefix = Setting::get('return_prefix', 'RTN-' . date('Y') . '-');
        $counter = (int) Setting::get('return_counter', 0) + 1;
        Setting::set('return_counter', $counter, 'numbering', 'int');
        return $prefix . str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }
}
