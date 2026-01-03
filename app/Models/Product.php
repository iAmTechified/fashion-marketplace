<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasSlug;

class Product extends Model
{
    use HasFactory, SoftDeletes, HasSlug;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'price',
        'stock',
        'category_id', // Removed legacy 'category' string
        'image',
        'images',
        'options',
        'tags',
        'approval_status',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'images' => 'array',
        'options' => 'array',
        'tags' => 'array',
    ];

    /**
     * Scope a query to only include products available for customers.
     */
    public function scopeForCustomer($query)
    {
        return $query->where('status', 'available')
            ->where('approval_status', 'approved');
    }

    /**
     * Scope a query to only include products in stock.
     */
    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    /**
     * Get the user (vendor) that owns the product.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order items for the product.
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the options for the product.
     */
    public function productOptions(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function showcaseSets()
    {
        return $this->belongsToMany(ShowcaseSet::class);
    }
}
