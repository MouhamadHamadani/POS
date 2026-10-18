<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Setting;
use Native\Laravel\Client\Client;
use Throwable;

/**
 * Which print format a sale uses, what the A4 invoice shows, and how an A4 invoice
 * reaches a named printer.
 *
 * The thermal receipt has always been plain `window.print()` in a popup, which
 * opens the system print dialog with the default printer pre-selected. An A4
 * invoice sent to that same default (usually the thermal printer) would come out
 * on receipt paper, so A4 can instead go straight to a printer picked in Settings
 * — but only inside the packaged app, where NativePHP can reach Electron. Anywhere
 * else, and whenever no printer is set, A4 opens the dialog and the user chooses.
 */
class ReceiptPrintService
{
    public const FORMAT_THERMAL = 'thermal';
    public const FORMAT_A4 = 'a4';
    public const FORMAT_ASK = 'ask';
    public const FORMATS = [self::FORMAT_THERMAL, self::FORMAT_A4, self::FORMAT_ASK];

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

    // ---- A4 straight to a printer (packaged app only) ------------------------

    public function a4PrinterName(): string
    {
        return trim((string) Setting::get('a4_printer_name', ''));
    }

    /** True only inside the NativePHP shell with an A4 printer chosen in Settings. */
    public function silentA4Available(): bool
    {
        return $this->inNativeShell() && $this->a4PrinterName() !== '';
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

    /**
     * Send finished A4 HTML to the configured printer without a dialog. False when
     * Electron refused or could not be reached, so the caller can fall back to the
     * dialog instead of telling the cashier an invoice printed when it did not.
     */
    public function printA4Silently(string $html): bool
    {
        if (! $this->silentA4Available()) {
            return false;
        }

        try {
            // Electron loads this as a data: URL without encoding it, so a bare `#` or `%`
            // in the markup would cut the page off — hence the percent-encoding.
            return app(Client::class)->post('system/print', [
                'html' => rawurlencode($html),
                'printer' => $this->a4PrinterName(),
                'settings' => ['silent' => true, 'pageSize' => 'A4', 'printBackground' => true],
            ])->successful();
        } catch (Throwable $e) {
            report($e);

            return false;
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
