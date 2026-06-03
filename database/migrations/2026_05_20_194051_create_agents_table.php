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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            
            // Relación: Un agente pertenece a un promotor.
            // Usamos nullable() por si algún día tienes un agente directo sin promotor.
            // onDelete('set null') evita que se borre el agente si eliminas a su promotor del sistema.
            $table->foreignId('promoter_id')->nullable()->constrained('promoters')->onDelete('set null');
            
            $table->string('name');
            // Aquí podrás agregar campos específicos del agente
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
