<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheme_tiers', function (Blueprint $table) {
            $table->decimal('agent_automatic_percentage', 5, 2)->default(0)
                ->after('agent_percentage')
                ->comment('Porcentaje automático para el agente (bono garantizado)');
        });
    }

    public function down(): void
    {
        Schema::table('scheme_tiers', function (Blueprint $table) {
            $table->dropColumn('agent_automatic_percentage');
        });
    }
};
