<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::withCount('products')->orderBy('sort_order')->get();
        return view('categories.index', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:120',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        Category::create($data);
        return back()->with('success', "Created category '{$data['name']}'");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'name_ar' => 'nullable|string|max:120',
            'color' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $category->update($data);
        return back()->with('success', "Updated '{$category->name}'");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if (Product::where('category_id', $category->id)->exists()) {
            return back()->withErrors(['delete' => "Can't delete: products are assigned to this category."]);
        }
        $name = $category->name;
        $category->delete();
        return back()->with('success', "Deleted '{$name}'");
    }
}
