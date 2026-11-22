<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las pólizas registradas antes de capturar el conducto de pago se
        // consideran de cobro automático.
        DB::table('policies')
            ->whereNull('payment_method')
            ->update(['payment_method' => 'Automático']);

        Schema::table('policies', function (Blueprint $table) {
            $table->string('payment_method')
                ->default('Automático')
                ->nullable(false)
                ->comment('Conducto de pago (Efectivo, Automático)')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->string('payment_method')
                ->nullable()
                ->default(null)
                ->comment('Conducto de pago (Efectivo, Automático)')
                ->change();
        });
    }
};
