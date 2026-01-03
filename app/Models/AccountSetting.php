<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'settlement_account_details',
        'store_status',
    ];

    /**
     * Get the user that owns the account setting.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
