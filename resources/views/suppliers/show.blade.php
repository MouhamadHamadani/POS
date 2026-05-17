<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $supplier->name }}</h2>
            <div class="flex gap-2">
                <a href="{{ route('suppliers.edit', $supplier) }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">Edit</a>
                <a href="{{ route('purchases.create', ['supplier' => $supplier->id]) }}" class="px-3 py-2 text-sm bg-brand-700 text-white rounded hover:bg-brand-800">+ New PO</a>
                <a href="{{ route('suppliers.index') }}" class="px-3 py-2 text-sm text-gray-600 hover:underline">← Back</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if (session('success'))<div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>@endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Outstanding Balance</div>
                <div class="text-2xl font-bold {{ $supplier->balance > 0 ? 'text-warning' : 'text-success' }} mt-1">${{ number_format((float) $supplier->balance, 2) }}</div>
                <div class="text-xs text-gray-500 mt-1">Terms: {{ $supplier->payment_terms }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Lifetime Purchases</div>
                <div class="text-2xl font-bold text-brand-700 mt-1">${{ number_format($totalSpent, 2) }}</div>
            </div>
            <div class="bg-white rounded-lg shadow-card p-4">
                <div class="text-xs text-gray-500">Contact</div>
                <div class="text-sm mt-1">{{ $supplier->contact_person ?? '—' }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $supplier->phone ?? '' }} {{ $supplier->email ? '· ' . $supplier->email : '' }}</div>
            </div>
        </div>

        @if ($supplier->balance > 0)
        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">Record payment to supplier</h3>
            <form method="POST" action="{{ route('suppliers.payment', $supplier) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end text-sm">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500">Amount (USD)</label>
                    <input type="number" step="0.01" min="0.01" max="{{ $supplier->balance }}" name="amount_usd" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Method</label>
                    <select name="method" class="w-full border-gray-300 rounded text-sm" required>
                        <option value="cash_usd">Cash USD</option>
                        <option value="cash_lbp">Cash LBP</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Reference</label>
                    <input type="text" name="reference" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <button class="px-3 py-2 bg-success text-white rounded text-sm hover:bg-green-700">Record</button>
            </form>
        </div>
        @endif

        <div class="bg-white rounded-lg shadow-card p-5">
            <h3 class="font-semibold mb-3 text-sm">Recent purchase orders</h3>
            <table class="w-full text-sm">
                <thead class="text-gray-500 text-xs uppercase">
                    <tr class="border-b"><th class="text-left py-2">PO #</th><th class="text-left">Status</th><th class="text-right">Total</th><th class="text-right">Expected</th><th></th></tr>
                </thead>
                <tbody class="divide-y">
                @forelse ($pos as $po)
                    <tr>
                        <td class="py-2 font-mono text-xs">{{ $po->po_number }}</td>
                        <td><span class="text-xs px-2 py-0.5 rounded bg-brand-100 text-brand-700">{{ ucfirst($po->status) }}</span></td>
                        <td class="text-right font-medium">${{ number_format((float) $po->total_usd, 2) }}</td>
                        <td class="text-right text-gray-500">{{ $po->expected_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="text-right text-xs"><a href="{{ route('purchases.show', $po) }}" class="text-blue-600">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">No purchase orders yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
