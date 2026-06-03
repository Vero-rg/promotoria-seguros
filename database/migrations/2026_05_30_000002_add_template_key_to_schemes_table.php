<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('schemes', function (Blueprint $table) {
            $table->string('template_key')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('schemes', function (Blueprint $table) {
            $table->dropColumn('template_key');
        });
    }
};
