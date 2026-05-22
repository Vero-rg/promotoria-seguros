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
        Schema::create('scheme_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained()->onDelete('cascade');
            
            $table->decimal('min_amount', 12, 2);
            // max_amount es nullable porque el último rango suele ser "De $100,000 en adelante" (sin tope)
            $table->decimal('max_amount', 12, 2)->nullable(); 
            
            // Lo que se ganan si caen en este rango (puede ser porcentaje o monto fijo)
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('fixed_amount', 12, 2)->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheme_tiers');
    }
};
