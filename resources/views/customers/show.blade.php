<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $customer->name }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('customers.edit', $customer) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">Edit</a>
                <a href="{{ route('customers.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline">← Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Outstanding Balance</div>
                <div class="text-2xl font-bold {{ $customer->balance > 0 ? 'text-danger' : 'text-success' }} mt-1">${{ number_format((float) $customer->balance, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">Limit: ${{ number_format((float) $customer->credit_limit, 2) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Loyalty Points</div>
                <div class="text-2xl font-bold text-accent mt-1">{{ number_format($customer->loyalty_points) }}</div>
                <div class="text-xs text-gray-500 mt-1">Tier: {{ ucfirst($customer->loyalty_tier) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Lifetime Spend</div>
                <div class="text-2xl font-bold text-brand-700 mt-1">${{ number_format($totalSpent, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $salesCount }} sale{{ $salesCount === 1 ? '' : 's' }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Group</div>
                <div class="text-2xl font-bold text-gray-800 mt-1">{{ ucfirst($customer->customer_group) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $customer->phone ?? 'No phone' }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold mb-3 text-sm">Record payment</h3>
                @if ($customer->balance > 0)
                    <form method="POST" action="{{ route('customers.payment', $customer) }}" class="space-y-3 text-sm">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500">Amount (USD)</label>
                                <input type="number" step="0.01" min="0.01" max="{{ $customer->balance }}" name="amount_usd" required class="w-full border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">Method</label>
                                <select name="method" class="w-full border-gray-300 rounded text-sm" required>
                                    <option value="cash_usd">Cash (USD)</option>
                                    <option value="cash_lbp">Cash (LBP)</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Reference (cheque/transaction#)</label>
                            <input type="text" name="reference" class="w-full border-gray-300 rounded text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Notes</label>
                            <textarea name="notes" rows="2" class="w-full border-gray-300 rounded text-sm"></textarea>
                        </div>
                        <button class="w-full px-4 py-2 bg-success text-white rounded text-sm hover:bg-green-700">Record Payment</button>
                    </form>
                @else
                    <p class="text-sm text-gray-500">No outstanding balance.</p>
                @endif
            </div>

            <div class="bg-white rounded-lg shadow-card p-5">
                <h3 class="font-semibold mb-3 text-sm">Recent loyalty activity</h3>
                <table class="w-full text-xs">
                    <thead class="text-gray-500 text-xs uppercase">
                        <tr class="border-b"><th class="text-left py-2">Type</th><th class="text-right">Points</th><th class="text-right">Balance</th><th class="text-right">When</th></tr>
                    </thead>
                    <tbody class="divide-y">
                    @forelse ($loyalty as $lt)
                        <tr>
                            <td class="py-2"><span class="text-xs px-2 py-0.5 rounded {{ $lt->points > 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">{{ ucfirst($lt->type) }}</span></td>
                            <td class="text-right">{{ $lt->points > 0 ? '+' : '' }}{{ $lt->points }}</td>
                            <td class="text-right text-gray-700">{{ $lt->balance_after }}</td>
                            <td class="text-right text-gray-500">{{ $lt->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-400">No loyalty activity.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">Recent sales</h3>
            <table class="w-full text-sm">
                <thead class="text-gray-500 text-xs uppercase">
                    <tr class="border-b"><th class="text-left py-2">Receipt</th><th class="text-left">Method</th><th class="text-right">Total</th><th class="text-right">Status</th><th class="text-right">When</th></tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($sales as $s)
                    <tr>
                        <td class="py-2 font-mono text-xs">{{ $s->receipt_number }}</td>
                        <td class="text-xs">{{ $s->payment_method }}</td>
                        <td class="text-right font-medium">${{ number_format((float) $s->total_usd, 2) }}</td>
                        <td class="text-right text-xs">{{ $s->status }}</td>
                        <td class="text-right text-gray-500">{{ $s->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">No sales yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
