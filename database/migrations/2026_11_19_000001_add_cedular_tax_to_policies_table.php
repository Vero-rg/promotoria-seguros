<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->decimal('cedular_tax', 5, 2)->default(5.00)->comment('Impuesto cedular (%)');
        });

        // El costo de facturación ya no tiene un valor por defecto: es obligatorio capturarlo.
        Schema::table('policies', function (Blueprint $table) {
            $table->decimal('billing_retention', 5, 2)->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('policies')->whereNull('billing_retention')->update(['billing_retention' => 5.00]);

        Schema::table('policies', function (Blueprint $table) {
            $table->decimal('billing_retention', 5, 2)->default(5.00)->nullable(false)->change();
        });

        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('cedular_tax');
        });
    }
};
