<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $q = PurchaseOrder::with('supplier:id,name', 'user:id,name');
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($supplierId = $request->query('supplier')) {
            $q->where('supplier_id', $supplierId);
        }
        $pos = $q->latest()->paginate(25)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        return view('purchases.index', compact('pos', 'suppliers'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $products = Product::with('tax:id,rate')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'cost_usd', 'unit', 'tax_id', 'stock_qty']);

        return view('purchases.form', [
            'po' => new PurchaseOrder([
                'status' => 'draft',
                'supplier_id' => $request->query('supplier'),
                'expected_at' => null,
            ]),
            'items' => collect(),
            'suppliers' => $suppliers,
            'products' => $products,
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $po = DB::transaction(function () use ($data, $request) {
            $po = PurchaseOrder::create([
                'po_number' => $this->nextPoNumber(),
                'supplier_id' => $data['supplier_id'],
                'user_id' => $request->user()->id,
                'status' => $data['status'] ?? 'draft',
                'expected_at' => $data['expected_at'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'shipping_usd' => $data['shipping_usd'] ?? 0,
            ]);

            $this->upsertItemsAndRecomputeTotals($po, $data['items']);
            return $po;
        });

        AuditLog::record($request->user()->id, 'create', PurchaseOrder::class, $po->id, null, ['po_number' => $po->po_number]);
        return redirect()->route('purchases.show', $po)->with('success', "Created {$po->po_number}.");
    }

    public function show(PurchaseOrder $purchase): View
    {
        $purchase->load('supplier', 'user:id,name', 'items.product:id,name,sku,unit,stock_qty');
        return view('purchases.show', ['po' => $purchase]);
    }

    public function edit(PurchaseOrder $purchase): View
    {
        if (!in_array($purchase->status, ['draft', 'sent'], true)) {
            abort(403, 'This PO can no longer be edited.');
        }
        $purchase->load('items.product:id,name,sku,unit,cost_usd,tax_id');
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $products = Product::with('tax:id,rate')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'cost_usd', 'unit', 'tax_id', 'stock_qty']);
        return view('purchases.form', [
            'po' => $purchase,
            'items' => $purchase->items,
            'suppliers' => $suppliers,
            'products' => $products,
            'isEdit' => true,
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchase): RedirectResponse
    {
        if (!in_array($purchase->status, ['draft', 'sent'], true)) {
            return back()->withErrors(['status' => 'This PO can no longer be edited.']);
        }

        $data = $this->validateData($request);

        DB::transaction(function () use ($data, $purchase, $request) {
            $purchase->update([
                'supplier_id' => $data['supplier_id'],
                'status' => $data['status'] ?? $purchase->status,
                'expected_at' => $data['expected_at'] ?? null,
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'shipping_usd' => $data['shipping_usd'] ?? 0,
            ]);

            // Remove items no longer in payload
            $keepIds = array_filter(array_column($data['items'], 'id'));
            $purchase->items()->whereNotIn('id', $keepIds)->delete();

            $this->upsertItemsAndRecomputeTotals($purchase->fresh(), $data['items']);

            AuditLog::record($request->user()->id, 'update', PurchaseOrder::class, $purchase->id);
        });

        return redirect()->route('purchases.show', $purchase)->with('success', "Updated {$purchase->po_number}.");
    }

    public function transition(Request $request, PurchaseOrder $purchase): RedirectResponse
    {
        $data = $request->validate([
            'to' => 'required|in:sent,cancelled,closed',
        ]);

        $valid = match ($data['to']) {
            'sent' => $purchase->status === 'draft',
            'cancelled' => in_array($purchase->status, ['draft', 'sent'], true),
            'closed' => in_array($purchase->status, ['received', 'partial'], true),
            default => false,
        };

        if (!$valid) {
            return back()->withErrors(['status' => "Cannot transition from {$purchase->status} to {$data['to']}."]);
        }

        $purchase->update(['status' => $data['to']]);
        AuditLog::record($request->user()->id, 'po_status', PurchaseOrder::class, $purchase->id, null, ['to' => $data['to']]);
        return back()->with('success', "PO marked as {$data['to']}.");
    }

    /**
     * Receive goods for one or more lines. Updates stock via InventoryService.
     * Body: receipts => [ ['item_id' => int, 'qty' => float, 'batch' => ?, 'expiry' => ?, 'cost_usd' => ?] ]
     */
    public function receive(Request $request, PurchaseOrder $purchase): RedirectResponse
    {
        if (!in_array($purchase->status, ['sent', 'partial', 'draft'], true)) {
            return back()->withErrors(['status' => "Cannot receive against {$purchase->status} status."]);
        }

        $data = $request->validate([
            'receipts' => 'required|array|min:1',
            'receipts.*.item_id' => 'required|exists:purchase_order_items,id',
            'receipts.*.qty' => 'required|numeric|min:0',
            'receipts.*.batch' => 'nullable|string|max:80',
            'receipts.*.expiry' => 'nullable|date',
            'receipts.*.cost_usd' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($data, $purchase, $request) {
            foreach ($data['receipts'] as $r) {
                $qty = (float) $r['qty'];
                if ($qty <= 0) continue;

                $item = PurchaseOrderItem::where('purchase_order_id', $purchase->id)
                    ->where('id', $r['item_id'])
                    ->firstOrFail();

                $remaining = (float) $item->qty_ordered - (float) $item->qty_received;
                if ($qty > $remaining + 0.0001) {
                    throw new RuntimeException("Cannot receive more than ordered ({$remaining} remaining for {$item->product_name}).");
                }

                $item->update(['qty_received' => (float) $item->qty_received + $qty]);

                $this->inventory->receiveStock(
                    product: Product::findOrFail($item->product_id),
                    qty: $qty,
                    userId: $request->user()->id,
                    poId: $purchase->id,
                    batch: $r['batch'] ?? null,
                    expiry: $r['expiry'] ?? null,
                );

                // Optional cost override — updates product master cost
                if (!empty($r['cost_usd']) && (float) $r['cost_usd'] !== (float) $item->cost_usd) {
                    Product::where('id', $item->product_id)->update(['cost_usd' => (float) $r['cost_usd']]);
                }
            }

            // Recompute status
            $purchase->load('items');
            $totalOrdered = $purchase->items->sum('qty_ordered');
            $totalReceived = $purchase->items->sum('qty_received');
            $newStatus = match (true) {
                $totalReceived <= 0 => 'sent',
                $totalReceived < $totalOrdered => 'partial',
                default => 'received',
            };
            $purchase->update([
                'status' => $newStatus,
                'received_at' => $newStatus === 'received' ? now() : $purchase->received_at,
            ]);

            // Add to supplier balance (we now owe them for received goods)
            $receivedValue = collect($data['receipts'])->reduce(function ($carry, $r) use ($purchase) {
                $item = $purchase->items->firstWhere('id', (int) $r['item_id']);
                if (!$item) return $carry;
                $cost = (float) ($r['cost_usd'] ?? $item->cost_usd);
                return $carry + $cost * (float) $r['qty'];
            }, 0.0);

            if ($receivedValue > 0) {
                $purchase->supplier->increment('balance', round($receivedValue, 2));
            }

            AuditLog::record($request->user()->id, 'po_receive', PurchaseOrder::class, $purchase->id, null, ['receipts' => $data['receipts']]);
        });

        return redirect()->route('purchases.show', $purchase)->with('success', 'Goods received.');
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'status' => 'nullable|in:draft,sent',
            'expected_at' => 'nullable|date',
            'supplier_reference' => 'nullable|string|max:120',
            'shipping_usd' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',

            'items' => 'required|array|min:1',
            'items.*.id' => 'nullable|integer',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty_ordered' => 'required|numeric|min:0.0001',
            'items.*.cost_usd' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ]);
        return $data;
    }

    private function upsertItemsAndRecomputeTotals(PurchaseOrder $po, array $items): void
    {
        $subtotal = 0; $tax = 0;
        foreach ($items as $row) {
            $product = Product::findOrFail($row['product_id']);
            $cost = (float) $row['cost_usd'];
            $qty = (float) $row['qty_ordered'];
            $rate = (float) ($row['tax_rate'] ?? ($product->tax->rate ?? 0));
            $line = $cost * $qty;
            $lineTax = $line * $rate;
            $subtotal += $line;
            $tax += $lineTax;

            $payload = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'qty_ordered' => $qty,
                'cost_usd' => $cost,
                'tax_rate' => $rate,
                'line_total_usd' => round($line + $lineTax, 4),
            ];

            if (!empty($row['id'])) {
                PurchaseOrderItem::where('id', $row['id'])->where('purchase_order_id', $po->id)->update($payload);
            } else {
                $po->items()->create($payload);
            }
        }

        $po->update([
            'subtotal_usd' => round($subtotal, 4),
            'tax_amount_usd' => round($tax, 4),
            'total_usd' => round($subtotal + $tax + (float) $po->shipping_usd, 4),
        ]);
    }

    private function nextPoNumber(): string
    {
        $prefix = Setting::get('po_prefix', 'PO-' . date('Y') . '-');
        $counter = (int) Setting::get('po_counter', 0) + 1;
        Setting::set('po_counter', $counter, 'numbering', 'int');
        return $prefix . str_pad((string) $counter, 5, '0', STR_PAD_LEFT);
    }
}
