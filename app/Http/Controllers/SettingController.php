<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettingController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab', 'general');
        $taxes = Tax::orderByDesc('is_default')->orderBy('name')->get();
        $backups = $this->backups->listBackups();

        $all = Setting::all()->keyBy('key')->map(fn ($s) => $s->value)->toArray();

        return view('settings.index', compact('tab', 'taxes', 'backups', 'all'));
    }

    public function update(Request $request): RedirectResponse
    {
        $group = $request->input('group');
        $data = $request->validate([
            'group' => 'required|in:general,currency,pos,receipt,numbering,loyalty,backup,appearance',
            'settings' => 'required|array',
        ]);

        $typeMap = [
            // general
            'business_name' => 'string', 'business_name_ar' => 'string', 'address' => 'string',
            'phone' => 'string', 'email' => 'string', 'tax_number' => 'string',
            // currency
            'exchange_rate' => 'float', 'lbp_rounding_step' => 'float',
            'base_currency' => 'string', 'secondary_currency' => 'string',
            // pos
            'auto_print' => 'bool', 'require_shift' => 'bool', 'pos_display_cost' => 'bool',
            'max_cashier_discount_pct' => 'float', 'idle_timeout_min' => 'int',
            // receipt
            'receipt_width' => 'int', 'paper_width_char' => 'int',
            'receipt_header' => 'string', 'receipt_footer' => 'string',
            // numbering
            'receipt_prefix' => 'string', 'invoice_prefix' => 'string', 'po_prefix' => 'string', 'return_prefix' => 'string',
            // loyalty
            'points_per_dollar' => 'float', 'redemption_rate' => 'int',
            'expiry_days' => 'int', 'bronze_threshold' => 'int', 'silver_threshold' => 'int', 'gold_threshold' => 'int',
            // backup
            'backup_frequency' => 'string', 'retention_days' => 'int', 'cloud_enabled' => 'bool',
            // appearance
            'language' => 'string', 'rtl' => 'bool', 'dark_mode' => 'bool',
        ];

        foreach ($data['settings'] as $key => $value) {
            $type = $typeMap[$key] ?? 'string';
            $stored = match ($type) {
                'bool' => $request->boolean("settings.$key") ? '1' : '0',
                default => (string) $value,
            };
            Setting::set($key, $stored, $group, $type);
        }

        // Reset bool keys that weren't submitted (unchecked checkboxes)
        if ($group === 'pos') {
            foreach (['auto_print', 'require_shift', 'pos_display_cost'] as $bk) {
                if (!isset($data['settings'][$bk])) {
                    Setting::set($bk, '0', 'pos', 'bool');
                }
            }
        }

        AuditLog::record($request->user()->id, 'settings_update', null, null, null, ['group' => $group]);
        Cache::flush();

        return redirect()->route('settings.index', ['tab' => $group])->with('success', 'Settings saved.');
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

        Tax::create([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'rate' => $data['rate'],
            'is_inclusive' => $request->boolean('is_inclusive'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => true,
        ]);

        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', 'Tax added.');
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

        if ($request->boolean('is_default')) {
            Tax::where('id', '!=', $tax->id)->update(['is_default' => false]);
        }

        $tax->update([
            'name' => $data['name'],
            'name_ar' => $data['name_ar'] ?? null,
            'rate' => $data['rate'],
            'is_inclusive' => $request->boolean('is_inclusive'),
            'is_default' => $request->boolean('is_default'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', 'Tax updated.');
    }

    public function destroyTax(Tax $tax): RedirectResponse
    {
        if (\App\Models\Product::where('tax_id', $tax->id)->exists()) {
            return back()->withErrors(['delete' => 'Tax is in use by products. Reassign first.']);
        }
        $tax->delete();
        return redirect()->route('settings.index', ['tab' => 'tax'])->with('success', 'Tax deleted.');
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
        if (!file_exists($path)) return back()->withErrors(['backup' => 'File not found.']);
        return response()->download($path);
    }

    public function backupRestore(string $filename): RedirectResponse
    {
        try {
            $this->backups->restore($filename);
        } catch (\Throwable $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }
        return redirect()->route('settings.index', ['tab' => 'backup'])->with('success', 'Backup restored. You may need to log in again.');
    }

    public function backupDelete(string $filename): RedirectResponse
    {
        $this->backups->delete($filename);
        return redirect()->route('settings.index', ['tab' => 'backup'])->with('success', 'Backup deleted.');
    }
}
