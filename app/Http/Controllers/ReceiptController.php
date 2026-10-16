<?php

namespace App\Http\Controllers;

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
     */
    public function show(Request $request, Sale $sale): View|Response
    {
        $data = $this->data($request, $sale);

        if ($this->prints->resolveFormat($request->query('format')) === ReceiptPrintService::FORMAT_A4) {
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
     * Send the A4 invoice straight to the printer chosen in Settings. Only meaningful
     * in the packaged app; `printed: false` tells the sell screen to open the A4 page
     * with the system print dialog instead.
     */
    public function printA4(Request $request, Sale $sale): JsonResponse
    {
        $data = $this->data($request, $sale);

        $printed = $this->prints->silentA4Available() && $this->prints->printA4Silently(
            view('receipts.a4', $data + ['inv' => $this->prints->invoice($sale), 'embedded' => true, 'autoPrint' => false])->render()
        );

        return response()->json(['printed' => $printed]);
    }

    /** The checks and data both formats share: one ownership rule, one set of queries. */
    private function data(Request $request, Sale $sale): array
    {
        $user = $request->user();
        abort_unless($sale->user_id === $user->id || $user->isManagerial(), 403);

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
        ];
    }
}
