<?php

namespace App\Http\Controllers;

use App\Http\Middleware\DemoGate;
use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Models\PrintTemplate;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\BackupService;
use App\Services\CurrencyService;
use App\Services\ReceiptPrintService;
use App\Services\ReceiptRenderer;
use App\Support\Activation;
use App\Support\Demo;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class SettingController extends Controller
{
    /**
     * Settings a demo build keeps fixed: business identity, currency, and VAT.
     * Changing them would either undermine the "every launch is identical"
     * property or misrepresent the very numbers the demo is showing off.
     */
    public const DEMO_LOCKED_TABS = ['general', 'currency', 'tax'];

    private const DEMO_LOCKED_GROUPS = ['general', 'currency'];

    /**
     * The settings update() will write: key => cast, by the form (tab) that posts it. Only keys
     * listed here are ever written. `language` is a field of the General tab, so it lives there.
     */
    private const SETTING_TYPES = [
        'general' => [
            'business_name' => 'string', 'business_name_ar' => 'string', 'address' => 'string',
            'phone' => 'string', 'email' => 'string', 'tax_number' => 'string', 'language' => 'string',
        ],
        'currency' => [
            'exchange_rate' => 'float', 'lbp_rounding_step' => 'float',
            'base_currency' => 'string', 'secondary_currency' => 'string',
        ],
        'pos' => [
            'auto_print' => 'bool', 'require_shift' => 'bool', 'pos_display_cost' => 'bool',
            'cashier_can_change_price_tier' => 'bool',
            'max_cashier_discount_pct' => 'float', 'idle_timeout_min' => 'int',
        ],
        'receipt' => [
            'receipt_width' => 'int', 'paper_width_char' => 'int', 'receipt_language' => 'string',
            'receipt_header' => 'string', 'receipt_footer' => 'string',
            'receipt_format' => 'string', 'a4_printer_name' => 'string',
        ],
        'numbering' => [
            'receipt_prefix' => 'string', 'invoice_prefix' => 'string', 'po_prefix' => 'string', 'return_prefix' => 'string',
        ],
        'loyalty' => [
            'points_per_dollar' => 'float', 'redemption_rate' => 'int',
            'expiry_days' => 'int', 'bronze_threshold' => 'int', 'silver_threshold' => 'int', 'gold_threshold' => 'int',
        ],
        'backup' => [
            'backup_frequency' => 'string', 'retention_days' => 'int', 'cloud_enabled' => 'bool',
        ],
        'appearance' => [
            'rtl' => 'bool', 'dark_mode' => 'bool',
        ],
    ];

    /** What a tax-rate audit entry records (old / new values). */
    private const TAX_AUDITED = ['name', 'name_ar', 'rate', 'is_inclusive', 'is_default', 'is_active'];

    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab', 'general');
        $taxes = Tax::orderByDesc('is_default')->orderBy('name')->get();

        // Backup filenames are only listed for the accounts that can act on
        // them (see the role:super_admin group in routes/web.php).
        $isSuperAdmin = $request->user()->isSuperAdmin();
        if (in_array($tab, ['backup', 'permissions', 'plan'], true) && !$isSuperAdmin) {
            $tab = 'general';
        }

        // Tabs of features this install doesn't have. The routes behind them are
        // gated too (feature: middleware); this only stops a hand-typed ?tab=
        // rendering a form that cannot save.
        $featureTabs = [
            'loyalty' => 'loyalty',
            'expenses' => 'expenses',
            'receipt_template' => 'receipts.custom_templates',
        ];
        foreach ($featureTabs as $featureTab => $feature) {
            if ($tab === $featureTab && ! feature($feature)) {
                $tab = 'general';
            }
        }

        // Locked: the Plan tab is all ActivationGate lets a super_admin reach, so
        // don't render the other tabs (backup file names, business data) around it.
        if (Activation::isLocked()) {
            $tab = 'plan';
        }

        // A demo resets to a fixed baseline on every launch, so the settings
        // that define that baseline are not editable in it (see update() and
        // the demo:blocked tax routes). Land somewhere that still works.
        if (Demo::enabled() && in_array($tab, self::DEMO_LOCKED_TABS, true)) {
            $tab = 'pos';
        }
        $backups = $isSuperAdmin ? $this->backups->listBackups() : [];

        $all = Setting::all()->keyBy('key')->map(fn ($s) => $s->value)->toArray();

        $receiptTemplate = feature('receipts.custom_templates')
            ? PrintTemplate::where('type', 'receipt')->orderByDesc('is_default')->first()
            : null;

        $expenseCategories = $tab === 'expenses'
            ? ExpenseCategory::withCount(['expenses' => fn ($q) => $q->withTrashed()])->orderBy('kind')->orderBy('sort_order')->get()
            : collect();

        // The installed printers, for the A4 printer dropdown (empty outside the desktop app).
        $printers = $tab === 'receipt' ? app(ReceiptPrintService::class)->printers() : [];

        return view('settings.index', compact('tab', 'taxes', 'backups', 'all', 'receiptTemplate', 'expenseCategories', 'printers'));
    }

    public function update(Request $request): RedirectResponse
    {
        $group = $request->input('group');
        $data = $request->validate([
            'group' => 'required|in:general,currency,pos,receipt,numbering,loyalty,backup,appearance',
            'settings' => 'required|array',
        ]);
        // Separate call on purpose: rules for nested keys would make validated() drop every other setting.
        $request->validate([
            'settings.receipt_format' => ['nullable', \Illuminate\Validation\Rule::in(ReceiptPrintService::FORMATS)],
            'settings.a4_printer_name' => 'nullable|string|max:255',
        ] + $this->numericSettingRules(), [
            // A rate of 0 prices everything at 0 LBP and counts every LBP note as $0 tendered.
            'settings.exchange_rate.required' => __('messages.exchange_rate_invalid'),
            'settings.exchange_rate.numeric' => __('messages.exchange_rate_invalid'),
            'settings.exchange_rate.gt' => __('messages.exchange_rate_invalid'),
        ], $this->numericSettingLabels());

        // The same lock, by key: which keys get written is decided by name, not by the posted
        // group, so `group=pos` carrying a key of a locked group must be refused too.
        $demoLockedKeys = array_merge(...array_values(array_intersect_key(self::SETTING_TYPES, array_flip(self::DEMO_LOCKED_GROUPS))));

        if (Demo::enabled() && (
            in_array($data['group'], self::DEMO_LOCKED_GROUPS, true)
            || array_intersect_key($data['settings'], $demoLockedKeys)
        )) {
            return back()->withErrors(['demo' => DemoGate::refusal()]);
        }

        // Loyalty settings are only meaningful on a tier that has loyalty.
        if ($data['group'] === 'loyalty' && ! feature('loyalty')) {
            return back()->withErrors(['plan' => __('plan.feature_locked')]);
        }

        $typeMap = array_merge(...array_values(self::SETTING_TYPES));

        foreach ($data['settings'] as $key => $value) {
            // Only keys this form is known to carry. This route is open to the
            // client's own admin, so an unlisted key must never be written: it is
            // how `tier`, `activation_status` or `installed_at` would otherwise be
            // set straight past the super_admin-only Plan tab.
            if (! isset($typeMap[$key])) {
                continue;
            }

            $type = $typeMap[$key];
            $stored = match ($type) {
                'bool' => $request->boolean("settings.$key") ? '1' : '0',
                default => (string) $value,
            };
            Setting::set($key, $stored, $group, $type);
        }

        // Reset bool keys that weren't submitted (unchecked checkboxes)
        if ($group === 'pos') {
            foreach (['auto_print', 'require_shift', 'pos_display_cost', 'cashier_can_change_price_tier'] as $bk) {
                if (!isset($data['settings'][$bk])) {
                    Setting::set($bk, '0', 'pos', 'bool');
                }
            }
        }

        AuditLog::record($request->user()->id, 'settings_update', null, null, null, ['group' => $group]);
        Cache::flush();

        return redirect()->route('settings.index', ['tab' => $group])->with('success', __('messages.settings_saved'));
    }

    /**
     * Server-side limits for the settings that hold a number or a document prefix: the ranges
     * the form advertises, and an exchange rate that must be above zero. Every rule is
     * `sometimes`: a tab only posts its own keys. [rules, label lang key]
     */
    private const NUMERIC_SETTINGS = [
        'exchange_rate' => ['required|numeric|gt:0', 'settings.exchange_rate'],
        'lbp_rounding_step' => ['required|numeric|min:0', 'settings.lbp_rounding'],
        'max_cashier_discount_pct' => ['nullable|numeric|min:0|max:100', 'settings.max_cashier_discount'],
        'idle_timeout_min' => ['nullable|integer|min:1|max:600', 'settings.idle_logout'],
        'receipt_width' => ['required|in:58,80', 'settings.paper_width'],
        'paper_width_char' => ['nullable|integer|min:20|max:80', 'settings.chars_per_line'],
        'points_per_dollar' => ['nullable|numeric|min:0', 'settings.points_per_dollar'],
        'redemption_rate' => ['required|integer|min:1', 'settings.redemption_rate'],
        'silver_threshold' => ['nullable|integer|min:0', 'settings.silver_threshold'],
        'gold_threshold' => ['nullable|integer|min:0', 'settings.gold_threshold'],
        'expiry_days' => ['nullable|integer|min:0', 'settings.points_expire'],
        'retention_days' => ['nullable|integer|min:0', 'settings.retention'],
        'receipt_prefix' => ['nullable|string|max:20', 'settings.prefixes.receipt_prefix'],
        'invoice_prefix' => ['nullable|string|max:20', 'settings.prefixes.invoice_prefix'],
        'po_prefix' => ['nullable|string|max:20', 'settings.prefixes.po_prefix'],
        'return_prefix' => ['nullable|string|max:20', 'settings.prefixes.return_prefix'],
    ];

    /** @return array<string,string> */
    private function numericSettingRules(): array
    {
        $rules = [];
        foreach (self::NUMERIC_SETTINGS as $key => [$rule]) {
            $rules["settings.$key"] = "sometimes|$rule";
        }

        return $rules;
    }

    /** @return array<string,string> */
    private function numericSettingLabels(): array
    {
        $labels = [];
        foreach (self::NUMERIC_SETTINGS as $key => [, $label]) {
            $labels["settings.$key"] = __($label);
        }

        return $labels;
    }

    /**
     * Render the real thermal receipt view with an in-memory sample sale, using
     * the Receipt tab's typed-but-unsaved width/header/footer. Pure render:
     * nothing is persisted, no receipt number is consumed, nothing is audited.
     */
    public function testPrintReceipt(Request $request, CurrencyService $currency): View|Response
    {
        $input = fn (string $key, $default) => $request->input("settings.$key", Setting::get($key, $default));
        $width = (int) $input('receipt_width', 80);

        // Mirrors SaleService::totalsFromCart with the default exclusive 11% VAT:
        // subtotal = gross, discount = line discounts, line_total = net + tax.
        $lines = [
            ['Sample Item A', 2, 4.50, 0.00, 0.11, 'صنف تجريبي أ'],
            ['Sample Item B — a longer product name to test wrapping', 1, 12.00, 2.00, 0.11, 'صنف تجريبي ب — اسم منتج أطول لاختبار التفاف السطر'],
            ['Sample Item C (tax exempt)', 0.75, 6.00, 0.00, 0.0, 'صنف تجريبي ج (معفى من الضريبة)'],
        ];
        $items = collect($lines)->map(function ($l) {
            [$name, $qty, $price, $disc, $rate, $nameAr] = $l;
            $net = $qty * $price - $disc;
            $tax = round($net * $rate, 4);

            $item = new SaleItem([
                'product_name' => $name, 'qty' => $qty, 'unit_price_usd' => $price,
                'discount_amount_usd' => $disc, 'tax_rate' => $rate, 'tax_amount_usd' => $tax,
                'line_total_usd' => $net + $tax, 'is_taxable' => $rate > 0,
            ]);
            // In-memory only: lets the sample show the Arabic name line in bilingual/Arabic mode.
            $item->setRelation('product', new \App\Models\Product(['name' => $name, 'name_ar' => $nameAr]));

            return $item;
        });

        $total = round($items->sum('line_total_usd'), 4);
        // Give $4 back in USD and the rest in LBP so both change rows print.
        $change = $currency->calculateChange($total, 30.0, 0.0, 4.0);

        $sale = new Sale([
            'receipt_number' => 'TEST-' . now()->format('His'),
            'subtotal_usd' => $items->sum(fn ($i) => $i->qty * $i->unit_price_usd),
            'discount_amount_usd' => $items->sum('discount_amount_usd'),
            'tax_amount_usd' => $items->sum('tax_amount_usd'),
            'total_usd' => $total,
            'total_lbp' => $currency->usdToLbp($total),
            'exchange_rate' => $currency->getRate(),
            'payment_method' => Sale::METHOD_CASH_USD,
            'amount_tendered_usd' => 30.0,
            'change_usd' => $change['change_usd'],
            'change_lbp' => $change['change_lbp'],
        ]);
        $sale->created_at = now();
        $sale->setRelation('items', $items);
        $sale->setRelation('user', $request->user());
        $sale->setRelation('customer', null);

        $data = [
            'sale' => $sale,
            'business' => [
                'name' => Setting::get('business_name', 'LebaSouk'),
                'name_ar' => Setting::get('business_name_ar'),
                'address' => Setting::get('address'),
                'phone' => Setting::get('phone'),
                'tax_number' => Setting::get('tax_number'),
            ],
            'width' => in_array($width, [58, 80], true) ? $width : 80,
            'header' => (string) $input('receipt_header', ''),
            'footer' => (string) $input('receipt_footer', ''),
            'receiptLang' => (string) $input('receipt_language', 'bilingual'),
            'autoPrint' => true,
            'isTest' => true,
        ];

        // Whatever a real sale would print, this has to print too — checking the
        // layout is the entire point of the button. On Premium that means the
        // saved template, stamped TEST PRINT the same way the built-in view is.
        if ($template = ReceiptRenderer::active()) {
            return response(ReceiptRenderer::render($template, $sale, $data));
        }

        return view('receipts.thermal', $data);
    }

    /**
     * The two bulk-upload toggles. Super-admin only (see the role:super_admin
     * group in routes/web.php) and audited one entry per role whose value
     * actually moved — "who turned this on" is the whole point of the switch.
     */
    public function updatePermissions(Request $request): RedirectResponse
    {
        $roles = [
            \App\Models\User::ROLE_ADMIN => 'bulk_upload_enabled_admin',
            \App\Models\User::ROLE_STOCK => 'bulk_upload_enabled_stock',
        ];

        foreach ($roles as $role => $key) {
            $was = (bool) Setting::get($key, false);
            $now = $request->boolean("settings.{$key}");

            if ($was === $now) {
                continue;
            }

            Setting::set($key, $now ? '1' : '0', 'permissions', 'bool');

            AuditLog::record($request->user()->id, 'permission_change', 'Setting', null,
                [$key => $was],
                [$key => $now, 'role' => $role],
            );
        }

        return redirect()->route('settings.index', ['tab' => 'permissions'])
            ->with('success', __('messages.permissions_saved'));
    }

    /**
     * Which tier this install is licensed for. Super-admin only, by the same
     * route group that guards Backup and Permissions — a client's own admin
     * raising their own plan would make the whole thing decorative.
     */
    public function updatePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tier' => ['required', Rule::in(License::tiers())],
        ]);

        License::setTier($data['tier'], $request->user()->id);

        return redirect()->route('settings.index', ['tab' => 'plan'])
            ->with('success', __('messages.plan_updated', ['plan' => __('plan.tier.'.$data['tier'])]));
    }

    /**
     * Premium's receipt template. Stored as plain HTML with {{ placeholders }}
     * — deliberately not Blade, which would make this textarea an arbitrary-PHP
     * execution sink for anyone with the Settings tab. See ReceiptRenderer.
     */
    public function updateReceiptTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_html' => 'nullable|string|max:65535',
            'paper_size' => 'required|in:58mm,80mm,A4,A5',
            'is_active' => 'nullable|boolean',
        ]);

        $template = PrintTemplate::where('type', 'receipt')->orderByDesc('is_default')->first();
        $old = $template?->only(['template_html', 'paper_size', 'is_active']);

        $attributes = [
            'name' => $template->name ?? 'Receipt',
            'type' => 'receipt',
            'template_html' => (string) $data['template_html'],
            'paper_size' => $data['paper_size'],
            'is_default' => true,
            'is_active' => $request->boolean('is_active'),
        ];

        $template = $template ? tap($template)->update($attributes) : PrintTemplate::create($attributes);

        AuditLog::record($request->user()->id, $old ? 'update' : 'create', PrintTemplate::class, $template->id, $old, $attributes);

        return redirect()->route('settings.index', ['tab' => 'receipt_template'])
            ->with('success', __('messages.receipt_template_saved'));
    }

    // === Tax inline CRUD ===
    public function storeTax(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:120',
            'rate' => 'required|numeric|min:0|max:1',
            'is_inclusive' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ]);

        if ($request->boolean('is_default')) {
            Tax::query()->update(['is_default' => false]);
        }

        $tax = Tax::create([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'rate' => $data['rate'],
            'is_inclusive' => $request->boolean('is_inclusive'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => true,
        ]);

        AuditLog::record($request->user()->id, 'create', Tax::class, $tax->id, null, $tax->only(self::TAX_AUDITED));

        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', __('messages.tax_added'));
    }

    public function updateTax(Request $request, Tax $tax): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:120',
            'rate' => 'required|numeric|min:0|max:1',
            'is_inclusive' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        // An inactive rate is not offered on the product form, so as the default it would make every
        // new product tax-free. The owner picks another default first (or unticks Default here).
        if ($request->boolean('is_default') && ! $request->boolean('is_active', true)) {
            return back()->withErrors(['is_active' => __('messages.tax_default_inactive')]);
        }

        if ($request->boolean('is_default')) {
            Tax::where('id', '!=', $tax->id)->update(['is_default' => false]);
        }

        $old = $tax->only(self::TAX_AUDITED);

        $tax->update([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'rate' => $data['rate'],
            'is_inclusive' => $request->boolean('is_inclusive'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLog::record($request->user()->id, 'update', Tax::class, $tax->id, $old, $tax->only(self::TAX_AUDITED));

        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', __('messages.tax_updated'));
    }

    public function destroyTax(Request $request, Tax $tax): RedirectResponse
    {
        if (\App\Models\Product::where('tax_id', $tax->id)->exists()) {
            return back()->withErrors(['delete' => __('messages.tax_in_use')]);
        }
        $old = $tax->only(self::TAX_AUDITED);
        $tax->delete();
        AuditLog::record($request->user()->id, 'delete', Tax::class, $tax->id, $old, null);
        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', __('messages.tax_deleted'));
    }

    // === Backups ===
    public function backupNow(): RedirectResponse|BinaryFileResponse
    {
        try {
            $file = $this->backups->createBackup();
            return response()->download($file)->deleteFileAfterSend(false);
        } catch (\Throwable $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }
    }

    public function backupDownload(string $filename): BinaryFileResponse|RedirectResponse
    {
        $path = $this->backups->backupPath() . DIRECTORY_SEPARATOR . basename($filename);
        if (!file_exists($path)) return back()->withErrors(['backup' => __('messages.file_not_found')]);
        return response()->download($path);
    }

    public function backupRestore(string $filename): RedirectResponse
    {
        try {
            $this->backups->restore($filename);
        } catch (\Throwable $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }
        return redirect()->route('settings.index', ['tab' => 'backup'])->with('success', __('messages.backup_restored'));
    }

    public function backupDelete(string $filename): RedirectResponse
    {
        $this->backups->delete($filename);
        return redirect()->route('settings.index', ['tab' => 'backup'])->with('success', __('messages.backup_deleted'));
    }
}
