<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonus_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheme_id')->constrained('schemes')->cascadeOnDelete();
            $table->string('user_type', 20);
            $table->unsignedBigInteger('user_id');
            $table->string('period_month', 7);
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scheme_id', 'user_type', 'user_id', 'period_month'], 'bonus_advances_unique');
            $table->index(['user_type', 'user_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_advances');
    }
};
