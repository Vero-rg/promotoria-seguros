<?php

namespace App\Services;

use App\Models\Scheme;
use App\Models\SchemeVersion;
use Carbon\Carbon;

class CommissionService
{
    /**
     * Calcula la comisión o bono correspondiente para un esquema específico.
     *
     * @param string $code Código único del esquema (ej. 'recruitment_monthly')
     * @param string $date Fecha del evento para buscar la vigencia correcta
     * @param array $context Datos actuales a evaluar (ej. ['total_people' => 3, 'amount' => 125000])
     * @return array|null Retorna un arreglo con el porcentaje o monto fijo ganado, o null si no aplica ninguna
     */
    public function calculate(string $code, string $date, array $context): ?array
    {
        $fecha = Carbon::parse($date);

        // 1. Buscar la versión del esquema que estaba vigente en la fecha del evento
        $version = SchemeVersion::whereHas('scheme', function ($query) use ($code) {
                $query->where('code', $code)->where('is_active', true);
            })
            ->where('starts_at', '<=', $fecha)
            ->where(function ($query) use ($fecha) {
                $query->where('ends_at', '>=', $fecha)->orWhereNull('ends_at');
            })
            ->first();

        if (!$version) {
            return null; // No hay ninguna configuración activa para esa fecha
        }

        // 2. Recorrer todos los rangos (tiers) de esa versión para encontrar el ganador
        foreach ($version->tiers as $tier) {
            if ($this->evaluateConditions($tier->conditions, $context)) {
                return [
                    'percentage' => $tier->percentage,
                    'fixed_amount' => $tier->fixed_amount,
                ];
            }
        }

        return null; // Cumplió la fecha, pero los datos no alcanzaron ningún rango
    }

    /**
     * Evalúa dinámicamente si los datos del contexto cumplen con el JSON de condiciones.
     */
    private function evaluateConditions(array $conditions, array $context): bool
    {
        // Validación de personas (mínimo y máximo)
        if (isset($conditions['min_people'])) {
            $people = $context['total_people'] ?? 0;
            if ($people < $conditions['min_people'] || $people > ($conditions['max_people'] ?? INF)) {
                return false;
            }
        }

        // Validación de montos de dinero (mínimo y máximo)
        if (isset($conditions['min_amount'])) {
            $amount = $context['amount'] ?? 0;
            if ($amount < $conditions['min_amount'] || $amount > ($conditions['max_amount'] ?? INF)) {
                return false;
            }
        }

        // Validación por tipo de producto
        if (isset($conditions['product_type'])) {
            $productType = $context['product_type'] ?? '';
            if ($productType !== $conditions['product_type']) {
                return false;
            }
        }

        // Si pasó todos los ifs de las condiciones que venían en el JSON, es verdadero
        return true;
    }
}