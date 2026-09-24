<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('products.trashed.title') }}</h2>
            <a href="{{ route('products.index') }}" class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded">
                {{ __('products.trashed.back') }}
            </a>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        <div class="p-3 bg-amber-50 text-amber-800 rounded text-sm">{{ __('products.trashed.note') }}</div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">{{ __('products.trashed.name') }}</th>
                        <th class="p-3 text-left">{{ __('products.trashed.old_sku') }}</th>
                        <th class="p-3 text-left">{{ __('products.trashed.old_barcode') }}</th>
                        <th class="p-3 text-left">{{ __('products.trashed.category') }}</th>
                        <th class="p-3 text-left">{{ __('products.trashed.deleted_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($products as $p)
                        <tr class="hover:bg-gray-50">
                            <td class="p-3">
                                <div class="font-medium text-gray-900">{{ $p->name }}</div>
                                @if ($p->name_ar)
                                    <div class="text-xs text-gray-500" dir="rtl">{{ $p->name_ar }}</div>
                                @endif
                            </td>
                            {{-- Codes stay LTR: an Arabic page must not reorder a barcode's digits. --}}
                            <td class="p-3 text-xs" dir="ltr">
                                @if ($p->old_sku)
                                    <span class="text-gray-600">{{ $p->old_sku }}</span>
                                @else
                                    <span class="text-gray-400">{{ __('products.trashed.unknown_code') }}</span>
                                @endif
                            </td>
                            <td class="p-3 text-xs" dir="ltr">
                                @if ($p->old_barcode)
                                    <span class="font-mono text-gray-600">{{ $p->old_barcode }}</span>
                                @else
                                    <span class="text-gray-400">{{ __('products.trashed.unknown_code') }}</span>
                                @endif
                            </td>
                            <td class="p-3 text-gray-700">{{ $p->category?->name ?? '—' }}</td>
                            <td class="p-3 text-gray-600 text-xs" dir="ltr">{{ $p->deleted_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-10 text-center text-gray-500">{{ __('products.trashed.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $products->links() }}</div>
    </div>
</x-app-layout>
