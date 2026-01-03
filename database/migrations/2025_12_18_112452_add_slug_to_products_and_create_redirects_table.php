<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add slug to products
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // 2. Backfill slugs for existing products
        $products = DB::table('products')->get();
        foreach ($products as $product) {
            $slug = Str::slug($product->name);
            // Ensure uniqueness (simple version for backfill)
            $originalSlug = $slug;
            $count = 1;
            while (DB::table('products')->where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                $slug = $originalSlug . '-' . $count++;
            }

            DB::table('products')
                ->where('id', $product->id)
                ->update(['slug' => $slug]);
        }

        // 3. Make slug not null and unique
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->unique()->change();
        });

        // 4. Create redirects table
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->index(); // The old slug
            $table->morphs('redirectable'); // redirectable_id, redirectable_type
            $table->timestamps();

            $table->unique(['slug', 'redirectable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
