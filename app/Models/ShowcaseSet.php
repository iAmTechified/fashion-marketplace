<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasSlug;

class ShowcaseSet extends Model
{
    use HasSlug;

    protected $fillable = ['name', 'slug', 'description', 'is_active', 'type'];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_showcase_set');
    }

    public function placeholders()
    {
        return $this->hasMany(ShowcasePlaceholder::class);
    }
}
