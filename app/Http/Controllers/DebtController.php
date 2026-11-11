<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DemoGate;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\CustomerLedgerService;
use App\Services\ReceiptPrintService;
use App\Support\Demo;
use App\Support\PdfText;
use App\Support\Spreadsheet;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The debt book's screens: who owes what and since when, taking a repayment, a customer's statement, the
 * payment slip. Open to the cashier too (taking money at the counter is a till job); voiding, writing off and
 * moving a due date are manager-only, and the customer-admin routes (edit / delete) are untouched.
 *
 * No `feature:` gate on any of it: switching `customer_credit` off hides the way INTO the book (till option,
 * sidebar, tile) but money already owed can always be collected.
 */
class DebtController extends Controller
{
    public function __construct(
        private readonly CustomerLedgerService $ledger,
        private readonly ReceiptPrintService $prints,
    ) {}

    // ------------------------------------------------------------------ the list

    public function index(Request $request): View|Response
    {
        return $this->list($request, 'debts.index');
    }

    /** The Receivables report (Reports > Financial, managers): the same data and filters as /debts. */
    public function aging(Request $request): View|Response
    {
        return $this->list($request, 'reports.receivables');
    }

    private function list(Request $request, string $view): View|Response
    {
        $f = [
            'q' => trim((string) $request->query('q', '')),
            'overdue' => $request->boolean('overdue'),
            'sort' => in_array($request->query('sort'), ['outstanding', 'overdue', 'name'], true) ? $request->query('sort') : 'outstanding',
        ];
        $data = $this->ledger->receivables($f['q'], $f['overdue'], $f['sort']) + ['filters' => $f];
        $title = __('reports.titles.receivables');

        $format = $request->query('format');
        if ($format !== null && Demo::enabled() && in_array($format, ['pdf', 'xlsx'], true)) {
            return back()->withErrors(['demo' => DemoGate::refusal().' '.__('messages.reports_viewable')]);
        }
        $filename = Str::slug(__('reports.titles.receivables', [], 'en')).'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            PdfText::ensureFontDirs();

            return Pdf::loadView('reports.receivables-pdf', $data + ['title' => $title])->download($filename.'.pdf');
        }
        if ($format === 'xlsx') {
            return Spreadsheet::downloadRows($this->listRows($data), $filename);
        }

        return view($view, $data + ['title' => $title]);
    }

    /** @return list<list<mixed>> heading row first */
    private function listRows(array $data): array
    {
        $rows = [array_map(fn ($k) => __("debts.cols.$k"), ['customer', 'phone', 'outstanding', 'overdue', 'oldest_due', 'last_payment', 'not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90'])];
        foreach ($data['customers'] as $r) {
            $rows[] = [
                $r['customer']->name, $r['customer']->phone, $r['outstanding'], $r['overdue'],
                $r['oldest_due']?->toDateString(), $r['last_payment']?->toDateString(),
                ...array_values($r['aging']),
            ];
        }
        $t = $data['totals'];
        $rows[] = [__('reports.cols.totals'), '', $t['outstanding'], $t['overdue'], '', '', ...array_map(fn ($b) => $t[$b], CustomerLedgerService::BUCKETS)];

        return $rows;
    }

    // ------------------------------------------------------------------ one customer

    public function show(Request $request, Customer $customer): View
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $book = $this->ledger->book(
            $customer,
            $request->query('from') ? Carbon::parse($request->query('from')) : null,
            $request->query('to') ? Carbon::parse($request->query('to')) : null,
        );

        return view('debts.show', $book + [
            'customer' => $customer,
            'tab' => in_array($request->query('tab'), ['debts', 'payments', 'statement'], true) ? $request->query('tab') : 'debts',
            'canManage' => $request->user()->isManagerial(),
            'slipPayment' => session('slip_payment') ? CustomerPayment::find(session('slip_payment')) : null,
        ]);
    }

    public function pay(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => ['required', Rule::in(['USD', 'LBP'])],
            'method' => ['required', Rule::in(CustomerPayment::METHODS)],
            'paid_at' => 'nullable|date',
            'reference' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:500',
            'apply' => 'nullable|in:oldest,choose',
            'allocations' => 'nullable|array',
            'allocations.*.sale_id' => 'nullable|integer',
            'allocations.*.amount_usd' => 'nullable|numeric|min:0',
        ]);
        if (($data['apply'] ?? 'oldest') !== 'choose') {
            unset($data['allocations']);
        }

        // A ValidationException from the service goes back to the form with the cashier's input.
        $payment = $this->ledger->recordPayment($customer, $data, $request->user());

        return redirect()->route('debts.show', ['customer' => $customer, 'tab' => 'payments'])
            ->with('success', __('messages.debt_payment_recorded', [
                'number' => $payment->number,
                'customer' => $customer->name,
                'amount' => '$'.number_format((float) $customer->fresh()->balance, 2),
            ]))
            ->with('slip_payment', $payment->id);
    }

    public function void(Request $request, CustomerPayment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        $this->ledger->voidPayment($payment, $request->user(), $data['reason']);

        return redirect()->route('debts.show', ['customer' => $payment->customer_id, 'tab' => 'payments'])
            ->with('success', __('messages.debt_payment_voided', ['number' => $payment->number]));
    }

    public function writeOff(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'amount_usd' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:300',
            'apply' => 'nullable|in:oldest,choose',
            'allocations' => 'nullable|array',
            'allocations.*.sale_id' => 'nullable|integer',
            'allocations.*.amount_usd' => 'nullable|numeric|min:0',
        ]);
        if (($data['apply'] ?? 'oldest') !== 'choose') {
            unset($data['allocations']);
        }

        $w = $this->ledger->writeOff($customer, $data, $request->user());

        return redirect()->route('debts.show', ['customer' => $customer, 'tab' => 'payments'])
            ->with('success', __('messages.debt_written_off', [
                'amount' => '$'.number_format((float) $w->amount_usd, 2),
                'customer' => $customer->name,
                'balance' => '$'.number_format((float) $customer->fresh()->balance, 2),
            ]));
    }

    public function dueDate(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate(['due_date' => 'required|date']);
        $this->ledger->changeDueDate($sale, Carbon::parse($data['due_date']), $request->user());

        return back()->with('success', __('messages.debt_due_date_changed'));
    }

    // ------------------------------------------------------------------ statement

    /**
     * A customer's statement: A4 page that follows the receipt language setting (print it from the browser or
     * the desktop dialog), `?format=pdf` for a file, `?format=xlsx` for a spreadsheet (CSV where the runtime
     * cannot write .xlsx).
     */
    public function statement(Request $request, Customer $customer): View|Response
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : Carbon::today();
        $from = $request->query('from') ? Carbon::parse($request->query('from')) : $to->copy()->subDays(90);
        $statement = $this->ledger->statement($customer, $from, $to);
        $format = $request->query('format');
        $filename = 'statement-'.Str::slug((string) preg_replace('/[^\x20-\x7E]/', '', $customer->name) ?: 'customer-'.$customer->id).'-'.now()->format('Ymd');

        if ($format !== null && Demo::enabled()) {
            return back()->withErrors(['demo' => DemoGate::refusal().' '.__('messages.reports_viewable')]);
        }

        if ($format === 'xlsx') {
            $rows = [array_map(fn ($k) => __("debts.cols.$k"), ['date', 'entry', 'receipt', 'debit', 'credit', 'balance'])];
            $rows[] = [$from->toDateString(), __('debts.opening_balance'), '', '', '', $statement['opening']];
            foreach ($statement['rows'] as $r) {
                $rows[] = [$r['date']->toDateString(), $r['type_label'].($r['payment'] ? ' '.$r['payment'] : ''), $r['receipt'] ?? '', $r['debit'] ?: '', $r['credit'] ?: '', $r['balance']];
            }
            $rows[] = [$to->toDateString(), __('debts.closing_balance'), '', $statement['debits'], $statement['credits'], $statement['closing']];

            return Spreadsheet::downloadRows($rows, $filename);
        }

        $payload = $this->printData() + ['customer' => $customer, 'statement' => $statement, 'from' => $from, 'to' => $to];

        if ($format === 'pdf') {
            PdfText::ensureFontDirs();

            return Pdf::loadView('debts.statement-pdf', $payload + ['title' => __('debts.statement_title')])->download($filename.'.pdf');
        }

        return view('debts.statement', $payload + ['autoPrint' => $request->boolean('auto')]);
    }

    // ------------------------------------------------------------------ payment slip

    /** The slip for one repayment: thermal by default, `?format=a4` for the small A4 slip. */
    public function slip(Request $request, CustomerPayment $payment): View
    {
        return view($this->slipView($request->query('format')), $this->slipData($payment) + ['autoPrint' => $request->boolean('auto', true), 'embedded' => false]);
    }

    /** The slip straight to the receipt printer in the desktop app; the same contract as printing a receipt. */
    public function printSlip(Request $request, CustomerPayment $payment): JsonResponse
    {
        if (! $this->prints->inNativeShell()) {
            return response()->json(['printed' => false, 'desktop' => false]);
        }

        $format = $request->query('format') === 'a4' ? ReceiptPrintService::FORMAT_A4 : ReceiptPrintService::FORMAT_THERMAL;
        $html = view($this->slipView($format), $this->slipData($payment) + ['autoPrint' => false, 'embedded' => true])->render();
        $printed = $format === ReceiptPrintService::FORMAT_A4 ? $this->prints->printA4Silently($html) : $this->prints->printThermalSilently($html);

        return response()->json(['printed' => $printed, 'desktop' => true], ! $printed ? 502 : 200);
    }

    private function slipView(?string $format): string
    {
        return $format === 'a4' ? 'debts.slip-a4' : 'debts.slip';
    }

    /** @return array<string, mixed> */
    private function slipData(CustomerPayment $payment): array
    {
        $payment->load('customer', 'user:id,name', 'entries.sale:id,receipt_number');

        return $this->printData() + [
            'payment' => $payment,
            'customer' => $payment->customer,
            'balance' => $this->ledger->balanceOf($payment->customer_id),
            'applied' => $payment->entries->whereIn('type', ['payment', 'write_off'])->map(fn ($e) => [
                'receipt' => $e->sale?->receipt_number,
                'amount' => abs((float) $e->amount_usd),
            ])->values(),
            'width' => (int) Setting::get('receipt_width', 80),
        ];
    }

    /** The business header and the print language every printed page here shares. @return array<string, mixed> */
    private function printData(): array
    {
        return [
            'business' => [
                'name' => Setting::get('business_name', 'LebaSouk'),
                'name_ar' => Setting::get('business_name_ar'),
                'address' => Setting::get('address'),
                'phone' => Setting::get('phone'),
                'tax_number' => Setting::get('tax_number'),
            ],
            'receiptLang' => (string) Setting::get('receipt_language', 'bilingual'),
            'fontScale' => $this->prints->fontScale(),
            'width' => (int) Setting::get('receipt_width', 80),
        ];
    }
}
