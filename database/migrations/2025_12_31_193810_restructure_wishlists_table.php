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
        // 1. Rename existing table
        if (Schema::hasTable('wishlists') && !Schema::hasTable('wishlist_items')) {
            Schema::rename('wishlists', 'wishlist_items');

            // 1b. Drop old foreign key to avoid name collision
            Schema::table('wishlist_items', function (Blueprint $table) {
                $table->dropForeign('wishlists_user_id_foreign');
            });
        }

        // 2. Create new parent table
        if (!Schema::hasTable('wishlists')) {
            Schema::create('wishlists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
                $table->timestamps();
            });
        }

        // 3. Add wishlist_id to items
        if (Schema::hasTable('wishlist_items') && !Schema::hasColumn('wishlist_items', 'wishlist_id')) {
            Schema::table('wishlist_items', function (Blueprint $table) {
                $table->foreignId('wishlist_id')->nullable()->after('id')->constrained('wishlists')->onDelete('cascade');
            });
        }

        // 4. Migrate Data
        $items = DB::table('wishlist_items')->whereNotNull('user_id')->get();
        $userIds = $items->pluck('user_id')->unique();

        foreach ($userIds as $userId) {
            // check if wishlist exists
            $wishlistId = DB::table('wishlists')->where('user_id', $userId)->value('id');

            if (!$wishlistId) {
                $wishlistId = DB::table('wishlists')->insertGetId([
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('wishlist_items')
                ->where('user_id', $userId)
                ->update(['wishlist_id' => $wishlistId]);
        }

        // 5. Cleanup items table
        try {
            Schema::table('wishlist_items', function (Blueprint $table) {
                $table->dropForeign('wishlists_user_id_foreign');
            });
        } catch (\Exception $e) {
            // FK might not exist or already dropped
        }

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
        });

        // Restore user_id
        $items = DB::table('wishlist_items')->get();
        foreach ($items as $item) {
            if ($item->wishlist_id) {
                $parent = DB::table('wishlists')->find($item->wishlist_id);
                if ($parent && $parent->user_id) {
                    DB::table('wishlist_items')->where('id', $item->id)->update(['user_id' => $parent->user_id]);
                }
            }
        }

        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropForeign(['wishlist_id']);
            $table->dropColumn('wishlist_id');
        });

        Schema::dropIfExists('wishlists');
        Schema::rename('wishlist_items', 'wishlists');
    }
};
