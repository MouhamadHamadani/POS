<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">{{ __('Categories') }}</h2>
            <a href="{{ route('products.index') }}" class="text-sm text-gray-600 hover:underline">← Back to products</a>
        </div>
    </x-slot>

    <div class="py-6 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if (session('success'))
            <div class="p-3 bg-green-50 text-green-800 rounded text-sm">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="p-3 bg-red-50 text-red-700 rounded text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white rounded-lg shadow-sm p-4">
            <h3 class="font-semibold mb-3 text-sm">Add new category</h3>
            <form method="POST" action="{{ route('categories.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end text-sm">
                @csrf
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-500">Name (English) *</label>
                    <input type="text" name="name" required class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Name (Arabic)</label>
                    <input type="text" name="name_ar" dir="rtl" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Sort</label>
                    <input type="number" name="sort_order" value="0" class="w-full border-gray-300 rounded text-sm" />
                </div>
                <button class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm">Add</button>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="p-3 text-left">Name</th>
                        <th class="p-3 text-left">Arabic</th>
                        <th class="p-3 text-right">Sort</th>
                        <th class="p-3 text-right">Products</th>
                        <th class="p-3 text-center">Active</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($categories as $c)
                        <tr>
                            <form method="POST" action="{{ route('categories.update', $c) }}">
                                @csrf @method('PUT')
                                <td class="p-2"><input name="name" value="{{ $c->name }}" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                <td class="p-2"><input name="name_ar" value="{{ $c->name_ar }}" dir="rtl" class="w-full border-gray-200 rounded text-sm py-1"></td>
                                <td class="p-2 w-20"><input type="number" name="sort_order" value="{{ $c->sort_order }}" class="w-full border-gray-200 rounded text-sm py-1 text-right"></td>
                                <td class="p-2 text-right text-gray-700">{{ $c->products_count }}</td>
                                <td class="p-2 text-center">
                                    <input type="checkbox" name="is_active" value="1" @checked($c->is_active)>
                                </td>
                                <td class="p-2 text-right whitespace-nowrap">
                                    <button class="text-xs text-blue-600 hover:underline">Save</button>
                            </form>
                                    <form method="POST" action="{{ route('categories.destroy', $c) }}" class="inline ml-2"
                                          onsubmit="return confirm('Delete category {{ $c->name }}?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline" {{ $c->products_count > 0 ? 'disabled title=Has products' : '' }}>Delete</button>
                                    </form>
                                </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center text-gray-500">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
