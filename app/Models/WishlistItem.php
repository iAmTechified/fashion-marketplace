<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WishlistItem extends Model
{
    use HasFactory;

    protected $table = 'wishlist_items';

    protected $fillable = ['wishlist_id', 'product_id'];

    /**
     * Get the wishlist that owns the item.
     */
    public function wishlist()
    {
        return $this->belongsTo(Wishlist::class);
    }

    /**
     * Get the product that is in the wishlist.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
