<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure we don't duplicate if run multiple times or if exists
        if (!User::where('email', 'admin@duadua.com')->exists()) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@duadua.com',
                'password' => Hash::make('#AdminDuaDua'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::where('email', 'admin@duadua.com')->delete();
    }
};
