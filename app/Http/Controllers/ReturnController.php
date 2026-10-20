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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReturnController extends Controller
{
    /** A sale in one of these states cannot take a return: not found by "Find sale", refused by create()/store(). */
    public const NOT_RETURNABLE = [Sale::STATUS_VOIDED, Sale::STATUS_ON_HOLD, Sale::STATUS_REFUNDED];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CurrencyService $currency,
    ) {}

    public function index(Request $request): View
    {
        $q = ReturnTransaction::with('sale:id,receipt_number,user_id', 'user:id,name', 'approver:id,name');
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

        // The status filter must bind to BOTH ways of finding the sale, hence the closure around the OR.
        $sale = Sale::whereNotIn('status', self::NOT_RETURNABLE)
            ->where(fn ($q) => $q->where('receipt_number', $term)
                ->orWhereHas('customer', fn ($w) => $w->where('phone', $term)))
            ->latest()
            ->first();

        if (!$sale) {
            return back()->withErrors(['query' => __('messages.no_sale_found', ['term' => $term])]);
        }

        return redirect()->route('returns.create', ['sale' => $sale->id]);
    }

    public function create(Sale $sale): View
    {
        if (in_array($sale->status, self::NOT_RETURNABLE, true)) {
            abort(403, __('messages.sale_cannot_return_abort'));
        }
        $sale->load('items.product:id,name,name_ar,sku,track_stock', 'customer');
        return view('returns.create', compact('sale'));
    }

    public function store(Request $request, Sale $sale): RedirectResponse
    {
        if (in_array($sale->status, self::NOT_RETURNABLE, true)) {
            return redirect()->route('returns.index')->withErrors(['sale' => __('messages.sale_cannot_return')]);
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

        // One entry per sale line: a line posted twice has its quantities summed (the last entry's
        // restock/condition win), so the check below sees the real total and the refund is counted once.
        // The key is the integer id the row is looked up by: the `integer` rule also lets "+5" through,
        // which is the same line as "5" to the database but a different string to groupBy().
        $positiveItems = collect($data['items'])
            ->filter(fn ($i) => (float) $i['qty_returned'] > 0)
            ->groupBy(fn ($i) => (int) $i['sale_item_id'])
            ->map(fn ($rows, $id) => array_merge($rows->last(), ['sale_item_id' => $id, 'qty_returned' => $rows->sum(fn ($r) => (float) $r['qty_returned'])]));
        if ($positiveItems->isEmpty()) {
            return back()->withErrors(['items' => __('messages.select_return_item')]);
        }

        // Validate qty against remaining (sale_item.qty - returned_qty) and compute refund.
        $perLine = [];
        foreach ($positiveItems as $i) {
            $saleItem = SaleItem::where('id', $i['sale_item_id'])->where('sale_id', $sale->id)->first();
            if (!$saleItem) {
                return back()->withErrors(['items' => __('messages.invalid_line')]);
            }
            // Units already on another pending return are spoken for: returned_qty only moves on approval.
            $remaining = $saleItem->returnableQty();
            $qty = (float) $i['qty_returned'];
            if ($qty > $remaining + 0.0001) {
                return back()->withErrors([
                    'items' => __('messages.qty_exceeds', ['product' => $saleItem->product_name, 'remaining' => rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.')]),
                ]);
            }
            $perUnit = (float) $saleItem->qty > 0 ? (float) $saleItem->line_total_usd / (float) $saleItem->qty : 0;
            $lineRefund = round($perUnit * $qty, 4);
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

        // The header is the sum of the item rows, never a running total kept next to them: the shift's cash
        // and the account credit both read the header, so it must not be able to differ from what is itemised.
        $refundAmount = round(array_sum(array_column($perLine, 'refund')), 4);

        // Credit refund needs a customer.
        if ($data['refund_method'] === 'account' && !$sale->customer_id) {
            return back()->withErrors(['refund_method' => __('returns.account_needs_customer')]);
        }

        $user = $request->user();
        $isAutoApprove = $user->isManagerial();

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
            ? __('messages.return_processed', ['number' => $return->return_number, 'amount' => '$' . number_format((float) $return->refund_amount_usd, 2)])
            : __('messages.return_pending', ['number' => $return->return_number]);

        return redirect()->route('returns.show', $return)->with('success', $msg);
    }

    public function show(ReturnTransaction $return): View
    {
        $return->load('sale.customer', 'items.product:id,name,name_ar,sku', 'user:id,name', 'approver:id,name');
        return view('returns.show', ['return' => $return]);
    }

    public function approve(Request $request, ReturnTransaction $return): RedirectResponse
    {
        if (!$request->user()->isManagerial()) {
            abort(403);
        }

        // Claim the pending return with one conditional UPDATE inside the transaction: of two overlapping
        // approvals (double click, two managers) only the first changes a row, the other applies nothing.
        $applied = DB::transaction(function () use ($return, $request) {
            if (!$this->claimPending($return, ReturnTransaction::STATUS_APPROVED, $request->user()->id)) {
                return false;
            }
            $this->processApproval($return->fresh('items'));
            AuditLog::record($request->user()->id, 'return_approve', ReturnTransaction::class, $return->id);

            return true;
        });
        if (!$applied) {
            return back()->withErrors(['status' => __('messages.return_not_pending')]);
        }

        return back()->with('success', __('messages.return_approved', ['number' => $return->return_number]));
    }

    public function reject(Request $request, ReturnTransaction $return): RedirectResponse
    {
        if (!$request->user()->isManagerial()) {
            abort(403);
        }
        if (!$this->claimPending($return, ReturnTransaction::STATUS_REJECTED, $request->user()->id)) {
            return back()->withErrors(['status' => __('messages.return_not_pending')]);
        }
        AuditLog::record($request->user()->id, 'return_reject', ReturnTransaction::class, $return->id);
        return back()->with('success', __('messages.return_rejected'));
    }

    /** Move a return out of `pending` only if it is still pending in the database (not just in memory). */
    private function claimPending(ReturnTransaction $return, string $status, int $userId): bool
    {
        return ReturnTransaction::where('id', $return->id)
            ->where('status', ReturnTransaction::STATUS_PENDING)
            ->update(['status' => $status, 'approved_by' => $userId, 'approved_at' => now()]) === 1;
    }

    /**
     * Apply the approved return: restock inventory, mark sale items, update sale status,
     * handle account-credit refund method.
     */
    private function processApproval(ReturnTransaction $return): void
    {
        foreach ($return->items as $ri) {
            $qty = (float) $ri->qty_returned;

            // Claim the units with one statement that both checks and moves returned_qty, so two
            // returns on the same line can never take it past what was sold. A throw rolls back
            // the whole approval (callers run this inside their transaction) and the return stays pending.
            // (PDO binds the float as text, and SQLite sorts any text above any number, hence the CAST.)
            $claimed = SaleItem::where('id', $ri->sale_item_id)
                ->whereRaw('qty - returned_qty >= CAST(? AS REAL)', [$qty - 0.0001])
                ->increment('returned_qty', $qty);
            if (!$claimed) {
                $line = SaleItem::find($ri->sale_item_id);
                $left = max(0.0, (float) $line?->qty - (float) $line?->returned_qty);
                throw ValidationException::withMessages([
                    'items' => __('messages.qty_exceeds', ['product' => $line?->product_name, 'remaining' => rtrim(rtrim(number_format($left, 4, '.', ''), '0'), '.')]),
                ]);
            }

            if ($ri->restock && $ri->product) {
                $this->inventory->restockFromReturn($ri->product, $qty, $return->user_id, $return->id);
            }
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
