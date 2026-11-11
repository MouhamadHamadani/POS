<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\LoyaltyTransaction;
use App\Models\Sale;
use App\Services\CustomerLedgerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = Customer::query();
        if ($s = $request->query('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('company_name', 'like', "%{$s}%");
            });
        }
        if ($g = $request->query('group')) {
            $q->where('customer_group', $g);
        }
        if ($request->query('with_balance')) {
            $q->where('balance', '>', 0);
        }

        $customers = $q->orderBy('name')->paginate(25)->withQueryString();
        return view('customers.index', compact('customers'));
    }

    public function create(): View
    {
        return view('customers.form', [
            'customer' => new Customer(['customer_group' => 'retail', 'is_active' => true, 'credit_limit' => 0]),
            'isEdit' => false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $customer = Customer::create($data);
        AuditLog::record($request->user()->id, 'create', Customer::class, $customer->id, null, ['name' => $customer->name]);
        return redirect()->route('customers.show', $customer)->with('success', __('messages.customer_created', ['name' => $customer->name]));
    }

    public function show(Request $request, Customer $customer, CustomerLedgerService $ledger): View
    {
        $sales = Sale::where('customer_id', $customer->id)->latest()->limit(20)->get();
        $loyalty = LoyaltyTransaction::where('customer_id', $customer->id)->latest()->limit(20)->get();
        $totalSpent = (float) Sale::where('customer_id', $customer->id)
            ->where('status', Sale::STATUS_COMPLETED)->sum('total_usd');
        $salesCount = Sale::where('customer_id', $customer->id)->where('status', Sale::STATUS_COMPLETED)->count();

        // The debt book: open receipts, repayments, statement. Repayments are taken on the /debts screens
        // (the same partial, so the two pages cannot drift apart).
        $book = $ledger->book(
            $customer,
            $request->query('from') ? Carbon::parse($request->query('from')) : null,
            $request->query('to') ? Carbon::parse($request->query('to')) : null,
        ) + [
            'tab' => in_array($request->query('tab'), ['debts', 'payments', 'statement'], true) ? $request->query('tab') : 'debts',
            'canManage' => $request->user()->isManagerial(),
            'slipPayment' => session('slip_payment') ? CustomerPayment::find(session('slip_payment')) : null,
        ];

        return view('customers.show', compact('customer', 'sales', 'loyalty', 'totalSpent', 'salesCount') + $book);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer, 'isEdit' => true]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validateData($request, $customer);
        $old = $customer->only(array_keys($data));
        $customer->update($data);
        AuditLog::record($request->user()->id, 'update', Customer::class, $customer->id, $old, $data);
        return redirect()->route('customers.show', $customer)->with('success', __('messages.customer_updated', ['name' => $customer->name]));
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $name = $customer->name;
        $customer->delete(); // soft delete
        AuditLog::record($request->user()->id, 'delete', Customer::class, $customer->id, ['name' => $name], null);
        return redirect()->route('customers.index')->with('success', __('messages.customer_deleted', ['name' => $name]));
    }

    /**
     * AJAX endpoint used by the POS typeahead.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json([]);

        return response()->json(
            Customer::where('is_active', true)
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
                })
                ->limit(10)
                ->get(Customer::POS_COLUMNS)
                ->map(fn (Customer $c) => $c->posSummary())
        );
    }

    /**
     * AJAX endpoint used by the "+ Customer" modal in POS.
     */
    public function quickAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^(\+961|0)?[0-9 \-]{6,20}$/'],
            'customer_group' => 'nullable|in:retail,wholesale,vip',
        ]);
        $data['customer_group'] ??= 'retail';
        $data['is_active'] = true;

        $customer = Customer::create($data);
        AuditLog::record($request->user()->id, 'create', Customer::class, $customer->id, null, ['name' => $customer->name, 'via' => 'pos_quick_add']);

        // refresh(): balance / loyalty_points / credit_limit / tax_exempt come
        // from column defaults, so the in-memory model has them as null until
        // re-read. The POS chip expects the same shape the typeahead returns.
        return response()->json($customer->refresh()->posSummary(), 201);
    }

    private function validateData(Request $request, ?Customer $existing = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^(\+961|0)?[0-9 \-]{6,20}$/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'company_name' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:80',
            'customer_group' => 'required|in:retail,wholesale,vip',
            'credit_limit' => 'nullable|numeric|min:0',
            // Days of credit this customer gets; blank = the store default (Settings > POS).
            'payment_terms_days' => 'nullable|integer|min:0|max:3650',
            'loyalty_points' => 'nullable|integer|min:0',
            'tax_exempt' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'birth_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);
        // credit_limit is NOT NULL DEFAULT 0: a blank input arrives as null, and blank means "no credit" (0).
        // A request that omits the field entirely still leaves the stored limit alone.
        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit'] ??= 0;
        }
        $data['tax_exempt'] = $request->boolean('tax_exempt');
        $data['is_active'] = $request->boolean('is_active', true);
        return $data;
    }
}
