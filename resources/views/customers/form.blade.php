<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? "Edit Customer" : "New Customer" }}</h2>
            <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 hover:underline">← Back</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('customers.update', $customer) : route('customers.store') }}"
              class="bg-white rounded-lg shadow-card p-6 space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Name *</label>
                    <input type="text" name="name" value="{{ old('name', $customer->name) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $customer->phone) }}" placeholder="+961 70 123 456" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                    <input type="email" name="email" value="{{ old('email', $customer->email) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Address</label>
                    <textarea name="address" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('address', $customer->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Company name</label>
                    <input type="text" name="company_name" value="{{ old('company_name', $customer->company_name) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Tax / VAT number (for B2B)</label>
                    <input type="text" name="tax_number" value="{{ old('tax_number', $customer->tax_number) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Customer group *</label>
                    <select name="customer_group" class="w-full border-gray-300 rounded text-sm" required>
                        @foreach (['retail' => 'Retail', 'wholesale' => 'Wholesale', 'vip' => 'VIP'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('customer_group', $customer->customer_group) === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Credit limit (USD)</label>
                    <input type="number" step="0.01" min="0" name="credit_limit" value="{{ old('credit_limit', $customer->credit_limit) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Birth date</label>
                    <input type="date" name="birth_date" value="{{ old('birth_date', $customer->birth_date?->format('Y-m-d')) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="flex items-end gap-4">
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt', $customer->tax_exempt))>
                        Tax exempt
                    </label>
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $customer->is_active ?? true))>
                        Active
                    </label>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $customer->notes) }}</textarea>
                </div>
            </div>

            <div class="flex justify-between items-center pt-4 border-t">
                @if ($isEdit)
                    <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                          onsubmit="return confirm('Delete {{ $customer->name }}?')">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2 text-sm text-danger hover:bg-red-50 rounded">Delete</button>
                    </form>
                @else
                    <span></span>
                @endif
                <div class="flex gap-2">
                    <a href="{{ route('customers.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">Cancel</a>
                    <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                        {{ $isEdit ? 'Save Changes' : 'Create Customer' }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
