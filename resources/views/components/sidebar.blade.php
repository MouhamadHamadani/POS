@php
    $user = auth()->user();
    $role = $user?->role;

    $nav = [
        ['POS',             '/pos',              'cashier,manager,admin,stock', '🛒'],
        ['Dashboard',       '/dashboard',        'manager,admin',               '📊'],
        ['Products',        '/products',         'stock,manager,admin',         '📦'],
        ['Categories',      '/categories',       'stock,manager,admin',         '🏷️'],
        ['Customers',       '/customers',        'manager,admin',               '👥'],
        ['Suppliers',       '/suppliers',        'manager,admin',               '🚚'],
        ['Purchase Orders', '/purchase-orders',  'manager,admin',               '📑'],
        ['Reports',         '/reports',          'manager,admin',               '📈'],
        ['Users',           '/users',            'admin',                        '👤'],
        ['Settings',        '/settings',         'admin',                        '⚙️'],
    ];
@endphp

<aside x-data="{ open: window.innerWidth >= 1024 }"
       x-init="window.addEventListener('resize', () => open = window.innerWidth >= 1024)"
       class="bg-brand-700 text-white flex flex-col h-screen sticky top-0 transition-all duration-200"
       :class="open ? 'w-56' : 'w-16'">

    <div class="flex items-center justify-between p-3 border-b border-brand-600">
        <div class="flex items-center gap-2 overflow-hidden">
            <div class="w-8 h-8 rounded bg-accent-light text-brand-900 flex items-center justify-center font-bold flex-shrink-0">P</div>
            <div x-show="open" class="font-semibold whitespace-nowrap">POS Pro</div>
        </div>
        <button @click="open = !open" class="text-brand-200 hover:text-white p-1" title="Toggle sidebar">
            <svg x-show="open"  class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            <svg x-show="!open" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto py-2">
        @foreach ($nav as [$label, $path, $rolesCsv, $icon])
            @php
                $roles = array_filter(array_map('trim', explode(',', $rolesCsv)));
                $allowed = !$role || in_array($role, $roles, true);
                $active = request()->is(ltrim($path, '/') . '*');
            @endphp
            @if ($allowed)
                <a href="{{ $path }}"
                   class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-brand-600 transition border-l-4 {{ $active ? 'bg-brand-600 border-accent-light text-white' : 'border-transparent text-brand-100' }}">
                    <span class="text-lg flex-shrink-0 w-5 text-center">{{ $icon }}</span>
                    <span x-show="open" class="whitespace-nowrap">{{ $label }}</span>
                </a>
            @endif
        @endforeach
    </nav>

    <div class="border-t border-brand-600 p-3 text-xs space-y-2">
        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-2 hover:text-accent-light"
           :title="open ? '' : '{{ $user?->name }}'">
            <div class="w-7 h-7 rounded-full bg-accent text-white flex items-center justify-center text-xs font-bold flex-shrink-0">
                {{ strtoupper(substr($user?->name ?? '?', 0, 1)) }}
            </div>
            <div x-show="open" class="overflow-hidden">
                <div class="font-medium truncate">{{ $user?->name }}</div>
                <div class="text-brand-300 truncate">{{ ucfirst($role ?? '') }}</div>
            </div>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="w-full text-left text-brand-200 hover:text-white" :title="open ? '' : 'Log out'">
                <span class="inline-block w-7 text-center">⎋</span>
                <span x-show="open">Log out</span>
            </button>
        </form>
    </div>
</aside>
