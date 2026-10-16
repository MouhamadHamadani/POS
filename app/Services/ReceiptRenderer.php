<?php

namespace App\Services;

use App\Models\PrintTemplate;
use App\Models\Sale;
use App\Support\Demo;

/**
 * Renders a stored receipt template (Premium's `receipts.custom_templates`).
 *
 * The template is plain HTML with `{{ token }}` placeholders and one repeating
 * `{{#items}}…{{/items}}` block — deliberately NOT Blade. The person editing it
 * is the client's admin through a Settings textarea; compiling that as Blade
 * would turn the field into arbitrary PHP execution on the till. Substitution
 * is a one-way string replace, so the worst a bad template can do is look wrong.
 *
 * Values are escaped on the way in; the template's own markup is the author's
 * and renders as written.
 */
final class ReceiptRenderer
{
    /** The receipt template this business prints with, or null to use the built-in view. */
    public static function active(): ?PrintTemplate
    {
        return PrintTemplate::query()
            ->where('type', 'receipt')
            ->where('is_active', true)
            ->whereRaw("trim(template_html) <> ''")
            ->orderByDesc('is_default')
            ->first();
    }

    public static function render(PrintTemplate $template, Sale $sale, array $data): string
    {
        $html = self::renderItems($template->template_html, $sale);
        $html = self::substitute($html, self::tokens($sale, $data));
        $html = self::withNotice($html, (bool) ($data['isTest'] ?? false));

        return self::withPrintControls($html, (bool) ($data['autoPrint'] ?? false));
    }

    /** @return array<string,string> */
    private static function tokens(Sale $sale, array $data): array
    {
        $business = $data['business'] ?? [];
        $money = fn ($v) => number_format((float) $v, 2);

        return [
            'business_name' => (string) ($business['name'] ?? ''),
            'business_name_ar' => (string) ($business['name_ar'] ?? ''),
            'address' => (string) ($business['address'] ?? ''),
            'phone' => (string) ($business['phone'] ?? ''),
            'tax_number' => (string) ($business['tax_number'] ?? ''),
            'header' => (string) ($data['header'] ?? ''),
            'footer' => (string) ($data['footer'] ?? ''),
            'receipt_number' => (string) $sale->receipt_number,
            'date' => $sale->created_at->format('Y-m-d H:i'),
            'cashier' => (string) ($sale->user?->name ?? ''),
            'customer' => (string) ($sale->customer?->name ?? ''),
            'subtotal' => $money($sale->subtotal_usd),
            'discount' => $money($sale->discount_amount_usd),
            'tax' => $money($sale->tax_amount_usd),
            'total_usd' => $money($sale->total_usd),
            'total_lbp' => number_format((float) $sale->total_lbp),
            'payment_method' => str_replace('_', ' ', (string) $sale->payment_method),
            'change_usd' => $money($sale->change_usd),
            'change_lbp' => number_format((float) $sale->change_lbp),
            'loyalty_points_earned' => (string) (int) $sale->loyalty_points_earned,
        ];
    }

    private static function renderItems(string $html, Sale $sale): string
    {
        return (string) preg_replace_callback(
            '/\{\{#items\}\}(.*?)\{\{\/items\}\}/s',
            function (array $m) use ($sale) {
                $out = '';
                foreach ($sale->items as $item) {
                    $out .= self::substitute($m[1], [
                        'name' => (string) $item->product_name,
                        'qty' => rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.'),
                        'unit_price' => number_format((float) $item->unit_price_usd, 2),
                        'discount' => number_format((float) $item->discount_amount_usd, 2),
                        'line_total' => number_format((float) $item->line_total_usd, 2),
                    ]);
                }

                return $out;
            },
            $html,
        );
    }

    /** @param array<string,string> $tokens */
    private static function substitute(string $html, array $tokens): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn (array $m) => e($tokens[strtolower($m[1])] ?? ''),
            $html,
        );
    }

    /**
     * The words that keep a demo or test printout from passing as a transaction
     * record. Kept here, in one place, because receipts.thermal prints the same
     * three lines and the two must never drift — the markup differs (that view
     * has its own stylesheet, a custom template has none), the wording does not.
     *
     * @return array{label:string,en:string,ar:string}|null
     */
    public static function noticeLines(bool $isTest, bool $isDemo): ?array
    {
        if (! $isTest && ! $isDemo) {
            return null;
        }

        // Both languages, always, from the receipt catalogue — whatever the
        // receipt language is set to.
        return [
            'label' => __($isTest ? 'receipt.test_print' : 'receipt.demo_receipt', [], 'en'),
            'en' => __('receipt.not_valid', [], 'en'),
            'ar' => __($isTest ? 'receipt.test_ar' : 'receipt.demo_ar', [], 'ar'),
        ];
    }

    /**
     * A custom template is written by the client and cannot be relied on to
     * carry the notice itself, so it is stamped top and bottom from here — the
     * same two places receipts.thermal puts it.
     */
    private static function withNotice(string $html, bool $isTest): string
    {
        $lines = self::noticeLines($isTest, Demo::enabled());

        if ($lines === null) {
            return $html;
        }

        $notice = '<div style="text-align:center;font-weight:bold">*** '.e($lines['label']).' ***<br>'
            .e($lines['en']).'<br><span dir="rtl">'.e($lines['ar']).'</span></div><hr>';

        $html = preg_match('/<body[^>]*>/i', $html, $m) === 1
            ? str_replace($m[0], $m[0].$notice, $html)
            : $notice.$html;

        return str_contains($html, '</body>')
            ? str_replace('</body>', $notice.'</body>', $html)
            : $html.$notice;
    }

    /**
     * Printing is the point of the page, so the buttons and the auto-print hook
     * come from here rather than from whatever the author remembered to paste.
     */
    private static function withPrintControls(string $html, bool $autoPrint): string
    {
        $controls = '<div class="receipt-actions" style="padding:10mm 0;text-align:center">'
            .'<button type="button" onclick="window.print()">'.e(__('receipt.print')).'</button> '
            .'<button type="button" onclick="window.close()">'.e(__('receipt.close')).'</button></div>'
            .'<style>@media print{.receipt-actions{display:none}}</style>';

        if ($autoPrint) {
            $controls .= '<script>window.addEventListener("load",()=>setTimeout(()=>{window.print();'
                .'setTimeout(()=>window.close(),500)},250))</script>';
        }

        return str_contains($html, '</body>')
            ? str_replace('</body>', $controls.'</body>', $html)
            : $html.$controls;
    }
}
