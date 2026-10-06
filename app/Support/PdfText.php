<?php

namespace App\Support;

/**
 * Text for dompdf. dompdf does no Arabic contextual shaping and no bidi, so an
 * Arabic string reaches the PDF as disconnected, left-to-right letters. ar-php's
 * glyph converter returns it already joined and in visual order; strings with no
 * Arabic are left exactly as they are (so English reports are byte-identical).
 *
 * Western digits are kept (utf8Glyphs' Hindi-digit switch stays off).
 */
final class PdfText
{
    private static ?object $arabic = null;

    public static function shape(?string $text): string
    {
        $text = (string) $text;

        if ($text === '' || ! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        // 100000 chars per line: ar-php otherwise inserts its own line breaks.
        return self::arabic()->utf8Glyphs($text, 100000, false);
    }

    private static function arabic(): object
    {
        return self::$arabic ??= new \ArPHP\I18N\Arabic();
    }

    /** dompdf needs a writable font cache; make sure it exists before rendering. */
    public static function ensureFontDirs(): void
    {
        foreach ([config('dompdf.options.font_dir'), config('dompdf.options.font_cache')] as $dir) {
            if (is_string($dir) && $dir !== '' && ! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}
