<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SlugRedirect extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'redirectable_id', 'redirectable_type'];

    public function redirectable(): MorphTo
    {
        return $this->morphTo();
    }
}
