<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Services\ProductImportService;
use App\Support\Spreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Concerns\FromArray;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk product upload. Every route here sits behind `can:bulk-upload-products`
 * (see routes/web.php) — the UI hiding the menu item is a convenience, not the
 * control.
 *
 * Preview and commit are two requests over one stored upload: `preview()` parks
 * the file and shows what would happen, `store()` re-reads and re-validates the
 * same file before writing anything.
 */
class ProductImportController extends Controller
{
    /** Where preview() parks the upload for store() to pick up. */
    private const SESSION_PATH = 'product_import.path';
    private const SESSION_NAME = 'product_import.name';

    public function __construct(private readonly ProductImportService $imports) {}

    public function show(): View
    {
        return view('products.import', ['report' => null, 'filename' => null]);
    }

    public function template(): BinaryFileResponse
    {
        $rows = $this->imports->templateRows();

        $export = new class($rows) implements FromArray {
            public function __construct(private array $rows) {}
            public function array(): array { return $this->rows; }
        };

        // Format is the runtime's choice, not the user's: the packaged app
        // cannot write .xlsx. See App\Support\Spreadsheet.
        return Spreadsheet::download($export, 'product-import-template');
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $upload = $request->file('file');
        $path = $upload->store('imports');

        try {
            $report = $this->imports->analyse(Storage::disk('local')->path($path));
        } catch (\RuntimeException $e) {
            Storage::disk('local')->delete($path);
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $request->session()->put(self::SESSION_PATH, $path);
        $request->session()->put(self::SESSION_NAME, $upload->getClientOriginalName());

        return view('products.import', [
            'report' => $report,
            'filename' => $upload->getClientOriginalName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $path = $request->session()->get(self::SESSION_PATH);

        // The path comes from the session, never the request — a confirm POST
        // must not be able to name a file of its own choosing.
        if (!$path || !Storage::disk('local')->exists($path)) {
            return redirect()->route('products.import.show')
                ->withErrors(['file' => 'That upload has expired. Please upload the file again.']);
        }

        // Re-read and re-validate rather than trusting the preview: the
        // catalogue may have changed since, and the preview is client-visible.
        try {
            $report = $this->imports->analyse(Storage::disk('local')->path($path));
            $created = $this->imports->commit($report, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } finally {
            $this->forget($request, $path);
        }

        // One entry for the batch, not one per row — a 500-product import
        // should not bury every other action in the audit log.
        AuditLog::record($request->user()->id, 'products_bulk_import', Product::class, null, null, [
            'file' => $request->session()->pull(self::SESSION_NAME) ?? 'upload',
            'rows' => $report['total'],
            'created' => $created,
            'duplicates_skipped' => count($report['duplicates']),
        ]);

        return redirect()->route('products.index')
            ->with('success', "Imported {$created} product(s).");
    }

    private function forget(Request $request, string $path): void
    {
        Storage::disk('local')->delete($path);
        $request->session()->forget(self::SESSION_PATH);
    }
}
