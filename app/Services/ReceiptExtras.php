<?php

namespace App\Services;

use App\Models\Setting;
use Closure;
use Illuminate\Http\UploadedFile;
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

    // ---- what one receipt prints ----------------------------------------------

    /**
     * The extras a receipt carries, for 'thermal' (a roll of $paper mm) or 'a4'. Each is null when its
     * toggle is off (the default) or there is nothing to print, so a view prints what it finds and an
     * install that never touched the settings prints exactly what it always did.
     *
     * $input reads a form field, falling back to the saved setting: the Settings test print passes the
     * values typed but not yet saved.
     *
     * @return array{logo: ?array}
     */
    public function forReceipt(string $format, string $number, int $paper, ?Closure $input = null): array
    {
        $read = fn (string $key, mixed $default = null) => $input ? $input($key, Setting::get($key, $default)) : Setting::get($key, $default);
        $on = fn (string $key) => filter_var($read($key, false), FILTER_VALIDATE_BOOLEAN);

        return [
            'logo' => $on($format === 'a4' ? 'receipt_logo_a4' : 'receipt_logo_thermal') ? $this->logo($format, $paper) : null,
        ];
    }
}
