<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $q = Supplier::query();
        if ($s = $request->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('contact_person', 'like', "%{$s}%");
            });
        }
        if ($request->query('with_balance')) {
            $q->where('balance', '>', 0);
        }
        $suppliers = $q->orderBy('name')->paginate(25)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        return view('suppliers.form', [
            'supplier' => new Supplier(['is_active' => true, 'payment_terms' => 'NET30', 'balance' => 0]),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $supplier = Supplier::create($data);
        AuditLog::record($request->user()->id, 'create', Supplier::class, $supplier->id);
        return redirect()->route('suppliers.show', $supplier)->with('success', "Created '{$supplier->name}'.");
    }

    public function show(Supplier $supplier): View
    {
        $pos = PurchaseOrder::where('supplier_id', $supplier->id)->latest()->limit(20)->get();
        $totalSpent = (float) PurchaseOrder::where('supplier_id', $supplier->id)
            ->whereIn('status', ['received', 'closed', 'partial'])->sum('total_usd');
        return view('suppliers.show', compact('supplier', 'pos', 'totalSpent'));
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.form', ['supplier' => $supplier, 'isEdit' => true]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validateData($request);
        $old = $supplier->only(array_keys($data));
        $supplier->update($data);
        AuditLog::record($request->user()->id, 'update', Supplier::class, $supplier->id, $old, $data);
        return redirect()->route('suppliers.show', $supplier)->with('success', "Updated '{$supplier->name}'.");
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $name = $supplier->name;
        $supplier->delete();
        AuditLog::record($request->user()->id, 'delete', Supplier::class, $supplier->id, ['name' => $name], null);
        return redirect()->route('suppliers.index')->with('success', "Deleted '{$name}'.");
    }

    /**
     * Record a payment we make to a supplier (decrements their balance — what we owe them).
     */
    public function recordPayment(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'amount_usd' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash_usd,cash_lbp,card,bank_transfer,cheque,other',
            'reference' => 'nullable|string|max:80',
            'date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        if ((float) $data['amount_usd'] > (float) $supplier->balance) {
            return back()->withErrors(['amount_usd' => 'Payment exceeds outstanding balance ($' . number_format($supplier->balance, 2) . ').']);
        }

        DB::transaction(function () use ($data, $supplier, $request) {
            $supplier->decrement('balance', (float) $data['amount_usd']);
            AuditLog::record($request->user()->id, 'supplier_payment', Supplier::class, $supplier->id, null, $data);
        });

        return back()->with('success', 'Payment recorded. New balance: $' . number_format($supplier->fresh()->balance, 2));
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'contact_person' => 'nullable|string|max:120',
            'website' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:80',
            'payment_terms' => 'required|in:NET15,NET30,NET60,COD,prepaid',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        return $data;
    }
}
