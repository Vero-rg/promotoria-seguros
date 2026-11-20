<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_PRODUCT = 'METLIFE';

    private const NEW_PRODUCT = 'METALIFE';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->renameProduct(self::OLD_PRODUCT, self::NEW_PRODUCT);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->renameProduct(self::NEW_PRODUCT, self::OLD_PRODUCT);
    }

    /**
     * Renombra el producto en pólizas, tiers de esquemas y requisitos de producto.
     */
    private function renameProduct(string $from, string $to): void
    {
        DB::table('policies')
            ->where('product_type', $from)
            ->update(['product_type' => $to]);

        DB::table('scheme_tiers')
            ->whereNotNull('conditions')
            ->orderBy('id')
            ->each(function ($tier) use ($from, $to) {
                $conditions = $this->decodeJson($tier->conditions);

                if ($conditions === null || ($conditions['product_type'] ?? null) !== $from) {
                    return;
                }

                $conditions['product_type'] = $to;
                DB::table('scheme_tiers')->where('id', $tier->id)->update([
                    'conditions' => json_encode($conditions),
                ]);
            });

        DB::table('schemes')
            ->whereNotNull('requires_product')
            ->orderBy('id')
            ->each(function ($scheme) use ($from, $to) {
                $requirements = $this->decodeJson($scheme->requires_product);

                if ($requirements === null) {
                    return;
                }

                $changed = false;

                foreach ($requirements as $index => $requirement) {
                    if (is_array($requirement) && ($requirement['product_type'] ?? null) === $from) {
                        $requirements[$index]['product_type'] = $to;
                        $changed = true;
                    }
                }

                if (! $changed) {
                    return;
                }

                DB::table('schemes')->where('id', $scheme->id)->update([
                    'requires_product' => json_encode($requirements),
                ]);
            });
    }

    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
