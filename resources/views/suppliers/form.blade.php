<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ $isEdit ? __('suppliers.edit_supplier') : __('suppliers.new_supplier_title') }}</h2>
            <a href="{{ route('suppliers.index') }}" class="text-sm text-gray-600 hover:underline"><span class="inline-block rtl:-scale-x-100" aria-hidden="true">←</span> {{ __('common.back') }}</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">
                <ul class="list-disc list-inside">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('suppliers.update', $supplier) : route('suppliers.store') }}"
              class="bg-white rounded-lg shadow-card p-6 space-y-4">
            @csrf
            @if ($isEdit) @method('PUT') @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.name') }} *</label>
                    <input type="text" name="name" dir="auto" value="{{ old('name', $supplier->name) }}" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('categories.name_ar') }}</label>
                    <input type="text" name="name_ar" value="{{ old('name_ar', $supplier->name_ar) }}" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('suppliers.contact_person') }}</label>
                    <input type="text" name="contact_person" dir="auto" value="{{ old('contact_person', $supplier->contact_person) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.phone') }}</label>
                    <input type="text" name="phone" dir="ltr" value="{{ old('phone', $supplier->phone) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('users.email') }}</label>
                    <input type="email" name="email" dir="ltr" value="{{ old('email', $supplier->email) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('suppliers.website') }}</label>
                    <input type="text" name="website" dir="ltr" value="{{ old('website', $supplier->website) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.address') }}</label>
                    <textarea name="address" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('address', $supplier->address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('suppliers.tax_number') }}</label>
                    <input type="text" name="tax_number" dir="ltr" value="{{ old('tax_number', $supplier->tax_number) }}" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('suppliers.payment_terms') }}</label>
                    <select name="payment_terms" class="w-full border-gray-300 rounded text-sm">
                        @foreach (['NET15', 'NET30', 'NET60', 'COD', 'prepaid'] as $t)
                            <option value="{{ $t }}" @selected(old('payment_terms', $supplier->payment_terms) === $t)>{{ __("enums.payment_terms.$t") }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $supplier->is_active ?? true))>
                    <label for="is_active" class="text-sm">{{ __('common.active') }}</label>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('common.notes') }}</label>
                    <textarea name="notes" dir="auto" rows="2" class="w-full border-gray-300 rounded text-sm">{{ old('notes', $supplier->notes) }}</textarea>
                </div>
            </div>

            <div class="flex justify-between items-center pt-4 border-t">
                @if ($isEdit)
                    <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}"
                          onsubmit="return confirm({{ Js::from(__('suppliers.confirm_delete', ['name' => $supplier->name])) }})">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2 text-sm text-danger hover:bg-red-50 rounded">{{ __('common.delete') }}</button>
                    </form>
                @else
                    <span></span>
                @endif
                <div class="flex gap-2">
                    <a href="{{ route('suppliers.index') }}" class="px-4 py-2 bg-gray-100 rounded text-sm">{{ __('common.cancel') }}</a>
                    <button class="px-4 py-2 bg-brand-700 text-white rounded text-sm hover:bg-brand-800">
                        {{ $isEdit ? __('common.save_changes') : __('suppliers.create_supplier') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
