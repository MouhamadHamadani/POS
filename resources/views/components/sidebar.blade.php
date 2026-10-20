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

    // Feature areas the install's tier doesn't include. Cosmetic only — the
    // routes themselves are gated by `feature:` middleware either way.
    $nav = array_values(array_filter($nav, fn ($item) => match ($item[1]) {
        '/customers' => \App\Support\License::allows('loyalty'),
        '/suppliers', '/purchase-orders' => \App\Support\License::allows('suppliers'),
        '/returns' => \App\Support\License::allows('returns'),
        default => true,
    }));

    // Bulk upload is off by default and switched on per role by a super-admin,
    // so it can't be expressed as a role list. Hiding it is cosmetic — the
    // routes are gated by `can:bulk-upload-products` regardless.
    if ($user?->canBulkUploadProducts()) {
        array_splice($nav, 3, 0, [['bulk_upload', '/products/import', $role, '📤']]);
    }
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
                   class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-brand-600 transition border-s-4 {{ $active ? 'bg-brand-600 border-accent-light text-white' : 'border-transparent text-brand-100' }}">
                    <span class="text-lg flex-shrink-0 w-5 text-center">{{ $icon }}</span>
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

        <x-lang-switch x-show="open" class="text-brand-200 px-1" />

        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-2 hover:text-accent-light"
           :title="open ? '' : @js($user?->name)">
            <div class="w-7 h-7 rounded-full bg-accent text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
            </div>
            <div x-show="open" class="overflow-hidden">
                <div class="font-medium truncate">{{ $user?->name }}</div>
                <div class="text-brand-300 truncate">{{ $user?->roleLabel() }}</div>
            </div>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="w-full text-start text-brand-200 hover:text-white" :title="open ? '' : @js(__('nav.logout'))">
                <span class="inline-block w-7 text-center">⎋</span>
                <span x-show="open">{{ __('nav.logout') }}</span>
            </button>
        </form>
    </div>
</aside>
