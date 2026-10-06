<?php

namespace Tests\Unit;

use App\Support\PdfText;
use PHPUnit\Framework\TestCase;

/** dompdf cannot join or reorder Arabic; PdfText hands it joined, visual-order glyphs. */
class PdfTextTest extends TestCase
{
    public function test_pure_latin_and_numbers_are_returned_untouched(): void
    {
        foreach (['Olive Oil 1L', '$1,250.00', 'REC-2026-00001', '', '92 pcs'] as $text) {
            $this->assertSame($text, PdfText::shape($text));
        }
        $this->assertSame('', PdfText::shape(null));
    }

    public function test_arabic_is_converted_to_joined_presentation_forms(): void
    {
        $in = 'زيت زيتون';
        $out = PdfText::shape($in);

        $this->assertNotSame($in, $out);
        // Contextual forms live in the Arabic Presentation Forms-B block (U+FE70–U+FEFF).
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $out);
        // Same letters count, no stray line breaks injected.
        $this->assertStringNotContainsString("\n", $out);
    }

    public function test_mixed_arabic_latin_and_digits_stay_readable(): void
    {
        // Unicode bidi puts a digit that follows Arabic letters inside the Arabic run, so the "1"
        // can land beside the Latin words rather than glued to the "L"; what must hold is that the
        // Latin words stay whole and in order (not mirrored) and the digit survives.
        $out = PdfText::shape('زيت زيتون 1L Al Wadi');
        $this->assertStringContainsString('Al Wadi', $out);
        $this->assertStringNotContainsString('idaW', $out);
        $this->assertStringContainsString('1', $out);

        $out = PdfText::shape('شاي 25 كيس');
        $this->assertStringContainsString('25', $out, 'digits keep their order');
        $this->assertStringNotContainsString('52', $out);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $out, 'Western digits stay Western');
    }

    public function test_a_long_arabic_string_is_not_wrapped_by_the_library(): void
    {
        $out = PdfText::shape(str_repeat('زيت زيتون بكر ممتاز ', 12));

        $this->assertStringNotContainsString("\n", $out);
    }
}
