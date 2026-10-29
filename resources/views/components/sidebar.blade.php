@php
    $user = auth()->user();
    $role = $user?->role;

    $nav = [
        ['pos',             '/pos',              'cashier,manager,admin,stock', '🛒'],
        ['dashboard',       '/dashboard',        'manager,admin',               '📊'],
        ['products',        '/products',         'stock,manager,admin',         '📦'],
        ['stock_movements', '/products/movements', 'stock,manager,admin',       '🔄'],
        ['categories',     '/categories',       'stock,manager,admin',         '🏷️'],
        ['customers',       '/customers',        'manager,admin',               '👥'],
        ['suppliers',       '/suppliers',        'manager,admin',               '🚚'],
        ['purchases',       '/purchase-orders',  'manager,admin',               '📑'],
        ['sales',           '/sales',            implode(',', \App\Models\User::SALES_HISTORY_ROLES), '🧾'],
        ['returns',         '/returns',          'cashier,manager,admin',       '↩️'],
        ['expenses',        '/expenses',         'manager,admin',               '💸'],
        ['reports',         '/reports',          'manager,admin',               '📈'],
        ['users',           '/users',            'admin',                        '👤'],
        ['settings',        '/settings',         'admin',                        '⚙️'],
    ];

    // Bulk upload is off by default and switched on per role by a super-admin,
    // so it can't be expressed as a role list. Hiding it is cosmetic — the
    // routes are gated by `can:bulk-upload-products` regardless.
    if ($user?->canBulkUploadProducts()) {
        array_splice($nav, 3, 0, [['bulk_upload', '/products/import', $role, '📤']]);
    }

    // Items that belong to a feature this install doesn't have. Cosmetic only — the
    // routes themselves are gated by the same `feature:` key in routes/web.php.
    $featureFor = [
        '/products/movements' => 'stock_movements',
        '/customers' => 'loyalty',
        '/suppliers' => 'suppliers',
        '/purchase-orders' => 'suppliers',
        '/returns' => 'returns',
        '/expenses' => 'expenses',
    ];
    $nav = array_values(array_filter($nav, fn ($item) => ! isset($featureFor[$item[1]]) || feature($featureFor[$item[1]])));
@endphp

<aside x-data="{ open: window.innerWidth >= 1024 }"
       x-init="window.addEventListener('resize', () => open = window.innerWidth >= 1024)"
       class="bg-brand-700 text-white flex flex-col h-screen sticky top-0 transition-all duration-200"
       :class="open ? 'w-56' : 'w-16'">

    <div class="flex items-center justify-between p-3 border-b border-brand-600">
        <div class="flex items-center gap-2 overflow-hidden">
            <x-brand-mark class="w-8 h-8 rounded flex-shrink-0" />
            <div x-show="open" class="font-semibold whitespace-nowrap">LebaSouk</div>
        </div>
        <button @click="open = !open" class="text-brand-200 hover:text-white p-1" title="{{ __('nav.toggle_sidebar') }}">
            <svg x-show="open"  class="w-4 h-4 rtl:-scale-x-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            <svg x-show="!open" class="w-4 h-4 rtl:-scale-x-100" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto py-2">
        @foreach ($nav as [$key, $path, $rolesCsv, $icon])
            @php
                $roles = array_filter(array_map('trim', explode(',', $rolesCsv)));
                $allowed = !$role || $role === \App\Models\User::ROLE_SUPER_ADMIN || in_array($role, $roles, true);
                $active = request()->is(ltrim($path, '/') . '*')
                    // /products* would otherwise light up on its own sub-screens too.
                    && ! ($path === '/products' && request()->is('products/movements*'));
            @endphp
            @if ($allowed)
                <a href="{{ $path }}"
                   class="flex items-center gap-3 px-3 py-2 [@media(max-height:820px)]:py-1.5 text-sm hover:bg-brand-600 transition border-s-4 {{ $active ? 'bg-brand-600 border-accent-light text-white' : 'border-transparent text-brand-100' }}">
                    <span class="text-lg leading-none flex-shrink-0 w-5 text-center">{{ $icon }}</span>
                    <span x-show="open" class="whitespace-nowrap">{{ __("nav.$key") }}</span>
                </a>
            @endif
        @endforeach
    </nav>

    <div class="border-t border-brand-600 p-3 text-xs space-y-2">
        @if (\App\Support\Demo::enabled())
            {{-- Demo builds only. The confirm() is the guard against firing this
                 mid-sale: the reset replaces the database file underneath the app. --}}
            <form method="POST" action="{{ route('demo.reset') }}"
                  onsubmit="return confirm(@js(__('nav.reset_demo_confirm')))">
                @csrf
                <button class="w-full flex items-center gap-2 px-2 py-2 rounded bg-amber-400 text-amber-950 font-semibold hover:bg-amber-300 transition"
                        :title="open ? '' : @js(__('nav.reset_demo'))">
                    <span class="inline-block w-5 text-center">&#8634;</span>
                    <span x-show="open">{{ __('nav.reset_demo') }}</span>
                </button>
            </form>
        @endif

        {{-- The user menu: profile, language and "My preferences" (the user's own interface size) live here, not in the
             sidebar itself, so the navigation keeps the height it needs at a larger interface size. --}}
        <div x-data="{ menu: false }" class="relative">
            <button type="button" @click="menu = !menu" :aria-expanded="menu"
                    class="w-full flex items-center gap-2 text-start hover:text-accent-light"
                    :title="open ? '' : @js($user?->name)">
                <div class="w-7 h-7 rounded-full bg-accent text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                    {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
                </div>
                <div x-show="open" class="flex-1 overflow-hidden">
                    <div class="font-medium truncate">{{ $user?->name }}</div>
                    <div class="text-brand-300 truncate">{{ $user?->roleLabel() }}</div>
                </div>
                <svg x-show="open" class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
            </button>

            <div x-show="menu" @click.outside="menu = false" @keydown.escape.window="menu = false" x-cloak
                 class="absolute bottom-full start-0 mb-2 w-56 bg-white text-gray-800 rounded-lg shadow-pop py-1 text-sm z-50">
                <a href="{{ route('profile.edit') }}" class="block px-3 py-2 hover:bg-gray-100">{{ __('Profile') }}</a>
                <div class="px-3 py-2"><x-lang-switch class="text-gray-600" /></div>
                @unless (\App\Support\Demo::enabled())
                    <div class="px-3 py-2 border-t">
                        <div class="mb-1 text-xs font-semibold text-gray-500">{{ __('profile.my_preferences') }}</div>
                        <div class="mb-1 text-xs text-gray-500">{{ __('profile.ui_scale') }}</div>
                        <x-ui-scale-switcher />
                    </div>
                @endunless
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="w-full text-start text-brand-200 hover:text-white" :title="open ? '' : @js(__('nav.logout'))">
                <span class="inline-block w-7 text-center">⎋</span>
                <span x-show="open">{{ __('nav.logout') }}</span>
            </button>
        </form>
    </div>
</aside>
