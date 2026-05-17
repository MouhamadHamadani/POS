<?php

namespace App\Services;

use App\Models\Product;

class BarcodeService
{
    /**
     * Generate a unique EAN-13 barcode. The 13th digit is the check digit;
     * the first 12 digits are derived from a uniqid + product id seed.
     */
    public function generateUniqueEan13(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $base12 = str_pad((string) random_int(100_000_000_000, 999_999_999_999), 12, '0', STR_PAD_LEFT);
            $candidate = $base12 . $this->ean13CheckDigit($base12);

            if (!Product::where('barcode', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique barcode after 20 attempts.');
    }

    public function ean13CheckDigit(string $first12): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (($i % 2) === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;
        return (string) $check;
    }

    /**
     * Render a Code128 / EAN-13 barcode as inline SVG (works in HTML + PDF).
     */
    public function svg(string $code, string $type = 'C128'): string
    {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        $map = [
            'C128' => $generator::TYPE_CODE_128,
            'EAN13' => $generator::TYPE_EAN_13,
            'C39' => $generator::TYPE_CODE_39,
        ];
        return $generator->getBarcode($code, $map[$type] ?? $generator::TYPE_CODE_128);
    }
}
