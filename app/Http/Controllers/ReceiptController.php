<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\ReceiptPrintService;
use App\Services\ReceiptRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptPrintService $prints) {}

    /**
     * Render one sale as a print-ready page: the thermal receipt, or the A4 invoice
     * when `?format=a4` (or, with no format given, when the receipt_format setting
     * says so). Cashiers may only print receipts for sales they processed;
     * managers/admins can reprint anyone's.
     *
     * `?copy=1` marks the print as a reprint: the page carries the COPY notice and the
     * reprint is audited. The sell screen's first print after a sale never sends it.
     */
    public function show(Request $request, Sale $sale): View|Response
    {
        $data = $this->data($request, $sale);
        $format = $this->prints->resolveFormat($request->query('format'));
        $this->auditReprint($request, $sale, $format);

        if ($format === ReceiptPrintService::FORMAT_A4) {
            return view('receipts.a4', $data + ['inv' => $this->prints->invoice($sale), 'embedded' => false]);
        }

        // A saved, active receipt template wins; every install that has never
        // had one — which is all of them until Premium's editor is used — keeps
        // printing the built-in thermal layout exactly as before.
        if ($template = ReceiptRenderer::active()) {
            return response(ReceiptRenderer::render($template, $sale, $data));
        }

        return view('receipts.thermal', $data);
    }

    /**
     * Send the thermal receipt straight to the receipt printer chosen in Settings (the Windows
     * default printer when none is). Only the packaged app can: the answer is
     * `{printed, desktop}`. Outside it `desktop` is false and the page opens the receipt in a
     * popup instead; inside it a failed print is a 502 and the page shows Retry — it never falls
     * back to a dialog.
     */
    public function printThermal(Request $request, Sale $sale): JsonResponse
    {
        $data = $this->data($request, $sale);

        if (! $this->prints->inNativeShell()) {
            return $this->printResult(false, false);
        }

        $data = ['autoPrint' => false, 'embedded' => true] + $data;
        $template = ReceiptRenderer::active();
        $html = $template
            ? ReceiptRenderer::render($template, $sale, $data)
            : view('receipts.thermal', $data)->render();

        return $this->printed($request, $sale, ReceiptPrintService::FORMAT_THERMAL, $this->prints->printThermalSilently($html));
    }

    /** The A4 invoice, same contract as printThermal() with the A4 printer. */
    public function printA4(Request $request, Sale $sale): JsonResponse
    {
        $data = $this->data($request, $sale);

        if (! $this->prints->inNativeShell()) {
            return $this->printResult(false, false);
        }

        return $this->printed($request, $sale, ReceiptPrintService::FORMAT_A4, $this->prints->printA4Silently(
            view('receipts.a4', $data + ['inv' => $this->prints->invoice($sale), 'embedded' => true, 'autoPrint' => false])->render()
        ));
    }

    /**
     * Only a print that actually went out is a reprint; a failed one is retried by the page and
     * logged when it finally prints.
     */
    private function printed(Request $request, Sale $sale, string $format, bool $printed): JsonResponse
    {
        if ($printed) {
            $this->auditReprint($request, $sale, $format);
        }

        return $this->printResult($printed, true);
    }

    private function printResult(bool $printed, bool $desktop): JsonResponse
    {
        return response()->json(['printed' => $printed, 'desktop' => $desktop], $desktop && ! $printed ? 502 : 200);
    }

    /** A copy is a reprint, and every reprint is logged: who, which receipt, which format, from which screen. */
    private function auditReprint(Request $request, Sale $sale, string $format): void
    {
        if (! $request->boolean('copy')) {
            return;
        }

        $source = $request->input('src');

        AuditLog::record($request->user()->id, 'receipt_reprint', Sale::class, $sale->id, null, [
            'receipt' => $sale->receipt_number,
            'format' => $format,
            'source' => in_array($source, ['history', 'detail'], true) ? $source : 'direct',
        ]);
    }

    /** The checks and data both formats share: one ownership rule, one set of queries. */
    private function data(Request $request, Sale $sale): array
    {
        $user = $request->user();
        abort_unless($sale->canBeSeenBy($user), 403);

        $sale->load('items.product:id,name,name_ar,sku,barcode,unit', 'customer', 'user:id,name');

        return [
            'sale' => $sale,
            'business' => [
                'name' => Setting::get('business_name', 'LebaSouk'),
                'name_ar' => Setting::get('business_name_ar'),
                'address' => Setting::get('address'),
                'phone' => Setting::get('phone'),
                'tax_number' => Setting::get('tax_number'),
            ],
            'width' => (int) Setting::get('receipt_width', 80),
            'header' => (string) Setting::get('receipt_header', ''),
            'footer' => (string) Setting::get('receipt_footer', ''),
            'receiptLang' => (string) Setting::get('receipt_language', 'bilingual'),
            'autoPrint' => $request->boolean('auto', true),
            'isCopy' => $request->boolean('copy'),
        ];
    }
}
