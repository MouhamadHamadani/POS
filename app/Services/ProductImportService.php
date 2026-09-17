<?php

namespace App\Services;

use App\Http\Requests\StoreProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk product import: parse -> validate every row -> report -> commit.
 *
 * Parse and validate are one pass (`analyse()`), run twice: once to build the
 * preview and again at commit time against the same stored file. Re-running it
 * is deliberate — the confirm POST is a trust boundary like any other, and the
 * catalogue may have moved between preview and confirm.
 */
class ProductImportService
{
    /** Columns the template ships with, in order. */
    public const COLUMNS = [
        'name', 'name_ar', 'sku', 'barcode', 'category', 'tax',
        'price_usd', 'cost_usd', 'wholesale_price_usd', 'vip_price_usd',
        'price_lbp', 'force_lbp_price',
        'stock_qty', 'min_stock', 'max_stock', 'unit', 'location',
        'type', 'is_active', 'is_taxable', 'allow_discount', 'track_stock',
        'description',
    ];

    /** Columns the file must actually contain; the rest fall back to model defaults. */
    private const REQUIRED_COLUMNS = ['name', 'category', 'price_usd'];

    private const MAX_ROWS = 5000;

    public function __construct(private readonly BarcodeService $barcodes) {}

    /**
     * Parse and validate a spreadsheet without writing anything.
     *
     * @return array{
     *   new: array<int, array{row:int, data:array<string,mixed>}>,
     *   duplicates: array<int, array{row:int, barcode:string, existing:string}>,
     *   errors: array<int, array{row:int, reason:string}>,
     *   total: int
     * }
     */
    public function analyse(string $absolutePath): array
    {
        $rows = $this->readRows($absolutePath);

        $report = ['new' => [], 'duplicates' => [], 'errors' => [], 'total' => count($rows)];

        // Name-keyed lookups, loaded once. A 5000-row file would otherwise be
        // 5000 category queries.
        $categories = Category::pluck('id', 'name')->mapWithKeys(
            fn ($id, $name) => [mb_strtolower(trim($name)) => $id]
        );
        $taxes = Tax::pluck('id', 'name')->mapWithKeys(
            fn ($id, $name) => [mb_strtolower(trim($name)) => $id]
        );

        // Barcodes and SKUs claimed by earlier rows of *this same file*. The
        // unique validation rules only see the database, so without this a file
        // that repeats a barcode twice passes preview and fails at INSERT.
        $seenBarcodes = [];
        $seenSkus = [];

        foreach ($rows as $index => $raw) {
            // +2: row 1 is the heading row, and spreadsheet rows are 1-indexed.
            $lineNo = $index + 2;

            $data = $this->normalise($raw);

            if ($data === null) {
                continue; // blank row — a trailing empty line is not an error
            }

            $categoryKey = mb_strtolower((string) ($data['category'] ?? ''));
            if ($categoryKey !== '' && isset($categories[$categoryKey])) {
                $data['category_id'] = $categories[$categoryKey];
            }

            $taxKey = mb_strtolower((string) ($data['tax'] ?? ''));
            if ($taxKey !== '' && isset($taxes[$taxKey])) {
                $data['tax_id'] = $taxes[$taxKey];
            }

            if ($categoryKey !== '' && !isset($data['category_id'])) {
                $report['errors'][] = ['row' => $lineNo, 'reason' => "Unknown category '{$data['category']}' — create it first."];
                continue;
            }
            if ($taxKey !== '' && !isset($data['tax_id'])) {
                $report['errors'][] = ['row' => $lineNo, 'reason' => "Unknown tax '{$data['tax']}'."];
                continue;
            }

            unset($data['category'], $data['tax']);

            $validator = Validator::make($data, $this->rules(), [], $this->attributeNames());
            if ($validator->fails()) {
                $report['errors'][] = [
                    'row' => $lineNo,
                    'reason' => implode(' ', $validator->errors()->all()),
                ];
                continue;
            }

            $barcode = (string) ($data['barcode'] ?? '');
            if ($barcode !== '') {
                if (isset($seenBarcodes[$barcode])) {
                    $report['duplicates'][] = [
                        'row' => $lineNo,
                        'barcode' => $barcode,
                        'existing' => 'row ' . $seenBarcodes[$barcode] . ' of this file',
                    ];
                    continue;
                }

                if ($existing = $this->barcodes->findByBarcode($barcode)) {
                    $report['duplicates'][] = [
                        'row' => $lineNo,
                        'barcode' => $barcode,
                        'existing' => $existing->name,
                    ];
                    continue;
                }

                $seenBarcodes[$barcode] = $lineNo;
            }

            $sku = (string) ($data['sku'] ?? '');
            if ($sku !== '') {
                if (isset($seenSkus[$sku])) {
                    $report['errors'][] = ['row' => $lineNo, 'reason' => "SKU '{$sku}' is already used on row {$seenSkus[$sku]} of this file."];
                    continue;
                }
                $seenSkus[$sku] = $lineNo;
            }

            $report['new'][] = ['row' => $lineNo, 'data' => $validator->validated()];
        }

        return $report;
    }

    /**
     * Insert the rows `analyse()` classified as new. All-or-nothing: a file with
     * any error row is refused outright rather than half-imported.
     *
     * @return int products created
     */
    public function commit(array $report, int $userId): int
    {
        if ($report['errors'] !== []) {
            throw new \RuntimeException('Refusing to import a file with row errors.');
        }

        if ($report['new'] === []) {
            return 0;
        }

        return DB::transaction(function () use ($report, $userId) {
            $created = 0;

            foreach ($report['new'] as $entry) {
                $data = $entry['data'];

                if (empty($data['barcode'])) {
                    $data['barcode'] = $this->barcodes->generateUniqueEan13();
                }

                $data['created_by'] = $userId;

                Product::create($data);
                $created++;
            }

            return $created;
        });
    }

    /** The template file a user downloads, as rows (heading + one example). */
    public function templateRows(): array
    {
        return [
            self::COLUMNS,
            [
                'Coca Cola 1L', 'كوكا كولا ١ لتر', 'CC-1L', '6281000000017', 'Beverages', 'VAT 11%',
                '1.50', '1.10', '1.30', '1.40',
                '', '0',
                '24', '6', '120', 'pcs', 'A1-3',
                'simple', '1', '1', '1', '1',
                'Example row — delete before importing.',
            ],
        ];
    }

    /**
     * Row rules, borrowed wholesale from the product form so an import can never
     * accept something the form would reject. `image`/`remove_image` are the only
     * form-only fields.
     */
    private function rules(): array
    {
        $rules = Arr::except((new StoreProductRequest)->rules(), ['image', 'remove_image']);

        // The form's unique-barcode rule would turn an already-stocked barcode
        // into a row *error*, which aborts the whole file. An import treats it
        // as a duplicate instead: reported, skipped, rest of the file still
        // imports. Uniqueness is still enforced — see the findByBarcode() and
        // $seenBarcodes checks in analyse().
        $rules['barcode'] = ['nullable', 'string', 'max:80'];

        return $rules;
    }

    /** Column names as the spreadsheet spells them, for readable row errors. */
    private function attributeNames(): array
    {
        return ['category_id' => 'category', 'tax_id' => 'tax'];
    }

    /** @return array<int, array<string, mixed>> */
    private function readRows(string $absolutePath): array
    {
        $sheets = Excel::toArray(new class implements WithHeadingRow {}, $absolutePath);
        $rows = $sheets[0] ?? [];

        if (count($rows) > self::MAX_ROWS) {
            throw new \RuntimeException('File has ' . count($rows) . ' rows; the limit is ' . self::MAX_ROWS . '.');
        }

        $headings = array_keys($rows[0] ?? []);
        $missing = array_diff(self::REQUIRED_COLUMNS, $headings);

        if ($rows === [] || $missing !== []) {
            throw new \RuntimeException(
                $rows === []
                    ? 'The file has no data rows.'
                    : 'Missing required column(s): ' . implode(', ', $missing) . '. Download the template.'
            );
        }

        return $rows;
    }

    /**
     * Spreadsheet cell -> product attribute. Returns null for a wholly blank row.
     *
     * Blank optional fields are dropped rather than passed through as null: the
     * product table has NOT NULL columns with defaults (cost_usd, stock_qty,
     * min_stock, unit), so an empty cell has to mean "use the default", exactly
     * as StoreProductRequest::prepareForValidation does for a blank form input.
     */
    private function normalise(array $raw): ?array
    {
        $values = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $raw);

        if (collect($values)->every(fn ($v) => $v === null || $v === '')) {
            return null;
        }

        $data = [];
        foreach (self::COLUMNS as $column) {
            $value = $values[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $data[$column] = $this->cell($value);
        }

        foreach (['is_active', 'is_taxable', 'allow_discount', 'track_stock', 'force_lbp_price'] as $flag) {
            // Absent flag columns keep the create-form defaults rather than
            // silently importing every product as inactive.
            $default = $flag === 'force_lbp_price' ? false : true;
            $data[$flag] = array_key_exists($flag, $data)
                ? filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN)
                : $default;
        }

        return $data;
    }

    /**
     * Spreadsheet cell -> string. Everything downstream is a validation rule
     * that accepts a numeric string, so one uniform type keeps `string` rules
     * (name, barcode, sku) from tripping over cells the reader typed as numbers.
     */
    private function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // A 13-digit barcode comes back as a float; (string) would render it
        // "1.0E+12" and store a barcode no scanner will ever produce.
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0F', $value);
        }

        return (string) $value;
    }
}
