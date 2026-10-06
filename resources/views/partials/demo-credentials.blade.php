{{--
    Login panel for demo builds only — nobody should be reciting usernames from
    memory in front of a prospect. The accounts come from DemoSeeder and the
    password from config('pos.demo_password'), so this can't drift from what is
    actually seeded.
--}}
@php
    // Both halves are shown on purpose, so each is forced to its own language.
    $accounts = [
        ['demo_admin', 'admin'],
        ['demo_manager', 'manager'],
        ['demo_cashier', 'cashier'],
        ['demo_stock', 'stock'],
    ];
@endphp

<div class="mt-6 border-t pt-4">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-semibold text-gray-800">{{ __('demo.accounts', [], 'en') }}</h3>
        <span class="text-xs text-gray-500" dir="rtl">{{ __('demo.accounts', [], 'ar') }}</span>
    </div>

    <table class="mt-2 w-full text-xs text-gray-700">
        <tbody>
            @foreach ($accounts as [$username, $role])
                <tr class="border-b last:border-0 border-gray-100">
                    <td class="py-1 font-mono">{{ $username }}</td>
                    <td class="py-1 text-gray-500">{{ __("enums.role.$role", [], 'en') }}</td>
                    <td class="py-1 text-gray-500 text-end" dir="rtl">{{ __("enums.role.$role", [], 'ar') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-2 text-xs text-gray-600">
        {{ __('demo.password_for_all', [], 'en') }} <span class="font-mono font-semibold">{{ \App\Support\Demo::password() }}</span>
    </p>
    <p class="mt-1 text-xs text-gray-500">
        {{ __('demo.sample_data_only', [], 'en') }}
    </p>
    <p class="mt-2 text-xs text-gray-600" dir="rtl">
        {{ __('demo.password_for_all', [], 'ar') }} <bdi dir="ltr" class="font-mono font-semibold">{{ \App\Support\Demo::password() }}</bdi>
    </p>
    <p class="mt-1 text-xs text-gray-500" dir="rtl">
        {{ __('demo.sample_data_only', [], 'ar') }}
    </p>
</div>
