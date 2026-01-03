<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WishlistController extends Controller
{
    private function getWishlist(Request $request)
    {
        $user = auth('sanctum')->user();
        $wishlistId = $request->input('wishlist_id') ?? $request->header('X-Wishlist-ID');

        if ($user) {
            $wishlist = $user->wishlist;

            if ($wishlistId) {
                // Find anonymous wishlist
                $anonWishlist = Wishlist::where('id', $wishlistId)->whereNull('user_id')->first();

                if ($anonWishlist) {
                    if ($wishlist) {
                        // Merge anonymous wishlist items into user wishlist
                        foreach ($anonWishlist->items as $item) {
                            $existingItem = $wishlist->items()->where('product_id', $item->product_id)->first();
                            if (!$existingItem) {
                                $item->update(['wishlist_id' => $wishlist->id]);
                            }
                            // If it exists, we just leave it and the anon item will be deleted when anonWishlist is deleted
                            // Or we should delete duplicates manually? 
                            // Since we update relationship, if we don't update, it stays with anonWishlist.
                        }
                        // Delete the old anonymous wishlist and its remaining items (duplicates)
                        $anonWishlist->delete(); // cascading delete should handle items
                    } else {
                        // Associate anonymous wishlist with user
                        $anonWishlist->update(['user_id' => $user->id]);
                        $wishlist = $anonWishlist;
                    }
                }
            }

            if (!$wishlist) {
                $wishlist = $user->wishlist()->create();
            }
        } else {
            $wishlist = null;
            if ($wishlistId) {
                $wishlist = Wishlist::where('id', $wishlistId)->whereNull('user_id')->first();
            }

            if (!$wishlist) {
                $wishlist = Wishlist::create(['user_id' => null]);
            }
        }

        return $wishlist;
    }

    public function index(Request $request)
    {
        $wishlist = $this->getWishlist($request);

        $wishlist->load('items.product');

        return response()->json($wishlist);
    }

    public function store(Request $request, Product $product)
    {
        $wishlist = $this->getWishlist($request);

        $wishlistItem = $wishlist->items()->where('product_id', $product->id)->first();

        if ($wishlistItem) {
            return response()->json([
                'message' => 'Product is already in your wishlist.',
                'wishlist_id' => $wishlist->id,
                'wishlistItem' => $wishlistItem
            ], 409);
        }

        $wishlistItem = WishlistItem::create([
            'wishlist_id' => $wishlist->id,
            'product_id' => $product->id,
        ]);

        return response()->json([
            'message' => 'Product added to wishlist.',
            'wishlist_id' => $wishlist->id,
            'wishlistItem' => $wishlistItem
        ]);
    }

    public function destroy(Request $request, Product $product)
    {
        $wishlist = $this->getWishlist($request);

        if ($wishlist) {
            $deleted = $wishlist->items()->where('product_id', $product->id)->delete();

            if ($deleted) {
                return response()->json([
                    'message' => 'Product removed from wishlist.',
                    'wishlist_id' => $wishlist->id
                ]);
            }
        }

        return response()->json(['message' => 'Product not found in wishlist.'], 404);
    }
}
