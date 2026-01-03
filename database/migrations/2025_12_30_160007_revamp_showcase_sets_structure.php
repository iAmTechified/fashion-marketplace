<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('showcase_sets', function (Blueprint $table) {
            $table->string('type')->default('standard')->after('is_active');
        });

        Schema::create('showcase_placeholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('showcase_set_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cta_text')->nullable();
            $table->string('cta_url')->nullable();
            $table->timestamps();
        });

        Schema::create('product_showcase_placeholder', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('showcase_placeholder_id')->constrained('showcase_placeholders')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_showcase_placeholder');
        Schema::dropIfExists('showcase_placeholders');

        Schema::table('showcase_sets', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
