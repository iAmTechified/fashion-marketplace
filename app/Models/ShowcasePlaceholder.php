<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowcasePlaceholder extends Model
{
    protected $fillable = ['showcase_set_id', 'title', 'description', 'cta_text', 'cta_url'];

    public function showcaseSet()
    {
        return $this->belongsTo(ShowcaseSet::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_showcase_placeholder');
    }
}
