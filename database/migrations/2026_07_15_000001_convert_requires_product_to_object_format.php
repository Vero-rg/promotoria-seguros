<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert existing bonus schemes' requires_product from flat array of strings
     * to array of objects [{product_type, min_count}], using the scheme's current
     * min_product_count value for each product listed.
     */
    public function up(): void
    {
        $schemes = DB::table('schemes')
            ->where('type', 'bonus')
            ->whereNotNull('requires_product')
            ->get();

        foreach ($schemes as $scheme) {
            $requiresProduct = json_decode($scheme->requires_product, true);

            // Skip if already in new format (array of objects)
            if (empty($requiresProduct) || !is_array($requiresProduct)) {
                continue;
            }

            $firstItem = $requiresProduct[0] ?? null;

            // Already in new format: array of {product_type, min_count}
            if (is_array($firstItem) && isset($firstItem['product_type'])) {
                continue;
            }

            // Old format: flat array of strings — convert
            $minCount = (int) ($scheme->min_product_count ?? 0);
            $newFormat = [];

            foreach ($requiresProduct as $productType) {
                if (is_string($productType) && $productType !== '') {
                    $newFormat[] = [
                        'product_type' => $productType,
                        'min_count' => $minCount > 0 ? $minCount : 1,
                    ];
                }
            }

            DB::table('schemes')
                ->where('id', $scheme->id)
                ->update([
                    'requires_product' => json_encode($newFormat),
                ]);
        }
    }

    /**
     * Revert: convert back to flat array of strings + separate min_product_count.
     */
    public function down(): void
    {
        $schemes = DB::table('schemes')
            ->where('type', 'bonus')
            ->whereNotNull('requires_product')
            ->get();

        foreach ($schemes as $scheme) {
            $requiresProduct = json_decode($scheme->requires_product, true);

            if (empty($requiresProduct) || !is_array($requiresProduct)) {
                continue;
            }

            $firstItem = $requiresProduct[0] ?? null;

            // Already in old format
            if (is_string($firstItem)) {
                continue;
            }

            // New format: array of objects — convert back
            $productTypes = [];
            $maxMinCount = 0;

            foreach ($requiresProduct as $item) {
                if (isset($item['product_type'])) {
                    $productTypes[] = $item['product_type'];
                    $maxMinCount = max($maxMinCount, (int) ($item['min_count'] ?? 1));
                }
            }

            DB::table('schemes')
                ->where('id', $scheme->id)
                ->update([
                    'requires_product' => json_encode($productTypes),
                    'min_product_count' => $maxMinCount,
                ]);
        }
    }
};
