<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('options')->nullable();
            $table->json('tags')->nullable();
            $table->string('approval_status')->default('pending'); // pending, approved, rejected
            $table->string('status')->default('available'); // available, archived, disabled
            $table->softDeletes(); // for deleted/trash state
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['options', 'tags', 'approval_status', 'status']);
            $table->dropSoftDeletes();
        });
    }
};
