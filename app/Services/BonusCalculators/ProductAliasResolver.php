<?php

namespace App\Services\BonusCalculators;

/**
 * Trait que resuelve alias de productos para los cálculos de bonos.
 *
 * REGLA DE NEGOCIO:
 *   El producto «Vida» usado en la configuración de bonos (requires_product)
 *   es un alias que agrupa dos productos reales del portafolio:
 *     - METLIFE
 *     - PERFECTLIFE
 *
 *   Cuando un esquema de bono requiere «Vida», el conteo de pólizas debe
 *   sumar las de METLIFE + PERFECTLIFE. Cualquiera de los dos satisface
 *   el requisito.
 *
 * Productos reales (definidos en el esquema de comisiones):
 *   - METLIFE     → catalogado como «Vida»
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
        'Vida' => ['METLIFE', 'PERFECTLIFE'],
    ];

    /**
     * Resuelve un alias de producto a los nombres reales de producto en BD.
     *
     * Si el nombre dado es un alias conocido (ej. 'Vida'), retorna el array
     * de productos reales que lo componen.
     * Si no es un alias, retorna un array con el nombre original.
     *
     * Ejemplos:
     *   resolveProductAlias('Vida')       → ['METLIFE', 'PERFECTLIFE']
     *   resolveProductAlias('Primordial') → ['Primordial']
     *   resolveProductAlias('METLIFE')    → ['METLIFE']
     *
     * @param  string  $productType
     * @return string[]
     */
    protected function resolveProductAlias(string $productType): array
    {
        return $this->productAliases[$productType] ?? [$productType];
    }

    /**
     * Verifica si un tipo de producto (real) pertenece a un alias dado.
     *
     * Útil para validaciones donde necesitas saber si una póliza concreta
     * satisface un requisito de producto configurado como alias.
     *
     * @param  string  $actualProductType  El product_type real de la póliza (ej. 'METLIFE').
     * @param  string  $requiredAlias      El alias configurado en el esquema (ej. 'Vida').
     * @return bool
     */
    protected function productMatchesAlias(string $actualProductType, string $requiredAlias): bool
    {
        $resolved = $this->resolveProductAlias($requiredAlias);
        return in_array($actualProductType, $resolved, true);
    }
}
