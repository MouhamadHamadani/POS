{{--
    Login panel for demo builds only — nobody should be reciting usernames from
    memory in front of a prospect. The accounts come from DemoSeeder and the
    password from config('pos.demo_password'), so this can't drift from what is
    actually seeded.
--}}
@php
    $accounts = [
        ['demo_admin', 'Admin', 'مدير النظام'],
        ['demo_manager', 'Manager', 'مدير'],
        ['demo_cashier', 'Cashier', 'أمين صندوق'],
        ['demo_stock', 'Stock Keeper', 'أمين المستودع'],
    ];
@endphp

<div class="mt-6 border-t pt-4">
    <div class="flex items-baseline justify-between">
        <h3 class="text-sm font-semibold text-gray-800">Demo accounts</h3>
        <span class="text-xs text-gray-500" dir="rtl">حسابات تجريبية</span>
    </div>

    <table class="mt-2 w-full text-xs text-gray-700">
        <tbody>
            @foreach ($accounts as [$username, $role, $roleAr])
                <tr class="border-b last:border-0 border-gray-100">
                    <td class="py-1 font-mono">{{ $username }}</td>
                    <td class="py-1 text-gray-500">{{ $role }}</td>
                    <td class="py-1 text-gray-500 text-end" dir="rtl">{{ $roleAr }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-2 text-xs text-gray-600">
        Password for all four: <span class="font-mono font-semibold">{{ \App\Support\Demo::password() }}</span>
    </p>
    <p class="mt-1 text-xs text-gray-500">
        Sample data only. Closing the app restores the seeded catalogue.
    </p>
</div>
