<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Category::all();
    }

    /**
     * Display a paginated listing of categories for admin with stats.
     */
    public function adminIndex(Request $request)
    {
        if ($request->user() && $request->user()->role !== 'admin') {
            // allow if checking stats
        }
        // Assuming middleware handles auth, but safety check:
        // if ($request->user()->role !== 'admin') return response()->json(['message' => 'Unauthorized'], 403); 

        $perPage = $request->input('per_page', 15);

        // Fetch paginated categories with product count and sales volume
        $categories = Category::withCount('products')
            ->addSelect([
                'sales_volume' => \App\Models\OrderItem::selectRaw('sum(quantity)')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->whereColumn('products.category_id', 'categories.id')
            ])
            ->paginate($perPage);

        // Stats
        $monthlyTopCategory = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereMonth('order_items.created_at', now()->month)
            ->whereYear('order_items.created_at', now()->year)
            ->select('categories.id', 'categories.name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as monthly_sales'))
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('monthly_sales')
            ->first();

        $stats = [
            'total_categories' => Category::count(),
            'monthly_top_performing_category' => $monthlyTopCategory,
            'total_sales_volume' => \App\Models\OrderItem::sum('quantity')
        ];

        return response()->json([
            'data' => $categories,
            'stats' => $stats
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:categories,name',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:2048', // Allow file upload
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('categories', 'public');
        }

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'image' => $imagePath,
        ]);

        return response()->json($category, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        return $category->load('products');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($category->image) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($category->image);
            }
            $validated['image'] = $request->file('image')->store('categories', 'public');
        }

        $category->update($validated);

        return response()->json($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }

    /**
     * Add a product to the category.
     */
    public function addProduct(Category $category, \App\Models\Product $product)
    {
        $product->category_id = $category->id;
        $product->category = $category->name; // Sync legacy string field
        $product->save();

        return response()->json(['message' => 'Product added to category']);
    }

    /**
     * Remove a product from the category.
     */
    public function removeProduct(Category $category, \App\Models\Product $product)
    {
        if ($product->category_id === $category->id) {
            $product->category_id = null;
            $product->category = 'General'; // Default to General or leave empty? User prefers default to general logic.

            // Should we assign it to the 'General' Category model if it exists?
            $generalCategory = Category::where('name', 'General')->first();
            if ($generalCategory) {
                $product->category_id = $generalCategory->id;
            }

            $product->save();
        }
        return response()->json(['message' => 'Product removed from category']);
    }

    /**
     * Get products that are NOT in the specified category.
     */
    public function getProductsNotInCategory(Category $category)
    {
        $products = \App\Models\Product::where(function ($query) use ($category) {
            $query->where('category_id', '!=', $category->id)
                ->orWhereNull('category_id');
        })->get();
        return response()->json($products);
    }
}
