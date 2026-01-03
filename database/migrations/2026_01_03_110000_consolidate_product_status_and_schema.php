<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Data Migration: Move is_archived = 1 to status = 'archived'
        // Using raw SQL to ensure it runs before column drop
        DB::table('products')
            ->where('is_archived', true)
            ->update(['status' => 'archived']);

        // 2. Schema Changes
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_archived', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('status');
            $table->string('category')->nullable()->after('stock'); // Approximate position
        });

        // Restore legacy data (approximate)
        DB::table('products')
            ->where('status', 'archived')
            ->update(['is_archived' => true]);
    }
};
