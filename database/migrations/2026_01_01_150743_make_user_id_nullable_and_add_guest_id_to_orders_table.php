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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('guest_id')->nullable()->after('user_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Note: Making a column not nullable again might fail if there are null values.
            // But strict rollback would be:
            $table->dropColumn('guest_id');
            // $table->foreignId('user_id')->nullable(false)->change(); 
            // Be careful with change() on foreign keys depending on DB driver capabilities for 'change'
        });
    }
};
