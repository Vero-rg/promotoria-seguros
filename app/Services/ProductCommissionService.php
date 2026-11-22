<?php

namespace App\Services;

use App\Models\Scheme;

class ProductCommissionService
{
    /**
     * Mapa producto → { agent_percentage, promoter_percentage } tomado de los
     * esquemas de comisión activos (última versión vigente de cada esquema).
     */
    public function map(): array
    {
        $schemes = Scheme::with(['versions.tiers'])
            ->where('type', 'commission')
            ->where('is_active', true)
            ->get();

        $map = [];

        foreach ($schemes as $scheme) {
            $latestVersion = $scheme->versions->sortByDesc('starts_at')->first();
            if (! $latestVersion || ! $latestVersion->tiers) {
                continue;
            }

            foreach ($latestVersion->tiers as $tier) {
                $conditions = $tier->conditions ?? [];
                $productType = $conditions['product_type'] ?? null;
                if (! $productType) {
                    continue;
                }

                // Solo sobreescribe si no existe aún (primer encuentro gana)
                if (! isset($map[$productType])) {
                    $map[$productType] = [
                        'agent_percentage' => (float) ($tier->agent_percentage ?? 0),
                        'promoter_percentage' => (float) ($tier->promoter_percentage ?? 0),
                    ];
                }
            }
        }

        return $map;
    }
}
