@php
    $tabs = [
        'general' => 'General',
        'currency' => 'Currency',
        'tax' => 'Tax',
        'pos' => 'POS Behavior',
        'receipt' => 'Receipt',
        'numbering' => 'Numbering',
        'loyalty' => 'Loyalty',
        'backup' => 'Backup',
    ];
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
                            <label class="block text-xs text-gray-500">Business name *</label>
                            <input type="text" name="settings[business_name]" value="{{ $get('business_name') }}" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Business name (Arabic)</label>
                            <input type="text" name="settings[business_name_ar]" value="{{ $get('business_name_ar') }}" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">VAT / Tax number</label>
                            <input type="text" name="settings[tax_number]" value="{{ $get('tax_number') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Phone</label>
                            <input type="text" name="settings[phone]" value="{{ $get('phone') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Email</label>
                            <input type="email" name="settings[email]" value="{{ $get('email') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Address</label>
                            <textarea name="settings[address]" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('address') }}</textarea>
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'currency')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="currency">
                        <div>
                            <label class="block text-xs text-gray-500">Exchange rate (1 USD → LBP) *</label>
                            <input type="number" step="0.01" min="0" name="settings[exchange_rate]" value="{{ $get('exchange_rate') }}" required class="w-full border-gray-300 rounded text-sm" />
                            <div class="text-xs text-gray-500 mt-1">Updated by managers. Each sale snapshots this rate.</div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">LBP rounding step</label>
                            <select name="settings[lbp_rounding_step]" class="w-full border-gray-300 rounded text-sm">
                                @foreach ([100, 500, 1000, 5000] as $step)
                                    <option value="{{ $step }}" @selected((int) $get('lbp_rounding_step') === $step)>{{ number_format($step) }} LBP</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'tax')
                    <h3 class="font-semibold mb-3 text-sm">Tax rates</h3>
                    <table class="min-w-full text-sm mb-4">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr><th class="text-left py-2">Name</th><th class="text-left">Arabic</th><th class="text-right">Rate</th><th class="text-center">Inclusive</th><th class="text-center">Default</th><th class="text-center">Active</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y">
                        @foreach ($taxes as $t)
                            <tr>
                                <form method="POST" action="{{ route('settings.tax.update', $t) }}">
                                    @csrf @method('PUT')
                                    <td class="py-2"><input name="name" value="{{ $t->name }}" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2"><input name="name_ar" value="{{ $t->name_ar }}" dir="rtl" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2 w-24"><input type="number" step="0.0001" min="0" max="1" name="rate" value="{{ $t->rate }}" class="w-full text-right border-gray-200 rounded text-sm py-1"></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_inclusive" value="1" @checked($t->is_inclusive)></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_default" value="1" @checked($t->is_default)></td>
                                    <td class="py-2 text-center"><input type="checkbox" name="is_active" value="1" @checked($t->is_active)></td>
                                    <td class="py-2 text-right"><button class="text-xs text-blue-600">Save</button>
                                </form>
                                    <form method="POST" action="{{ route('settings.tax.destroy', $t) }}" class="inline ml-2" onsubmit="return confirm('Delete tax {{ $t->name }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600">×</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>

                    <form method="POST" action="{{ route('settings.tax.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm border-t pt-4">
                        @csrf
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Name *</label>
                            <input type="text" name="name" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Arabic</label>
                            <input type="text" name="name_ar" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Rate (e.g. 0.11)</label>
                            <input type="number" step="0.0001" min="0" max="1" name="rate" required class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="flex flex-col text-xs gap-1">
                            <label><input type="checkbox" name="is_inclusive" value="1"> Inclusive</label>
                            <label><input type="checkbox" name="is_default" value="1"> Default</label>
                        </div>
                        <button class="px-3 py-1.5 bg-brand-700 text-white rounded text-sm">+ Add</button>
                    </form>

                @elseif ($tab === 'pos')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="pos">
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[auto_print]" value="1" @checked($get('auto_print'))>
                            Auto-print receipt after sale
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[require_shift]" value="1" @checked($get('require_shift'))>
                            Require open shift to sell
                        </label>
                        <label class="text-sm flex items-center gap-2">
                            <input type="checkbox" name="settings[pos_display_cost]" value="1" @checked($get('pos_display_cost'))>
                            Show product cost to cashier
                        </label>
                        <div>
                            <label class="block text-xs text-gray-500">Max cashier discount %</label>
                            <input type="number" step="0.01" min="0" max="100" name="settings[max_cashier_discount_pct]" value="{{ $get('max_cashier_discount_pct') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Idle auto-logout (minutes)</label>
                            <input type="number" min="1" max="600" name="settings[idle_timeout_min]" value="{{ $get('idle_timeout_min') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'receipt')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="receipt">
                        <div>
                            <label class="block text-xs text-gray-500">Paper width</label>
                            <select name="settings[receipt_width]" class="w-full border-gray-300 rounded text-sm">
                                <option value="58" @selected((int) $get('receipt_width') === 58)>58 mm</option>
                                <option value="80" @selected((int) $get('receipt_width') === 80)>80 mm</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Characters per line</label>
                            <input type="number" min="20" max="80" name="settings[paper_width_char]" value="{{ $get('paper_width_char') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Header (printed top of receipt)</label>
                            <textarea name="settings[receipt_header]" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('receipt_header') }}</textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs text-gray-500">Footer (printed bottom of receipt)</label>
                            <textarea name="settings[receipt_footer]" rows="2" class="w-full border-gray-300 rounded text-sm">{{ $get('receipt_footer') }}</textarea>
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'numbering')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="numbering">
                        @foreach (['receipt_prefix' => 'Receipt prefix', 'invoice_prefix' => 'Invoice prefix', 'po_prefix' => 'PO prefix', 'return_prefix' => 'Return prefix'] as $k => $label)
                            <div>
                                <label class="block text-xs text-gray-500">{{ $label }}</label>
                                <input type="text" name="settings[{{ $k }}]" value="{{ $get($k) }}" class="w-full border-gray-300 rounded text-sm" />
                            </div>
                        @endforeach
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'loyalty')
                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        @csrf <input type="hidden" name="group" value="loyalty">
                        <div>
                            <label class="block text-xs text-gray-500">Points earned per $1 spent</label>
                            <input type="number" step="0.01" min="0" name="settings[points_per_dollar]" value="{{ $get('points_per_dollar') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Redemption rate (points = $1)</label>
                            <input type="number" min="1" name="settings[redemption_rate]" value="{{ $get('redemption_rate') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Silver tier threshold</label>
                            <input type="number" min="0" name="settings[silver_threshold]" value="{{ $get('silver_threshold') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Gold tier threshold</label>
                            <input type="number" min="0" name="settings[gold_threshold]" value="{{ $get('gold_threshold') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Points expire after (days)</label>
                            <input type="number" min="0" name="settings[expiry_days]" value="{{ $get('expiry_days') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div class="md:col-span-2 flex justify-end"><button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">Save</button></div>
                    </form>

                @elseif ($tab === 'backup')
                    <div class="flex flex-wrap gap-3 items-center mb-4">
                        <form method="POST" action="{{ route('settings.backup.now') }}">
                            @csrf
                            <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">+ Backup Now &amp; Download</button>
                        </form>
                        <span class="text-xs text-gray-500">Creates a snapshot of the SQLite database.</span>
                    </div>

                    <form method="POST" action="{{ route('settings.update') }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end text-sm border-t pt-4 mb-4">
                        @csrf <input type="hidden" name="group" value="backup">
                        <div>
                            <label class="block text-xs text-gray-500">Auto-backup frequency</label>
                            <select name="settings[backup_frequency]" class="w-full border-gray-300 rounded text-sm">
                                @foreach (['off', 'daily', 'weekly'] as $f)
                                    <option value="{{ $f }}" @selected($get('backup_frequency') === $f)>{{ ucfirst($f) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Retention (days)</label>
                            <input type="number" min="0" name="settings[retention_days]" value="{{ $get('retention_days') }}" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <button class="px-4 py-2 bg-gray-800 text-white rounded text-sm">Save Schedule</button>
                    </form>

                    <h3 class="font-semibold mb-2 text-sm border-t pt-4">Existing backups</h3>
                    <table class="min-w-full text-sm">
                        <thead class="text-xs uppercase text-gray-500 border-b">
                            <tr><th class="text-left py-2">File</th><th class="text-right">Size</th><th class="text-right">When</th><th class="text-right">Actions</th></tr>
                        </thead>
                        <tbody class="divide-y">
                        @forelse ($backups as $b)
                            <tr>
                                <td class="py-2 font-mono text-xs">{{ $b['name'] }}</td>
                                <td class="text-right text-xs">{{ $b['bytes'] }}</td>
                                <td class="text-right text-xs text-gray-500">{{ date('Y-m-d H:i', $b['date']) }}</td>
                                <td class="text-right space-x-2 whitespace-nowrap">
                                    <a href="{{ route('settings.backup.download', $b['name']) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                                    <form method="POST" action="{{ route('settings.backup.restore', $b['name']) }}" class="inline"
                                          onsubmit="return confirm('Restore this backup? Current DB will be overwritten.')">
                                        @csrf
                                        <button class="text-xs text-orange-600 hover:underline">Restore</button>
                                    </form>
                                    <form method="POST" action="{{ route('settings.backup.delete', $b['name']) }}" class="inline"
                                          onsubmit="return confirm('Delete this backup file?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-gray-400">No backups yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
