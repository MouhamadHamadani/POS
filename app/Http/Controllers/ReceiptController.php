<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    /**
     * Render the receipt for a single sale in a print-ready (thermal-style) HTML page.
     * Cashiers may only print receipts for sales they processed; managers/admins
     * can reprint anyone's.
     */
    public function show(Request $request, Sale $sale): View|Response
    {
        $user = $request->user();
        $isOwner = $sale->user_id === $user->id;
        $isManagerial = in_array($user->role, ['admin', 'manager'], true);
        abort_unless($isOwner || $isManagerial, 403);

        $sale->load('items', 'customer', 'user:id,name');

        return view('receipts.thermal', [
            'sale' => $sale,
            'business' => [
                'name' => Setting::get('business_name', 'POS Pro'),
                'name_ar' => Setting::get('business_name_ar'),
                'address' => Setting::get('address'),
                'phone' => Setting::get('phone'),
                'tax_number' => Setting::get('tax_number'),
            ],
            'width' => (int) Setting::get('receipt_width', 80),
            'header' => (string) Setting::get('receipt_header', ''),
            'footer' => (string) Setting::get('receipt_footer', 'Thank you for your business!'),
            'autoPrint' => $request->boolean('auto', true),
        ]);
    }
}
