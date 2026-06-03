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
                
                $table->foreignId('scheme_version_id')->constrained('scheme_versions')->onDelete('cascade');
                $table->json('conditions');

                // Lo que se ganan si caen en este rango (puede ser porcentaje o monto fijo)
                $table->decimal('agent_percentage', 5, 2)->default(0);
                $table->decimal('promoter_percentage', 5, 2)->default(0);
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
