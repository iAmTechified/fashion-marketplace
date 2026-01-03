<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    /**
     * Display a listing of the resource (Admin).
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 15);
        $query = Cart::whereNotNull('user_id')
            ->with(['items.product', 'user']);

        $carts = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        // Determine abandoned status (e.g., inactive for > 24 hours)
        $abandonedThreshold = now()->subHours(24);

        $carts->getCollection()->transform(function ($cart) use ($abandonedThreshold) {
            $cart->cart_status = $cart->updated_at < $abandonedThreshold ? 'abandoned' : 'active';
            return $cart;
        });

        // Stats
        $totalAbandonedCarts = Cart::whereNotNull('user_id')
            ->where('updated_at', '<', $abandonedThreshold)
            ->count();

        $monthlyNewCarts = Cart::whereNotNull('user_id')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $totalCartsYearly = Cart::whereNotNull('user_id')
            ->whereYear('created_at', now()->year)
            ->count();

        return response()->json([
            'carts' => $carts,
            'stats' => [
                'total_abandoned_carts' => $totalAbandonedCarts,
                'monthly_new_carts' => $monthlyNewCarts,
                'total_carts_yearly' => $totalCartsYearly
            ]
        ]);
    }

    private function getCart(Request $request)
    {
        $user = auth('sanctum')->user();
        $cartId = $request->input('cart_id') ?? $request->header('X-Cart-ID');

        if ($user) {
            $cart = $user->cart;

            if ($cartId) {
                $anonCart = Cart::where('id', $cartId)->whereNull('user_id')->first();

                if ($anonCart) {
                    if ($cart) {
                        // Merge anonymous cart items into user cart
                        foreach ($anonCart->items as $item) {
                            $existingItem = $cart->items()->where('product_id', $item->product_id)->first();
                            if ($existingItem) {
                                $existingItem->quantity += $item->quantity;
                                $existingItem->save();
                            } else {
                                $item->update(['cart_id' => $cart->id]);
                            }
                        }
                        // Delete the old anonymous cart as it's now empty/merged
                        $anonCart->delete();
                    } else {
                        // Associate anonymous cart with user
                        $anonCart->update(['user_id' => $user->id]);
                        $cart = $anonCart;
                    }
                }
            }

            if (!$cart) {
                $cart = $user->cart()->create();
            }
        } else {
            $cart = null;
            if ($cartId) {
                $cart = Cart::where('id', $cartId)->whereNull('user_id')->first();
            }

            if (!$cart) {
                $cart = Cart::create(['user_id' => null]);
            }
        }

        return $cart;
    }

    /**
     * Get the current user's cart (or anonymous cart).
     */
    public function show(Request $request)
    {
        $cart = $this->getCart($request);

        if ($cart) {
            // Cleanup invalid items
            $cart->items->each(function ($item) {
                $product = $item->product;
                if (!$product || $product->status !== 'available' || $product->approval_status !== 'approved') {
                    $item->delete();
                }
            });
            $cart->load('items.product'); // Reload items after cleanup
        }

        return response()->json($cart);
    }

    /**
     * Add an item to the cart.
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $this->getCart($request);
        $product = Product::find($request->product_id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // 1. Check Availability
        if ($product->status !== 'available' || $product->approval_status !== 'approved') {
            return response()->json(['message' => 'Product is not available for purchase.'], 400);
        }

        // 2. Check Stock
        if ($product->stock < $request->quantity) {
            return response()->json(['message' => "Only {$product->stock} items remaining in stock."], 400);
        }

        // Collect all request parameters except standard ones as options
        $options = $request->except(['product_id', 'quantity', 'cart_id', 'user_id', '_token']);

        // Sort keys to ensure consistent comparison
        ksort($options);

        // Find existing item with same product_id and same options
        $cartItem = $cart->items()
            ->where('product_id', $product->id)
            ->get()
            ->first(function ($item) use ($options) {
                $itemOptions = $item->options ?? [];
                if (is_null($itemOptions))
                    $itemOptions = [];
                ksort($itemOptions);
                return $itemOptions === $options;
            });

        if ($cartItem) {
            $newQuantity = $cartItem->quantity + $request->quantity;
            if ($product->stock < $newQuantity) {
                return response()->json(['message' => "Not enough stock. You already have {$cartItem->quantity} in cart."], 400);
            }
            $cartItem->increment('quantity', $request->quantity);
        } else {
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'options' => count($options) > 0 ? $options : null
            ]);
        }

        return response()->json([
            'message' => 'Item added to cart successfully.',
            'cart_id' => $cart->id,
            'cartItem' => $cartItem
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        if ($product->stock < $request->quantity) {
            return response()->json(['message' => "Only {$product->stock} items remaining in stock."], 400);
        }

        $cart = $this->getCart($request);

        $cartItem = $cart->items()->where('product_id', $product->id)->first();

        if ($cartItem) {
            $cartItem->update(['quantity' => $request->quantity]);
        } else {
            // Logic for creating during update? Rare case but safe to have check
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json([
            'message' => 'Cart updated successfully.',
            'cartItem' => $cartItem,
            'cart_id' => $cart->id
        ]);
    }

    public function destroy(Request $request, Product $product)
    {
        $cart = $this->getCart($request);

        if ($cart) {
            $cartItem = $cart->items()->where('product_id', $product->id)->first();

            if ($cartItem) {
                $cartItem->delete();

                // Move to Wishlist
                $user = auth('sanctum')->user();
                $wishlistId = $request->input('wishlist_id');
                $wishlist = null;

                if ($user) {
                    $wishlist = $user->wishlist;
                    if (!$wishlist)
                        $wishlist = $user->wishlist()->create();
                } else {
                    if ($wishlistId) {
                        $wishlist = Wishlist::where('id', $wishlistId)->whereNull('user_id')->first();
                    }
                    if (!$wishlist) {
                        $wishlist = Wishlist::create(['user_id' => null]);
                    }
                }

                if ($wishlist) {
                    WishlistItem::firstOrCreate([
                        'wishlist_id' => $wishlist->id,
                        'product_id' => $product->id,
                    ]);
                }

                return response()->json([
                    'message' => 'Product removed from cart and moved to wishlist.',
                    'cart_id' => $cart->id,
                    'wishlist_id' => $wishlist ? $wishlist->id : null
                ]);
            }
        }

        return response()->json(['message' => 'Product not found in cart.'], 404);
    }
}
