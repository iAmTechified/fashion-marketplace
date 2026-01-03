<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount',
        'status',
        'transaction_id',
    ];

    /**
     * Get the order that the settlement belongs to.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
