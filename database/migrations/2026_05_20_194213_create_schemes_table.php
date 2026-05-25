<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ej. "Bono de Verano 2026"
            $table->string('code')->unique(); // Ej. "recruitment_monthly"
            $table->string('type'); // 'commission' o 'bonus'
            $table->string('target'); // 'promoter' o 'agent'
            $table->boolean('is_active')->default(true);

            // Reglas Globales
            $table->string('metric_base')->nullable();
            $table->string('frequency')->nullable();
            $table->boolean('requires_anticipos')->default(false);
            $table->json('anticipos_config')->nullable();
            $table->boolean('applies_annual_adjustment')->default(false);
            $table->json('requires_product')->nullable();
            $table->integer('min_product_count')->default(0);
            $table->boolean('requires_mix')->default(false);
            $table->string('dependency_scheme_id')->nullable();
            $table->decimal('min_irp', 5, 2)->default(0);
            $table->decimal('min_collection_efficiency', 5, 2)->default(0);
            $table->json('quarterly_recruits')->nullable();
            $table->json('pna_equivalences')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schemes');
    }
};
