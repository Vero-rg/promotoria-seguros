<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('promoters', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('photo');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('name');
            $table->boolean('is_active')->default(true)->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('promoters', function (Blueprint $table) {
            $table->dropColumn(['photo', 'is_active']);
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['photo', 'is_active']);
        });
    }
};
