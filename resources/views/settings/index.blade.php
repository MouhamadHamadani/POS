@php
    $tabs = [
        'general' => __('settings.tabs.general'),
        'currency' => __('settings.tabs.currency'),
        'tax' => __('settings.tabs.tax'),
        'pos' => __('settings.tabs.pos'),
        'receipt' => __('settings.tabs.receipt'),
        'numbering' => __('settings.tabs.numbering'),
        'loyalty' => __('settings.tabs.loyalty'),
    ];

    // Feature areas this install's tier doesn't include have no settings to show.
    if (! \App\Support\License::allows('loyalty')) {
        unset($tabs['loyalty']);
    }
    if (\App\Support\License::allows('receipts.custom_templates')) {
        $tabs['receipt_template'] = __('settings.tabs.receipt_template');
    }

    // Backups, the bulk-upload switches and the plan are vendor/owner territory
    // — see the role:super_admin route group.
    if (auth()->user()?->isSuperAdmin()) {
        $tabs['permissions'] = __('settings.tabs.permissions');
        $tabs['backup'] = __('settings.tabs.backup');
        $tabs['plan'] = __('settings.tabs.plan');
    }

    // A locked install shows only the Plan tab — the rest is unreachable anyway.
    if (\App\Support\Activation::isLocked()) {
        $tabs = array_intersect_key($tabs, ['plan' => true]);
    }

    // Business identity, currency and VAT are part of the demo baseline and are
    // refused server-side in a demo build (SettingController::DEMO_LOCKED_TABS);
    // don't offer a form that cannot save.
    if (\App\Support\Demo::enabled()) {
        $tabs = array_diff_key($tabs, array_flip(\App\Http\Controllers\SettingController::DEMO_LOCKED_TABS));
    }
    $get = fn($key, $default = '') => $all[$key] ?? $default;
@endphp

<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('Settings') }}</h2></x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-card overflow-hidden">
            <div class="flex border-b text-sm overflow-x-auto">
                @foreach ($tabs as $k => $label)
                    <a href="?tab={{ $k }}"
                       class="px-4 py-3 whitespace-nowrap {{ $tab === $k ? 'border-b-2 border-brand-700 text-brand-700 font-medium' : 'text-gray-600 hover:text-gray-900' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="p-6">
                @if ($tab === 'general')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="general">
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">{{ __('settings.business_name') }} *</label>
                            <input type="text" name="settings[business_name]" dir="auto" value="{{ $get('business_name') }}" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.business_name_ar') }}</label>
                            <input type="text" name="settings[business_name_ar]" value="{{ $get('business_name_ar') }}" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.vat_number') }}</label>
                            <input type="text" name="settings[tax_number]" dir="ltr" value="{{ $get('tax_number') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('common.phone') }}</label>
                            <input type="text" name="settings[phone]" dir="ltr" value="{{ $get('phone') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('users.email') }}</label>
                            <input type="email" name="settings[email]" dir="ltr" value="{{ $get('email') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">{{ __('common.address') }}</label>
                            <textarea name="settings[address]" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('address') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.store_language') }}</label>
                            <select name="settings[language]" class="w-full border-gray-300 rounded text-sm">
                                <option value="en" @selected($get('language', 'en') === 'en')>English</option>
                                <option value="ar" @selected($get('language', 'en') === 'ar')>العربية</option>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">{{ __('settings.store_language_help') }}</div>
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'currency')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="currency">
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.exchange_rate') }} *</label>
                            <input type="number" step="0.01" min="0" name="settings[exchange_rate]" dir="ltr" value="{{ $get('exchange_rate') }}" required class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs text-gray-500 mt-1">{{ __('settings.exchange_rate_help') }}</div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.lbp_rounding') }}</label>
                            <select name="settings[lbp_rounding_step]" class="w-full border-gray-300 rounded text-sm">
                                @foreach ([100, 500, 1000, 5000] as $step)
                                    <option value="{{ $step }}" @selected((int) $get('lbp_rounding_step') === $step)>{{ __('settings.lbp_step', ['amount' => number_format($step)]) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'tax')
                    <h3 class="font-semibold mb-3 text-sm">{{ __('settings.tax_rates') }}</h3>
                    <table class="min-w-full text-sm mb-4">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr><th class="text-start py-2">{{ __('common.name') }}</th><th class="text-start">{{ __('categories.arabic') }}</th><th class="text-end">{{ __('settings.rate') }}</th><th class="text-center">{{ __('settings.inclusive') }}</th><th class="text-center">{{ __('settings.default') }}</th><th class="text-center">{{ __('common.active') }}</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y">
                        @foreach ($taxes as $t)
                            <tr>
                                <form method="POST" action="{{ route('settings.tax.update', $t) }}">
                                    @csrf @method('PUT')
                                    <td class="py-2"><input name="name" dir="auto" value="{{ $t->name }}" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2"><input name="name_ar" value="{{ $t->name_ar }}" dir="rtl" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2 w-24"><input type="number" step="0.0001" min="0" max="1" name="rate" dir="ltr" value="{{ $t->rate }}" class="w-full text-end border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_inclusive" value="1" @checked($t->is_inclusive)></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_default" value="1" @checked($t->is_default)></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_active" value="1" @checked($t->is_active)></td>
                                    <td class="py-2 text-end"><button class="text-xs text-brand-600">{{ __('common.save') }}</button>
                                </form>
                                    <form method="POST" action="{{ route('settings.tax.destroy', $t) }}" class="inline ms-2" onsubmit="return confirm({{ Js::from(__('settings.confirm_delete_tax', ['name' => $t->name])) }})">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600" title="{{ __('common.delete') }}">×</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <form method="POST" action="{{ route('settings.tax.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm border-t pt-4">
                        @csrf
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">{{ __('common.name') }} *</label>
                            <input type="text" name="name" dir="auto" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('categories.arabic') }}</label>
                            <input type="text" name="name_ar" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.rate_example') }}</label>
                            <input type="number" step="0.0001" min="0" max="1" name="rate" dir="ltr" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="flex flex-col text-xs gap-1">
                            <label><input type="checkbox" name="is_inclusive" value="1"> {{ __('settings.inclusive') }}</label>
                            <label><input type="checkbox" name="is_default" value="1"> {{ __('settings.default') }}</label>
                        </div>
                        <button class="px-3 py-1.5 bg-brand-700 text-white rounded text-sm">{{ __('settings.add_tax') }}</button>
                    </form>

                @elseif ($tab === 'pos')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="pos">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[auto_print]" value="1" @checked($get('auto_print'))>
                            {{ __('settings.auto_print') }}
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[require_shift]" value="1" @checked($get('require_shift'))>
                            {{ __('settings.require_shift') }}
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[pos_display_cost]" value="1" @checked($get('pos_display_cost'))>
                            {{ __('settings.show_cost') }}
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[cashier_can_change_price_tier]" value="1" @checked($get('cashier_can_change_price_tier', true))>
                            {{ __('pos.settings.cashier_can_change_price_tier') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.max_cashier_discount') }}</label>
                            <input type="number" step="0.01" min="0" max="100" name="settings[max_cashier_discount_pct]" dir="ltr" value="{{ $get('max_cashier_discount_pct') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.idle_logout') }}</label>
                            <input type="number" min="1" max="600" name="settings[idle_timeout_min]" dir="ltr" value="{{ $get('idle_timeout_min') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'receipt')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="receipt">
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.paper_width') }}</label>
                            <select name="settings[receipt_width]" class="w-full border-gray-300 rounded text-sm">
                                <option value="58" @selected((int) $get('receipt_width') === 58)>58 mm</option>
                                <option value="80" @selected((int) $get('receipt_width') === 80)>80 mm</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.chars_per_line') }}</label>
                            <input type="number" min="20" max="80" name="settings[paper_width_char]" dir="ltr" value="{{ $get('paper_width_char') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.receipt_language') }}</label>
                            <select name="settings[receipt_language]" class="w-full border-gray-300 rounded text-sm">
                                @foreach (['bilingual', 'en', 'ar'] as $rl)
                                    <option value="{{ $rl }}" @selected($get('receipt_language', 'bilingual') === $rl)>{{ __("settings.receipt_language_options.$rl") }}</option>
                                @endforeach
                            </select>
                            <div class="text-xs text-gray-500 mt-1">{{ __('settings.receipt_language_help') }}</div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">{{ __('settings.receipt_header') }}</label>
                            <textarea name="settings[receipt_header]" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('receipt_header') }}</textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">{{ __('settings.receipt_footer') }}</label>
                            <textarea name="settings[receipt_footer]" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('receipt_footer') }}</textarea>
                        </div>
                        {{-- Save stays first: Enter in a field submits via the first submit button. --}}
                        <div class="md:col-span-2 flex justify-end gap-2">
                            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button>
                            <button type="submit" formaction="{{ route('settings.receipt.test-print') }}" formtarget="_blank" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">{{ __('settings.print_test') }}</button>
                        </div>
                    </form>

                @elseif ($tab === 'numbering')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="numbering">
                        @foreach (['receipt_prefix', 'invoice_prefix', 'po_prefix', 'return_prefix'] as $k)
                            <div>
                                <label class="block text-xs text-gray-500">{{ __("settings.prefixes.$k") }}</label>
                                <input type="text" name="settings[{{ $k }}]" dir="ltr" value="{{ $get($k) }}" class="w-full border-gray-300 rounded text-sm" />
                            </div>
                        @endforeach
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'loyalty')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="loyalty">
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.points_per_dollar') }}</label>
                            <input type="number" step="0.01" min="0" name="settings[points_per_dollar]" dir="ltr" value="{{ $get('points_per_dollar') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.redemption_rate') }}</label>
                            <input type="number" min="1" name="settings[redemption_rate]" dir="ltr" value="{{ $get('redemption_rate') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.silver_threshold') }}</label>
                            <input type="number" min="0" name="settings[silver_threshold]" dir="ltr" value="{{ $get('silver_threshold') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.gold_threshold') }}</label>
                            <input type="number" min="0" name="settings[gold_threshold]" dir="ltr" value="{{ $get('gold_threshold') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.points_expire') }}</label>
                            <input type="number" min="0" name="settings[expiry_days]" dir="ltr" value="{{ $get('expiry_days') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'permissions')
                    <form method="POST" action="{{ route('settings.permissions.update') }}" class="space-y-4 text-sm max-w-xl">
                        @csrf
                        <div>
                            <h3 class="font-semibold">{{ __('settings.bulk_upload') }}</h3>
                            <p class="text-xs text-gray-500 mt-1">{{ __('settings.bulk_upload_help') }}</p>
                        </div>

                        @foreach (['bulk_upload_enabled_admin', 'bulk_upload_enabled_stock'] as $key)
                            <label class="flex items-start gap-3 border rounded p-3">
                                <input type="checkbox" name="settings[{{ $key }}]" value="1"
                                       @checked($get($key) === '1')
                                       class="mt-0.5 rounded border-gray-300" />
                                <span>{{ __("settings.permission_labels.$key") }}</span>
                            </label>
                        @endforeach

                        <div class="flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'backup')
                    <div class="flex flex-wrap gap-3 items-center mb-4">
                        <form method="POST" action="{{ route('settings.backup.now') }}">
                            @csrf
                            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('settings.backup_now') }}</button>
                        </form>
                        <span class="text-xs text-gray-500">{{ __('settings.backup_help') }}</span>
                    </div>

                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end text-sm border-t pt-4 mb-4">
                        @csrf <input type="hidden" name="group" value="backup">
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.backup_frequency') }}</label>
                            <select name="settings[backup_frequency]" class="w-full border-gray-300 rounded text-sm">
                                @foreach (['off', 'daily', 'weekly'] as $f)
                                    <option value="{{ $f }}" @selected($get('backup_frequency') === $f)>{{ __("enums.backup_frequency.$f") }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.retention') }}</label>
                            <input type="number" min="0" name="settings[retention_days]" dir="ltr" value="{{ $get('retention_days') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <button class="px-4 py-2 bg-gray-800 text-white rounded text-sm">{{ __('settings.save_schedule') }}</button>
                    </form>

                    <h3 class="font-semibold mb-2 text-sm border-t pt-4">{{ __('settings.existing_backups') }}</h3>
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr><th class="text-start py-2">{{ __('settings.file') }}</th><th class="text-end">{{ __('settings.size') }}</th><th class="text-end">{{ __('dashboard.when') }}</th><th class="text-end">{{ __('common.actions') }}</th></tr>
                        </thead>
                        <tbody class="divide-y">
                        @forelse ($backups as $b)
                            <tr>
                                <td class="py-2 font-mono text-xs"><x-ltr>{{ $b['name'] }}</x-ltr></td>
                                <td class="text-end text-xs"><x-ltr>{{ $b['bytes'] }}</x-ltr></td>
                                <td class="text-end text-xs text-gray-500"><x-ltr>{{ date('Y-m-d H:i', $b['date']) }}</x-ltr></td>
                                <td class="text-end space-x-2 rtl:space-x-reverse whitespace-nowrap">
                                    <a href="{{ route('settings.backup.download', $b['name']) }}" class="text-xs text-brand-600 hover:underline">{{ __('settings.download') }}</a>
                                    <form method="POST" action="{{ route('settings.backup.restore', $b['name']) }}" class="inline"
                                          onsubmit="return confirm({{ Js::from(__('settings.confirm_restore')) }})">
                                        @csrf
                                        <button class="text-xs text-orange-600 hover:underline">{{ __('settings.restore') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('settings.backup.delete', $b['name']) }}" class="inline"
                                          onsubmit="return confirm({{ Js::from(__('settings.confirm_delete_backup')) }})">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">{{ __('common.delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">{{ __('settings.no_backups') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>

                @elseif ($tab === 'receipt_template')
                    {{-- Premium only. Plain HTML with token placeholders, not Blade
                         — see App\Services\ReceiptRenderer for why. --}}
                    <form method="POST" action="{{ route('settings.receipt-template.update') }}" class="space-y-4 text-sm">
                        @csrf
                        <p class="text-xs text-gray-500">
                            {{ __('settings.receipt_template.help') }}
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('settings.receipt_template.paper_size') }}</label>
                                <select name="paper_size" class="w-full border-gray-300 rounded text-sm">
                                    @foreach (['58mm', '80mm', 'A4', 'A5'] as $size)
                                        <option value="{{ $size }}" @selected(($receiptTemplate->paper_size ?? '80mm') === $size)>{{ $size }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="flex items-center gap-2 md:col-span-2 md:pt-5">
                                <input type="checkbox" name="is_active" value="1"
                                       @checked($receiptTemplate?->is_active) class="rounded border-gray-300" />
                                <span>{{ __('settings.receipt_template.use') }}</span>
                            </label>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('settings.receipt_template.html') }}</label>
                            <textarea name="template_html" rows="16" spellcheck="false"
                                      class="w-full border-gray-300 rounded text-xs font-mono">{{ $receiptTemplate->template_html ?? '' }}</textarea>
                        </div>
                        <div class="text-xs text-gray-500">
                            <div class="font-medium text-gray-700 mb-1">{{ __('settings.receipt_template.placeholders') }}</div>
                            <code class="break-words">
                                &#123;&#123;business_name&#125;&#125; &#123;&#123;business_name_ar&#125;&#125; &#123;&#123;address&#125;&#125;
                                &#123;&#123;phone&#125;&#125; &#123;&#123;tax_number&#125;&#125; &#123;&#123;header&#125;&#125; &#123;&#123;footer&#125;&#125;
                                &#123;&#123;receipt_number&#125;&#125; &#123;&#123;date&#125;&#125; &#123;&#123;cashier&#125;&#125; &#123;&#123;customer&#125;&#125;
                                &#123;&#123;subtotal&#125;&#125; &#123;&#123;discount&#125;&#125; &#123;&#123;tax&#125;&#125; &#123;&#123;total_usd&#125;&#125;
                                &#123;&#123;total_lbp&#125;&#125; &#123;&#123;payment_method&#125;&#125; &#123;&#123;change_usd&#125;&#125;
                                &#123;&#123;change_lbp&#125;&#125; &#123;&#123;loyalty_points_earned&#125;&#125;
                            </code>
                            <div class="mt-2">
                                {{ __('settings.receipt_template.repeat') }}
                                <code dir="ltr">&#123;&#123;#items&#125;&#125; &hellip; &#123;&#123;/items&#125;&#125;</code>, {{ __('settings.receipt_template.using') }}
                                <code dir="ltr">&#123;&#123;name&#125;&#125; &#123;&#123;qty&#125;&#125; &#123;&#123;unit_price&#125;&#125; &#123;&#123;discount&#125;&#125; &#123;&#123;line_total&#125;&#125;</code>.
                            </div>
                        </div>
                        <div class="flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('common.save') }}</button></div>
                    </form>

                @elseif ($tab === 'plan')
                    @php
                        $tier = \App\Support\License::tier();
                        $maxUsers = \App\Support\License::maxUsers();
                        $maxDevices = \App\Support\License::maxDevices();

                        $status = \App\Support\Activation::status();
                        $badge = ['trial' => 'bg-amber-100 text-amber-800', 'activated' => 'bg-green-100 text-green-800', 'locked' => 'bg-red-100 text-red-800'][$status] ?? 'bg-gray-100 text-gray-800';
                    @endphp

                    {{-- Two independent controls on one tab: which plan this install is
                         licensed for (tier), and whether its trial has been paid off
                         (activation). Separate forms, separate routes, separate settings —
                         saving one never writes the other. --}}
                    <div class="space-y-8 text-sm max-w-xl">
                        <section>
                            <h3 class="font-semibold mb-3">{{ __('plan.section_tier') }}</h3>
                            <form method="POST" action="{{ route('settings.plan.update') }}" class="space-y-4">
                                @csrf
                                <div>
                                    <label class="block text-xs text-gray-500">{{ __('plan.label') }}</label>
                                    <select name="tier" class="w-full border-gray-300 rounded text-sm">
                                        @foreach (\App\Support\License::tiers() as $t)
                                            <option value="{{ $t }}" @selected($tier === $t)>{{ __('plan.tier.'.$t) }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('plan.tier_help') }}</p>
                                </div>

                                <dl class="grid grid-cols-2 gap-3 border-t pt-4">
                                    <div>
                                        <dt class="text-xs text-gray-500">{{ __('plan.user_accounts') }}</dt>
                                        <dd class="font-medium">
                                            <x-ltr>{{ \App\Support\License::seatsUsed() }}</x-ltr> {{ __('plan.of') }} <x-ltr>{{ $maxUsers ?? __('plan.unlimited') }}</x-ltr>
                                        </dd>
                                        <p class="text-xs text-gray-500">{{ __('plan.super_admin_not_counted') }}</p>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-gray-500">{{ __('plan.devices') }}</dt>
                                        <dd class="font-medium"><x-ltr>{{ $maxDevices ?? __('plan.unlimited') }}</x-ltr></dd>
                                        <p class="text-xs text-gray-500">{{ __('plan.devices_help') }}</p>
                                    </div>
                                </dl>

                                <div class="flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('plan.save') }}</button></div>
                            </form>
                        </section>

                        <section class="border-t pt-6">
                            <h3 class="font-semibold mb-3">{{ __('activation.section') }}</h3>
                            <dl class="grid grid-cols-2 gap-y-2">
                                <dt class="text-gray-500">{{ __('activation.status') }}</dt>
                                <dd><span class="px-2 py-0.5 rounded text-xs font-medium {{ $badge }}">{{ \App\Support\EnumLabel::get('activation_status', $status) }}</span></dd>
                                @unless (\App\Support\Activation::isActivated())
                                    <dt class="text-gray-500">{{ __('activation.installed_on') }}</dt>
                                    <dd><x-ltr>{{ \App\Support\Activation::installedAt()->format('Y-m-d') }}</x-ltr></dd>
                                    <dt class="text-gray-500">{{ __('activation.trial_ends_on') }}</dt>
                                    <dd><x-ltr>{{ \App\Support\Activation::expiresAt()->format('Y-m-d') }}</x-ltr></dd>
                                    <dt class="text-gray-500">{{ __('activation.days_remaining') }}</dt>
                                    <dd><x-ltr>{{ \App\Support\Activation::daysRemaining() }}</x-ltr></dd>
                                @endunless
                            </dl>

                            @if (\App\Support\Activation::clockRolledBack())
                                <p class="mt-3 p-3 bg-amber-50 text-amber-800 rounded text-xs">{{ __('activation.clock_body') }}</p>
                            @endif

                            @if (\App\Support\Activation::isActivated())
                                <p class="mt-3 text-gray-600">{{ __('activation.activated_note') }}</p>
                            @else
                                <form method="POST" action="{{ route('settings.activation.activate') }}" class="mt-4 space-y-3 border-t pt-4">
                                    @csrf
                                    <p class="text-xs text-gray-500">{{ __('activation.activate_help') }}</p>
                                    <div>
                                        <label class="block text-xs text-gray-500">{{ __('Password') }}</label>
                                        <input type="password" name="password" dir="ltr" required autocomplete="current-password" class="w-full border-gray-300 rounded text-sm" />
                                    </div>
                                    <div class="flex justify-end">
                                        <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">{{ __('activation.activate') }}</button>
                                    </div>
                                </form>
                            @endif
                        </section>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
