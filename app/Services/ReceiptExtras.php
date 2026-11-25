<?php

namespace App\Services;

use App\Models\Setting;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Closure;
use Illuminate\Http\UploadedFile;
use Picqer\Barcode\Types\TypeCode128;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * What a receipt prints besides the sale itself: the shop's logo (top), and below it a QR code and the
 * receipt-number barcode. Available on every plan — the toggles are client settings, not plan features,
 * so nothing here asks Feature / License.
 *
 * Everything is made here, offline: the logo is re-encoded by GD (which also strips anything a file might
 * carry) and kept in the database as data: URIs, so it travels with a backup and prints from a popup or a
 * silent print alike; the QR code and the barcode are inline SVG. Nothing touches the network.
 *
 * Both thermal.blade.php and a4.blade.php call forReceipt(); a test print hands it the typed, unsaved form.
 */
class ReceiptExtras
{
    /** The one setting that holds the logo: JSON, never posted by a form, written only by storeLogo() / removeLogo(). */
    public const LOGO_KEY = 'receipt_logo';

    public const LOGO_MAX_KB = 1024;
    /** A logo bigger than this is cut down, never stretched: this is the stored (colour, A4) copy. */
    private const LOGO_MAX_PX = [800, 400];
    /** A decoded image that is bigger than this is refused, not decompressed (GD holds 4 bytes a pixel). */
    private const SOURCE_MAX_PX = 2500;
    /** The logo's box on the A4 invoice, width x height in mm. */
    private const A4_MAX_MM = [70, 25];
    /** Tallest logo on a thermal roll. */
    private const THERMAL_MAX_HEIGHT_MM = 20;
    /** 203 dpi, the common thermal head: the monochrome logo is made at exactly the dots it will print on. */
    private const DOTS_PER_MM = 8;
    /** Grey level (0 black .. 255 white) below which a pixel prints black. */
    private const THRESHOLD = 150;

    /** One printer dot at 203 dpi (8 dots/mm), the unit QR modules and barcode bars are built from. */
    private const DOT_MM = 0.125;
    /** Longest QR content that stays scannable at ~28 mm (error correction M: ~version 10, 57 modules). */
    public const QR_MAX_CHARS = 200;
    public const QR_MAX_BYTES = 300;
    public const QR_CAPTION_MAX = 60;
    private const QR_QUIET_MODULES = 4;
    /** The side a QR code aims for, in mm (24-30 on a roll, a little larger on A4). */
    private const QR_TARGET_MM = ['thermal' => 28, 'a4' => 34];
    /** Width of the A4 invoice's text column, in mm: what the QR code and barcode may use. */
    private const A4_TEXT_MM = 80;
    /** Bar height, in mm (10-12 is plenty for a hand scanner). */
    private const BARCODE_HEIGHT_MM = ['thermal' => 11, 'a4' => 12];

    // ---- the logo -------------------------------------------------------------

    public function hasLogo(): bool
    {
        return is_array(Setting::get(self::LOGO_KEY));
    }

    /** What the Settings card shows: the colour copy and the facts about it. Null when there is none. */
    public function logoMeta(): ?array
    {
        $logo = Setting::get(self::LOGO_KEY);

        return is_array($logo) && isset($logo['color']['uri'])
            ? ['uri' => $logo['color']['uri'], 'name' => (string) ($logo['name'] ?? ''), 'width' => $logo['color']['w'], 'height' => $logo['color']['h']]
            : null;
    }

    /**
     * Re-encode an uploaded image into the three copies a receipt needs (colour for A4, 1-bit for each roll
     * width) and store them. The upload was already checked by the controller (type, size, pixels); a file
     * GD cannot read is refused here. Returns what the audit row records.
     *
     * @return array{name:string,bytes:int,width:int,height:int}
     */
    public function storeLogo(UploadedFile $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['logo' => __('messages.logo_needs_gd')]);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $src = @imagecreatefromstring($bytes);
        if ($src === false || imagesx($src) < 1 || imagesy($src) < 1
            || imagesx($src) > self::SOURCE_MAX_PX || imagesy($src) > self::SOURCE_MAX_PX) {
            throw ValidationException::withMessages(['logo' => __('messages.logo_unreadable')]);
        }
        [$w, $h] = [imagesx($src), imagesy($src)];

        $logo = [
            'name' => mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '', basename($file->getClientOriginalName())), 0, 100),
            'color' => $this->colour($src),
            'mono' => [],
        ];
        $flat = $this->onWhite($src);
        foreach ([80, 58] as $paper) {
            $logo['mono'][$paper] = $this->mono($flat, $paper);
        }

        Setting::set(self::LOGO_KEY, $logo, 'receipt', 'json');

        return ['name' => $logo['name'], 'bytes' => strlen($bytes), 'width' => $w, 'height' => $h];
    }

    /** True when there was a logo to remove. */
    public function removeLogo(): bool
    {
        $had = $this->hasLogo();
        Setting::where('key', self::LOGO_KEY)->delete();
        Cache::forget('setting:'.self::LOGO_KEY);

        return $had;
    }

    /**
     * The logo as this paper wants it: the 1-bit copy sized in mm for a roll, the colour copy for A4.
     * Null when there is no logo.
     *
     * @return array{uri:string,w_mm:float,h_mm:float}|null  (A4 carries no size: CSS caps it)
     */
    public function logo(string $format, int $paper): ?array
    {
        $logo = Setting::get(self::LOGO_KEY);
        if (! is_array($logo)) {
            return null;
        }

        if ($format !== 'a4') {
            return $logo['mono'][$paper] ?? $logo['mono'][80] ?? null;
        }

        // Sized in mm up front, not by CSS max-*: the invoice measures its blocks before the image has loaded.
        $c = $logo['color'];
        $mmW = min(self::A4_MAX_MM[0], self::A4_MAX_MM[1] * $c['w'] / $c['h']);

        return ['uri' => $c['uri'], 'w_mm' => round($mmW, 2), 'h_mm' => round($mmW * $c['h'] / $c['w'], 2)];
    }

    /** Colour copy: cut down to LOGO_MAX_PX (never up), alpha kept, as a PNG. */
    private function colour(\GdImage $src): array
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::LOGO_MAX_PX[0] / $w, self::LOGO_MAX_PX[1] / $h);
        [$dw, $dh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $out = imagecreatetruecolor($dw, $dh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);

        return ['uri' => $this->png($out), 'w' => $dw, 'h' => $dh];
    }

    /** The image composited on white: a transparent logo must print as ink on paper, not as black. */
    private function onWhite(\GdImage $src): \GdImage
    {
        $flat = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));

        return $flat;
    }

    /**
     * Thermal copy: scaled to the dots it will print on (fits the printable width, at most 20 mm tall), then a
     * hard threshold to pure black and white. A thermal head has no grey; letting the driver dither a logo
     * is what turns it into a smudge.
     */
    private function mono(\GdImage $flat, int $paper): array
    {
        [$w, $h] = [imagesx($flat), imagesy($flat)];
        $textMm = ReceiptPrintService::printableMm($paper) - 2 * ReceiptPrintService::PAGE_PAD_MM;
        $mmW = min($textMm, self::THERMAL_MAX_HEIGHT_MM * $w / $h);
        $mmH = $mmW * $h / $w;
        [$dw, $dh] = [max(1, (int) round($mmW * self::DOTS_PER_MM)), max(1, (int) round($mmH * self::DOTS_PER_MM))];

        $scaled = imagescale($flat, $dw, $dh, IMG_BICUBIC);
        $out = imagecreate($dw, $dh);
        imagecolorallocate($out, 255, 255, 255);
        $black = imagecolorallocate($out, 0, 0, 0);
        for ($y = 0; $y < $dh; $y++) {
            for ($x = 0; $x < $dw; $x++) {
                $rgb = imagecolorat($scaled, $x, $y);
                if (0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255) < self::THRESHOLD) {
                    imagesetpixel($out, $x, $y, $black);
                }
            }
        }

        return ['uri' => $this->png($out), 'w_mm' => round($mmW, 2), 'h_mm' => round($mmH, 2)];
    }

    private function png(\GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 9);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    // ---- the QR code ----------------------------------------------------------

    /**
     * A QR code for $text as inline SVG, sized in whole printer dots, or null when there is nothing to
     * print. The matrix comes from bacon/bacon-qr-code's encoder; its own SVG back end is not used because
     * it needs ext-xmlwriter, which the PHP bundled in the desktop build does not have. So the drawing is
     * done here: one path of horizontal runs, four modules of quiet zone, error correction M.
     *
     * Each module is a whole number (k >= 2) of 0.125 mm dots, k picked to land nearest $targetMm without
     * passing $availableMm, so edges do not straddle dots; at 0.25 mm a module is still easy for a phone.
     *
     * @return array{svg:string,mm:float}|null
     */
    public function qr(string $text, float $targetMm, float $availableMm): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::QR_MAX_CHARS || strlen($text) > self::QR_MAX_BYTES) {
            return null;
        }

        $ascii = mb_check_encoding($text, 'ASCII');
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), $ascii ? 'ISO-8859-1' : 'UTF-8')->getMatrix();
        $n = $matrix->getWidth();
        $total = $n + 2 * self::QR_QUIET_MODULES;

        $k = max(2, (int) round($targetMm / ($total * self::DOT_MM)));
        while ($k > 2 && $total * $k * self::DOT_MM > $availableMm) {
            $k--;
        }
        if ($total * $k * self::DOT_MM > $availableMm) {
            return null;
        }

        $d = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }
                for ($run = 1; $x + $run < $n && $matrix->get($x + $run, $y) === 1; $run++);
                $d .= 'M'.($x + self::QR_QUIET_MODULES).' '.($y + self::QR_QUIET_MODULES)."h{$run}v1h-{$run}z";
                $x += $run;
            }
        }

        return [
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges" role="img">'
                .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path d="'.$d.'" fill="#000"/></svg>',
            'mm' => round($total * $k * self::DOT_MM, 3),
        ];
    }

    /** The QR block for one receipt: the code and its caption, or null when switched off, empty or unprintable. */
    private function qrBlock(string $format, string $content, string $caption, int $paper): ?array
    {
        $textMm = $format === 'a4' ? self::A4_TEXT_MM : ReceiptPrintService::printableMm($paper) - 2 * ReceiptPrintService::PAGE_PAD_MM;
        $qr = $this->qr($content, $format === 'a4' ? self::QR_TARGET_MM['a4'] : self::QR_TARGET_MM['thermal'], $textMm);

        return $qr ? $qr + ['caption' => mb_substr(trim($caption), 0, self::QR_CAPTION_MAX) ?: null] : null;
    }

    // ---- the receipt-number barcode ----------------------------------------------

    /**
     * The receipt number as a Code 128 barcode (inline SVG), or null when it cannot be printed
     * scannably, in which case the receipt still prints the number as text.
     *
     * Code 128 holds any printable ASCII (space to ~), so a number with another character (an Arabic
     * prefix, say) is not encoded. picqer picks and switches the code sets itself (a run of digits goes
     * out as set C, about half the width). Every bar is a whole number of printer dots: 4, 3 or 2 dots
     * (0.5 / 0.375 / 0.25 mm), the widest that fits $availableMm; below 0.25 mm a thermal head and a
     * scanner stop agreeing, so then there is no barcode. Quiet zones are the paper's own unprinted margin
     * plus the page padding (at least 5 mm each side on both roll widths, 10 modules at 0.5 mm).
     *
     * @return array{svg:string,w_mm:float,h_mm:float,modules:int}|null
     */
    public function barcode(string $number, float $availableMm, float $heightMm): ?array
    {
        if ($number === '' || ! preg_match('/^[\x20-\x7e]+$/', $number)) {
            return null;
        }

        $code = (new TypeCode128())->getBarcode($number);
        $modules = $code->getWidth();

        for ($k = 4; $k >= 2 && $modules * $k * self::DOT_MM > $availableMm; $k--);
        if ($k < 2) {
            return null;
        }

        $d = '';
        $x = 0;
        foreach ($code->getBars() as $bar) {
            if ($bar->isBar()) {
                $d .= "M{$x} 0h{$bar->getWidth()}v1h-{$bar->getWidth()}z";
            }
            $x += $bar->getWidth();
        }

        return [
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$modules.' 1" preserveAspectRatio="none" shape-rendering="crispEdges" role="img">'
                .'<path d="'.$d.'" fill="#000"/></svg>',
            'w_mm' => round($modules * $k * self::DOT_MM, 3),
            'h_mm' => $heightMm,
            'modules' => $modules,
        ];
    }

    private function barcodeBlock(string $format, string $number, int $paper): ?array
    {
        $textMm = $format === 'a4' ? self::A4_TEXT_MM : ReceiptPrintService::printableMm($paper) - 2 * ReceiptPrintService::PAGE_PAD_MM;

        return $this->barcode($number, $textMm, self::BARCODE_HEIGHT_MM[$format === 'a4' ? 'a4' : 'thermal']);
    }

    // ---- what one receipt prints ----------------------------------------------

    /**
     * The extras a receipt carries, for 'thermal' (a roll of $paper mm) or 'a4'. Each is null when its
     * toggle is off (the default) or there is nothing to print, so a view prints what it finds and an
     * install that never touched the settings prints exactly what it always did.
     *
     * $input reads a form field, falling back to the saved setting: the Settings test print passes the
     * values typed but not yet saved.
     *
     * @return array{logo: ?array, qr: ?array, barcode: ?array}
     */
    public function forReceipt(string $format, string $number, int $paper, ?Closure $input = null): array
    {
        $read = fn (string $key, mixed $default = null) => $input ? $input($key, Setting::get($key, $default)) : Setting::get($key, $default);
        $on = fn (string $key) => filter_var($read($key, false), FILTER_VALIDATE_BOOLEAN);

        return [
            'logo' => $on($format === 'a4' ? 'receipt_logo_a4' : 'receipt_logo_thermal') ? $this->logo($format, $paper) : null,
            'qr' => $on('receipt_qr_enabled') ? $this->qrBlock($format, (string) $read('receipt_qr_content', ''), (string) $read('receipt_qr_caption', ''), $paper) : null,
            'barcode' => $on('receipt_barcode_enabled') ? $this->barcodeBlock($format, $number, $paper) : null,
        ];
    }
}
