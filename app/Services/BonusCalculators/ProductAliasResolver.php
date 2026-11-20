<?php

namespace App\Services\BonusCalculators;

/**
 * Trait que resuelve alias de productos para los cálculos de bonos.
 *
 * REGLA DE NEGOCIO:
 *   El producto «Vida» usado en la configuración de bonos (requires_product)
 *   es un alias que agrupa dos productos reales del portafolio:
 *     - METALIFE
 *     - PERFECTLIFE
 *
 *   Cuando un esquema de bono requiere «Vida», el conteo de pólizas debe
 *   sumar las de METALIFE + PERFECTLIFE. Cualquiera de los dos satisface
 *   el requisito.
 *
 * Productos reales (definidos en el esquema de comisiones):
 *   - METALIFE     → catalogado como «Vida»
 *   - PERFECTLIFE → catalogado como «Vida»
 *   - PRIMORDIAL  → catalogado como «Primordial»
 */
trait ProductAliasResolver
{
    /**
     * Mapa de alias → productos reales que cubre.
     *
     * @var array<string, string[]>
     */
    protected array $productAliases = [
        'Vida' => ['METALIFE', 'PERFECTLIFE'],
    ];

    /**
     * Resuelve un alias de producto a los nombres reales de producto en BD,
     * incluyendo el propio alias para políticas que usen el valor literal.
     *
     * Si el nombre dado es un alias conocido (ej. 'Vida'), retorna el array
     * de productos reales que lo componen MÁS el alias mismo.
     * Si no es un alias, retorna un array con el nombre original.
     *
     * Ejemplos:
     *   resolveProductAlias('Vida')       → ['Vida', 'METALIFE', 'PERFECTLIFE']
     *   resolveProductAlias('Primordial') → ['Primordial']
     *   resolveProductAlias('METALIFE')   → ['METALIFE']
     *
     * @param  string  $productType
     * @return string[]
     */
    protected function resolveProductAlias(string $productType): array
    {
        $resolved = $this->productAliases[$productType] ?? null;

        if ($resolved === null) {
            return [$productType];
        }

        // Incluir el alias mismo: políticas con product_type='Vida' también deben contar
        return array_merge([$productType], $resolved);
    }

    /**
     * Verifica si un tipo de producto (real) pertenece a un alias dado.
     *
     * Útil para validaciones donde necesitas saber si una póliza concreta
     * satisface un requisito de producto configurado como alias.
     *
     * @param  string  $actualProductType  El product_type real de la póliza (ej. 'METALIFE').
     * @param  string  $requiredAlias      El alias configurado en el esquema (ej. 'Vida').
     * @return bool
     */
    protected function productMatchesAlias(string $actualProductType, string $requiredAlias): bool
    {
        $resolved = $this->resolveProductAlias($requiredAlias);
        return in_array($actualProductType, $resolved, true);
    }
}
