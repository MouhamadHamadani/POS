<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use Native\Laravel\Client\Client;
use Throwable;

/**
 * Which print format a sale uses, what the A4 invoice shows, and how a receipt or an
 * invoice reaches a printer.
 *
 * Inside the packaged app (the NativePHP shell) everything prints silently through
 * Electron: to the printer named in Settings (`thermal_printer_name` for the receipt,
 * `a4_printer_name` for the invoice), or to the Windows default printer when none is
 * named. There is never a print dialog and never a popup window there. Anywhere else
 * (a browser tab in development) the receipt page opens in a popup and calls
 * `window.print()`, as it always did.
 */
class ReceiptPrintService
{
    public const FORMAT_THERMAL = 'thermal';
    public const FORMAT_A4 = 'a4';
    public const FORMAT_ASK = 'ask';
    public const FORMATS = [self::FORMAT_THERMAL, self::FORMAT_A4, self::FORMAT_ASK];

    /** `receipt_font_size` setting => the multiplier every size on the built-in receipt and invoice is scaled by. */
    public const FONT_SIZES = ['small' => 0.9, 'normal' => 1.0, 'large' => 1.2];

    /** The text-size multiplier for a stored (or just typed) `receipt_font_size`; anything unrecognised is normal. */
    public function fontScale(?string $size = null): float
    {
        $size ??= (string) Setting::get('receipt_font_size', 'normal');

        return self::FONT_SIZES[$size] ?? 1.0;
    }

    /** The `receipt_format` setting; anything unrecognised reads as thermal. */
    public function formatSetting(): string
    {
        $format = (string) Setting::get('receipt_format', self::FORMAT_THERMAL);

        return in_array($format, self::FORMATS, true) ? $format : self::FORMAT_THERMAL;
    }

    /** What Ctrl+P and auto-print use: A4 only when the setting says so; `ask` has no default. */
    public function defaultFormat(): string
    {
        return $this->formatSetting() === self::FORMAT_A4 ? self::FORMAT_A4 : self::FORMAT_THERMAL;
    }

    /** A usable `?format=` wins; a missing or unknown one falls back to the default. */
    public function resolveFormat(?string $requested): string
    {
        return in_array($requested, [self::FORMAT_THERMAL, self::FORMAT_A4], true) ? $requested : $this->defaultFormat();
    }

    // ---- straight to a printer (packaged app only) --------------------------

    /** The named A4 printer; '' means the Windows default printer. */
    public function a4PrinterName(): string
    {
        return trim((string) Setting::get('a4_printer_name', ''));
    }

    /** The named receipt printer; '' means the Windows default printer. */
    public function thermalPrinterName(): string
    {
        return trim((string) Setting::get('thermal_printer_name', ''));
    }

    /**
     * Send finished receipt HTML to the receipt printer. No page size is sent: a roll-paper
     * driver sizes the page itself, and the page's own CSS sets the width (receipt_width).
     * False when Electron refused or could not be reached; the caller shows a Retry.
     */
    public function printThermalSilently(string $html): bool
    {
        return $this->printSilently($html, $this->thermalPrinterName(), ['margins' => ['marginType' => 'none']]);
    }

    /** Send finished A4 HTML to the invoice printer. False on failure, like the receipt. */
    public function printA4Silently(string $html): bool
    {
        return $this->printSilently($html, $this->a4PrinterName(), ['pageSize' => 'A4']);
    }

    /**
     * Electron's own `webContents.print` takes no header or footer unless handed one, so no
     * URL or date lands on the paper. The printer name is left out when empty, and Electron
     * then prints to the Windows default printer. Electron loads the markup as a data: URL
     * without encoding it, so a bare `#` or `%` would cut the page off — hence the
     * percent-encoding, and fonts must travel inside the page too (see fontDataUri).
     */
    private function printSilently(string $html, string $printer, array $settings): bool
    {
        if (! $this->inNativeShell()) {
            return false;
        }

        try {
            return app(Client::class)->post('system/print', array_filter([
                'html' => rawurlencode($html),
                'printer' => $printer,
                'settings' => ['silent' => true, 'printBackground' => true] + $settings,
            ], fn ($v) => $v !== ''))->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * A bundled font file as a data: URI. Electron prints the page from a data: URL, whose
     * origin is opaque, so an http://127.0.0.1/fonts/... @font-face would be refused for want
     * of CORS and Arabic would fall back to a system font.
     */
    public static function fontDataUri(string $file): string
    {
        static $cache = [];

        return $cache[$file] ??= 'data:font/woff2;base64,'.base64_encode((string) file_get_contents(public_path('fonts/'.basename($file))));
    }

    public function inNativeShell(): bool
    {
        return (bool) config('nativephp-internal.running');
    }

    /**
     * Installed printers as name => display name, for the Settings dropdown. Empty
     * outside the packaged app or when Electron cannot be reached — the form then
     * falls back to a text box.
     *
     * @return array<string, string>
     */
    public function printers(): array
    {
        if (! $this->inNativeShell()) {
            return [];
        }

        try {
            return collect(app(Client::class)->get('system/printers')->json('printers') ?? [])
                ->filter(fn ($p) => ! empty($p['name']))
                ->mapWithKeys(fn ($p) => [$p['name'] => $p['displayName'] ?? $p['name']])
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    // ---- what the A4 invoice shows -------------------------------------------

    /**
     * Presentation data for receipts/a4.blade.php. Every money figure is read from
     * the stored sale and its lines; nothing is repriced or re-taxed here, so a
     * reprint always matches the original.
     *
     * @return array<string, mixed>
     */
    public function invoice(Sale $sale): array
    {
        $lines = [];
        foreach ($sale->items as $i => $item) {
            $product = $item->product;
            $lines[] = [
                'n' => $i + 1,
                'code' => $item->product_sku ?: ($product?->sku ?: $product?->barcode),
                'name' => $item->product_name,
                'name_ar' => $product?->name_ar,
                'qty' => rtrim(rtrim(number_format((float) $item->qty, 4, '.', ''), '0'), '.'),
                'unit' => $product?->unit,
                'unit_price' => (float) $item->unit_price_usd,
                'discount' => (float) $item->discount_amount_usd,
                'total' => (float) $item->line_total_usd,
            ];
        }

        $discount = (float) $sale->discount_amount_usd;
        $cart = (float) $sale->cart_discount_usd;

        return [
            'lines' => $lines,
            'has_line_discount' => collect($lines)->contains(fn ($l) => $l['discount'] > 0),
            // discount_amount_usd is every discount and the cart slice is already inside it.
            'item_discounts' => max(0.0, $discount - $cart),
            'cart_discount' => $cart,
            'discount' => $discount,
            'vat_rates' => $sale->items->filter(fn ($i) => (float) $i->tax_amount_usd > 0)
                ->map(fn ($i) => round((float) $i->tax_rate * 100, 2))
                ->unique()->sort()->values()->all(),
        ];
    }
}
