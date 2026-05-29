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
            // Quitar columnas de esquema que ya no se usan
            $table->dropForeign(['scheme_id']);
            $table->dropColumn(['scheme_type', 'scheme_id']);

            // Nuevas columnas para producto y comisión del promotor
            $table->string('product_type')->nullable()->after('status')->comment('Tipo de producto (METLIFE, PERFECTLIFE, etc.)');
            $table->decimal('promoter_commission_percentage', 5, 2)->default(0)->after('commission_amount')->comment('% comisión del promotor');
            $table->decimal('promoter_commission_amount', 12, 2)->default(0)->after('promoter_commission_percentage')->comment('Monto comisión del promotor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn(['product_type', 'promoter_commission_percentage', 'promoter_commission_amount']);
            $table->string('scheme_type')->nullable()->after('status');
            $table->foreignId('scheme_id')->nullable()->after('scheme_type')->constrained('schemes')->nullOnDelete();
        });
    }
};
