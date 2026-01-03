<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{


    /**
     * Display a listing of the resource with advanced filtering.
     */
    public function index(Request $request)
    {
        $query = Product::query();

        // 1. Search (Name or Description)
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // 2. Category Filter (ID, Slug, Title/Name)
        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->has('category_slug')) {
            $slug = $request->input('category_slug');
            $query->whereHas('category', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
        }

        if ($request->has('category_title')) {
            $title = $request->input('category_title');
            $query->whereHas('category', function ($q) use ($title) {
                $q->where('name', $title);
            });
        }

        // General 'category' filter
        if ($request->has('category')) {
            $cat = $request->input('category');
            if (is_numeric($cat)) {
                $query->where('category_id', $cat);
            } else {
                $query->whereHas('category', function ($q) use ($cat) {
                    $q->where('slug', $cat)->orWhere('name', $cat);
                });
            }
        }

        // 3. Tags Filter
        if ($request->has('tags')) {
            $tags = $request->input('tags');
            if (is_string($tags)) {
                $tags = explode(',', $tags);
            }
            if (is_array($tags) && count($tags) > 0) {
                $query->where(function ($q) use ($tags) {
                    foreach ($tags as $tag) {
                        $q->whereJsonContains('tags', trim($tag));
                    }
                });
            }
        }

        // 4. Options Filter (Name & Value)
        if ($request->has('options')) {
            $options = $request->input('options');
            if (is_string($options)) {
                $decoded = json_decode($options, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $options = $decoded;
                }
            }

            if (is_array($options)) {
                foreach ($options as $key => $val) {
                    if (is_string($key)) {
                        $query->whereHas('productOptions', function ($q) use ($key, $val) {
                            $q->where('name', $key)
                                ->where('values', 'like', "%{$val}%");
                        });
                    } elseif (is_array($val) && isset($val['name']) && isset($val['value'])) {
                        $query->whereHas('productOptions', function ($q) use ($val) {
                            $q->where('name', $val['name'])
                                ->where('values', 'like', "%{$val['value']}%");
                        });
                    }
                }
            }
        }

        // 5. Price Range Filter
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->input('min_price'));
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->input('max_price'));
        }

        $perPage = $request->input('per_page', 15);
        $query->with(['category', 'productOptions', 'user']);

        // Enforce customer scope for public listing
        $query->forCustomer();

        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    /**
     * Display a listing of products for admin with stats.
     */
    public function adminIndex(Request $request)
    {
        if ($request->user() && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized. Only admins can view all products.'], 403);
        }

        $perPage = $request->input('per_page', 15);

        // Fetch products with sales volume and relations
        $products = Product::with(['category', 'user'])
            ->withSum('orderItems as sales_volume', 'quantity')
            ->paginate($perPage);

        // Stats
        $monthlyTopProduct = \Illuminate\Support\Facades\DB::table('order_items')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereMonth('order_items.created_at', now()->month)
            ->whereYear('order_items.created_at', now()->year)
            ->select('products.id', 'products.name', \Illuminate\Support\Facades\DB::raw('SUM(order_items.quantity) as monthly_sales'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('monthly_sales')
            ->first();

        $stats = [
            'total_products' => Product::count(),
            'monthly_top_performing_product' => $monthlyTopProduct,
            'total_sales_volume' => \App\Models\OrderItem::sum('quantity'),
            'low_stock_products' => Product::where('stock', '<', 6)->count(),
        ];

        return response()->json([
            'data' => $products,
            'stats' => $stats
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'], // WYSIWYG content
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:255'],
            'images' => ['nullable', 'array', 'max:10'], // At most 10 images
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:2048'], // Validate each image
            'options' => ['nullable'], // Dynamic options can be array or JSON string
            'tags' => ['nullable', 'array'],
        ]);

        // Handle Category Logic
        // If no category is set, default to 'General'
        $categoryName = !empty($validated['category']) ? $validated['category'] : 'General';

        $categoryModel = Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Str::slug($categoryName)]
        );

        // Handle Image Uploads
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                // 1. Store Locally (Backup / Reserved storage)
                $image->store('products', 'public');

                // 2. Upload to Cloudinary
                $cloudinaryImage = $image->storeOnCloudinary('products');
                $url = $cloudinaryImage->getSecurePath();

                $imagePaths[] = $url;
            }
        }

        // Create Product
        $user = Auth::user();

        if (!$user || $user->role !== 'vendor') {
            // Development Fallback: Use or Create 'DuaDua Store' vendor
            // This is temporary until authentication is fully integrated on the frontend
            $user = \App\Models\User::firstOrCreate(
                ['email' => 'store@duadua.com'],
                [
                    'name' => 'DuaDua Store',
                    'password' => bcrypt('password'), // Default password
                    'role' => 'vendor'
                ]
            );
        }

        $product = $user->products()->create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'price' => $validated['price'],
            'stock' => $validated['stock'],
            // 'category' => $categoryName, // REMOVED LEGACY
            'category_id' => $categoryModel->id,
            'image' => count($imagePaths) > 0 ? $imagePaths[0] : null,
            'images' => $imagePaths,
            'tags' => $validated['tags'] ?? [],
            'approval_status' => 'pending',
            'status' => 'available',
        ]);

        // Save Options to ProductOptions table
        if (!empty($validated['options'])) {
            // Check if options is a JSON string (from multipart form-data)
            $optionsData = is_string($validated['options']) ? json_decode($validated['options'], true) : $validated['options'];

            // Or if it came as array from form-data validation (laravel often handles array[] inputs automatically but sometimes not if nested JSON)
            // Given the input example: [{"name": "Size", "values": ["S", "M"]}]
            // Ensure we have an array
            if (is_array($optionsData)) {
                foreach ($optionsData as $option) {
                    if (isset($option['name']) && isset($option['values'])) {
                        $product->productOptions()->create([
                            'name' => $option['name'],
                            'values' => $option['values']
                        ]);
                    }
                }
            }
        }

        return response()->json($product->load('productOptions'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        if (Auth::id() !== $product->user_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'category' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'string'],
        ]);

        $product->update($request->all());

        return response()->json($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        if (Auth::id() !== $product->user_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

    /**
     * Update the status of the specified product.
     */
    public function updateStatus(Request $request, Product $product)
    {
        $request->validate([
            'status' => ['sometimes', 'required', 'string', 'in:available,unavailable,archived'],
            'approval_status' => ['sometimes', 'required', 'string', 'in:pending,approved,rejected'],
        ]);

        $user = Auth::user();

        // 1. Approval Status Update (Admin Only)
        if ($request->has('approval_status')) {
            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Unauthorized. Only admins can update approval status.'], 403);
            }
            $product->approval_status = $request->approval_status;
        }

        // 2. Availability Status Update (Vendor or Admin)
        if ($request->has('status')) {
            if ($user->role !== 'admin' && $user->id !== $product->user_id) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            $product->status = $request->status;
        }

        $product->save();

        return response()->json([
            'message' => 'Product status updated successfully.',
            'product' => $product
        ]);
    }

    /**
     * Update the stock status of the specified product.
     */
    public function updateStock(Request $request, Product $product)
    {
        if (Auth::id() !== $product->user_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        $product->update(['stock' => $request->stock]);

        return response()->json($product);
    }

    public function search(Request $request)
    {
        return $this->index($request);
    }

    public function findByTags(Request $request)
    {
        return $this->index($request);
    }

    /**
     * Fetch products in a specific category.
     * This example assumes a 'category_id' on the products table.
     */
    public function findByCategory(Category $category)
    {
        $perPage = request()->input('per_page', 15);
        $products = Product::where('category_id', $category->id)
            ->forCustomer()
            ->paginate($perPage);

        return response()->json($products);
    }

    /**
     * Fetch all products that belong to a specific user (vendor).
     */
    public function findByUser(User $user)
    {
        // Optional: Check if the user is a vendor
        if ($user->role !== 'vendor') {
            return response()->json(['message' => 'This user is not a vendor.'], 403);
        }

        $perPage = request()->input('per_page', 15);
        $products = Product::where('user_id', $user->id)
            ->forCustomer()
            ->paginate($perPage);

        return response()->json($products);
    }

    /**
     * Fetch products for the currently logged-in vendor.
     * This method includes comprehensive filtering.
     */
    public function myProducts(Request $request)
    {
        $user = Auth::user();

        // Ensure the user is a logged-in vendor
        if (!$user || $user->role !== 'vendor') {
            return response()->json(['message' => 'Unauthorized. You must be a vendor.'], 403);
        }

        $products = Product::query()->where('user_id', $user->id);

        // Filter by search query
        if ($request->has('search')) {
            $products->where(function (Builder $query) use ($request) {
                $searchTerm = $request->input('search');
                $query->where('name', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Filter by category
        if ($request->has('category_id')) {
            $products->where('category_id', $request->input('category_id'));
        }

        // Filter by tags (assuming tags are in a JSON column)
        if ($request->has('tags')) {
            $tags = is_array($request->input('tags')) ? $request->input('tags') : explode(',', $request->input('tags'));
            foreach ($tags as $tag) {
                $products->whereJsonContains('tags', trim($tag));
            }
        }

        // Filter by stock status (assuming a 'stock_status' column: e.g., 'in_stock', 'out_of_stock')
        if ($request->has('stock_status')) {
            $products->where('stock_status', $request->input('stock_status'));
        }

        // By default, return non-archived products unless specified
        $products->where('is_archived', $request->input('is_archived', false));


        $perPage = $request->input('per_page', 15);
        return response()->json($products->paginate($perPage));
    }

    /**
     * Fetch only archived products for the currently logged-in vendor.
     */
    public function archived(Request $request)
    {
        $user = Auth::user();

        if (!$user || $user->role !== 'vendor') {
            return response()->json(['message' => 'Unauthorized. You must be a vendor.'], 403);
        }

        $perPage = $request->input('per_page', 15);
        $products = Product::where('user_id', $user->id)
            ->where('status', 'archived')
            ->paginate($perPage);

        return response()->json($products);
    }

    /**
     * Fetch products by status (Admin).
     */
    public function getProductsByStatus(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'nullable|string',
            'approval_status' => 'nullable|string'
        ]);

        $query = Product::query()->with(['category', 'user']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('approval_status')) {
            $query->where('approval_status', $request->approval_status);
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }
    /**
     * Fetch related products based on category and tags.
     */
    public function related(Product $product)
    {
        $query = Product::where('id', '!=', $product->id)
            ->forCustomer();

        // Prioritize same category
        $query->where(function ($q) use ($product) {
            $q->where('category_id', $product->category_id);

            // If tags exist, also look for matching tags
            if (!empty($product->tags) && is_array($product->tags)) {
                $q->orWhere(function ($subQ) use ($product) {
                    foreach ($product->tags as $tag) {
                        $subQ->orWhereJsonContains('tags', $tag);
                    }
                });
            }
        });

        // Limit results
        $limit = request()->input('limit', 5);
        $relatedProducts = $query->inRandomOrder()->take($limit)->get();
        // Use paginate if pagination is strictly required, but usually "related" is a small list
        // $relatedProducts = $query->paginate($limit); 

        return response()->json($relatedProducts);
    }

    /**
     * Perform bulk actions on products.
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'action' => ['required', 'string', 'in:enable,disable,archive,unarchive,delete,update_status,approve,reject'],
            'status' => ['required_if:action,update_status', 'string', 'in:available,unavailable,archived'],
        ]);

        $productIds = $request->input('product_ids');
        $action = $request->input('action');
        $user = Auth::user();

        $query = Product::whereIn('id', $productIds);

        // Security Check: If not admin, restrict to own products
        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);

            // Vendors cannot perform approval actions
            if (in_array($action, ['approve', 'reject'])) {
                return response()->json(['message' => 'Unauthorized action.'], 403);
            }
        }

        $count = $query->count();
        if ($count === 0) {
            return response()->json(['message' => 'No valid products found for this action.'], 404);
        }

        $message = 'Products processed successfully.';

        switch ($action) {
            case 'enable': // Legacy
                $query->update(['status' => 'available']);
                $message = 'Products set to available.';
                break;
            case 'disable': // Legacy
                $query->update(['status' => 'unavailable']);
                $message = 'Products set to unavailable.';
                break;
            case 'archive':
                $query->update(['status' => 'archived']);
                $message = 'Products archived.';
                break;
            case 'unarchive': // Legacy, treat as available? or just restore from archive?
                $query->update(['status' => 'available']);
                $message = 'Products unarchived (set to available).';
                break;
            case 'delete':
                $query->delete();
                $message = 'Products deleted.';
                break;
            case 'update_status': // Generic status update
                $status = $request->input('status');
                $query->update(['status' => $status]);
                $message = "Products status updated to $status.";
                break;
            case 'approve':
                // logic check handled above (admin only)
                $query->update(['approval_status' => 'approved']);
                $message = 'Products approved.';
                break;
            case 'reject':
                // logic check handled above (admin only)
                $query->update(['approval_status' => 'rejected']);
                $message = 'Products rejected.';
                break;
        }

        return response()->json(['message' => $message]);
    }
}
