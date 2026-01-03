<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_name',
        'store_description',
        'store_logo',
        'contact_email',
        'phone_number',
        'address',
        'subaccount_code',
        'bank_name',
        'account_number',
        'account_name',
        'settlement_bank',
        'percentage_charge',
    ];

    /**
     * Get the user that owns the vendor profile.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
