<?php

namespace App\Services\BonusCalculators;

use App\Models\Agent;
use App\Models\Promoter;
use App\Models\Scheme;
use Carbon\Carbon;

/**
 * Interfaz base para todas las calculadoras de bonos (Strategy Pattern).
 *
 * Cada bono del sistema implementa esta interfaz, respetando el principio
 * de Responsabilidad Única: una calculadora = un tipo de bono.
 */
interface BonusCalculatorInterface
{
    /**
     * Calcula si un usuario (Agent o Promoter) alcanza un esquema de bono
     * dentro de un periodo específico.
     *
     * @param  Agent|Promoter  $user         El usuario a evaluar (único roles: Agent o Promoter).
     * @param  Scheme           $scheme       El esquema de bono con sus versiones y tiers cargados.
     * @param  Carbon           $periodStart  Inicio del periodo de evaluación.
     * @param  Carbon           $periodEnd    Fin del periodo de evaluación.
     *
     * @return array{
     *     is_achieved: bool,
     *     amount: float,
     *     tier_index: int|null,
     *     tier_data: array|null,
     *     progress: array{
     *         metric_label: string,
     *         current_value: float,
     *         required_value: float
     *     },
     *     progress_breakdown: array<int, array{
     *         label: string,
     *         current: float|int,
     *         target: float|int,
     *         met: bool
     *     }>,
     *     details: array
     * }
     */
    public function calculate(
        Agent|Promoter $user,
        Scheme $scheme,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array;
}
