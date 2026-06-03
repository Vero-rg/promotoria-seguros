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
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            // Ligado a la tabla agents
            $table->foreignId('agent_id')->constrained('agents')->onDelete('cascade');
            
            $table->string('policy_number')->unique();
            $table->date('issue_date');
            
            // Montos y porcentajes (10 a 12 dígitos en total, 2 decimales para dinero/porcentajes)
            $table->decimal('premium_amount', 12, 2); 
            $table->decimal('commission_percentage', 5, 2)->default(0); 
            $table->decimal('commission_amount', 12, 2)->default(0); 
            
            // Deducciones fijas por defecto
            $table->decimal('isr_retention', 5, 2)->default(10.00); 
            $table->decimal('billing_retention', 5, 2)->default(5.00); 
            
            $table->string('status')->default('activa'); // Activa, No tomada, Pagada
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
