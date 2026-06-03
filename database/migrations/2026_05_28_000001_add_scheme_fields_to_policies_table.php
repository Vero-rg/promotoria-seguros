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
        Schema::table('policies', function (Blueprint $table) {
            $table->string('scheme_type')->nullable()->after('status')->comment('commission or bonus');
            $table->foreignId('scheme_id')->nullable()->after('scheme_type')->constrained('schemes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['scheme_id']);
            $table->dropColumn(['scheme_type', 'scheme_id']);
        });
    }
};
