<?php

namespace App\Support;

use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Spreadsheet downloads that survive the packaged app.
 *
 * NativePHP ships its own PHP binary (vendor/nativephp/php-bin), and that build
 * has no ext-xmlwriter — so PhpSpreadsheet cannot write .xlsx there at all. It
 * fatals with "Class XMLWriter not found", which a user sees as a broken
 * download button, not as a missing extension. WAMP's PHP *does* have it, so
 * this only bites in the built desktop app: exactly the environment that is
 * hardest to notice it in.
 *
 * Reading .xlsx is unaffected — the reader uses SimpleXML and ZipArchive, both
 * of which the bundled build has — so uploads still accept .xlsx either way.
 * Only generated files have to step down to CSV, which needs no XML at all and
 * opens in Excel regardless.
 */
final class Spreadsheet
{
    /** True when this runtime can write a real .xlsx. */
    public static function canWriteXlsx(): bool
    {
        return extension_loaded('xmlwriter');
    }

    public static function extension(): string
    {
        return self::canWriteXlsx() ? 'xlsx' : 'csv';
    }

    /**
     * Download an export in the best format this runtime can actually produce.
     * `$basename` carries no extension — this picks it.
     */
    public static function download(object $export, string $basename): BinaryFileResponse
    {
        return Excel::download($export, $basename . '.' . self::extension());
    }
}
