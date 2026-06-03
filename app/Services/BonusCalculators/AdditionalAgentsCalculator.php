<?php

namespace App\Services\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use Carbon\Carbon;

/**
 * Calcula el Bono Adicional por Agentes con Compensación Trimestral.
 *
 * REGLAS DE NEGOCIO:
 *   1. El Promoter DEBE haber ganado el bono prerequisite (dependency_scheme_id).
 *   2. Solo cuentan agentes activos bajo el promotor.
 *   3. El porcentaje se aplica sobre la PP total acumulada de la promotoría.
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_agents  (int)       Mínimo de agentes calificados.
 *   - max_agents  (int|null)  Máximo de agentes calificados (null = sin límite).
 */
class AdditionalAgentsCalculator implements BonusCalculatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function calculate(
        Agent|Promoter $user,
        Scheme $scheme,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        if ($user instanceof Agent) {
            return $this->notAchieved('El Bono Adicional por Agentes solo aplica a Promotores.');
        }

        // ── 1. Versión activa ──────────────────────────────────────────
        $version = $this->resolveActiveVersion($scheme, $periodStart, $periodEnd);
        if ($version === null) {
            return $this->notAchieved('No hay versión vigente del esquema para el periodo solicitado.');
        }

        $tiers = $version->tiers()->get()
            ->sortByDesc(fn (SchemeTier $t) => (float) ($t->promoter_percentage ?? 0))
            ->values();

        if ($tiers->isEmpty()) {
            return $this->notAchieved('El esquema no tiene tiers configurados.');
        }

        // ── 2. Verificar dependencia (dependency_scheme_id) ─────────────
        $dependencyMet = true; // El orquestador ya verificó; aquí solo documentamos

        if (!empty($scheme->dependency_scheme_id)) {
            $prerequisite = Scheme::where('template_key', $scheme->dependency_scheme_id)
                ->where('is_active', true)
                ->first();

            if ($prerequisite === null) {
                return $this->notAchieved(
                    reason: "El bono prerequisite ({$scheme->dependency_scheme_id}) no está activo o no existe.",
                );
            }

            $prerequisiteName = $prerequisite->name;
        } else {
            $prerequisiteName = null;
        }

        // ── 3. Métricas ─────────────────────────────────────────────────
        $activeAgentCount = $this->countActiveAgents($user, $periodEnd);
        $totalPP = $this->calculateTotalPP($user, $periodStart, $periodEnd);

        // ── 4. Evaluar tiers (mejor porcentaje primero) ──────────────────
        foreach ($tiers as $index => $tier) {
            $conditions = $tier->conditions ?? [];

            $minAgents = (int) ($conditions['min_agents'] ?? 0);
            $maxAgents = isset($conditions['max_agents'])
                ? (int) $conditions['max_agents']
                : PHP_INT_MAX;

            if ($activeAgentCount >= $minAgents && $activeAgentCount <= $maxAgents) {
                $amount = $this->calculateAmount($tier, $user, $totalPP);

                return [
                    'is_achieved' => true,
                    'amount'      => $amount,
                    'tier_index'  => $index,
                    'tier_data'   => $tier->toArray(),
                    'progress'    => [
                        'metric_label'   => 'Agentes Calificados',
                        'current_value'  => $activeAgentCount,
                        'required_value' => $minAgents,
                    ],
                    'progress_breakdown' => [
                        [
                            'label'   => $prerequisiteName ?? 'Bono Producción 1er Año de Vida Trimestral',
                            'current' => $dependencyMet ? 1 : 0,
                            'target'  => 1,
                            'met'     => $dependencyMet,
                        ],
                        [
                            'label'   => 'Agentes Activos Calificados',
                            'current' => $activeAgentCount,
                            'target'  => $minAgents,
                            'met'     => $activeAgentCount >= $minAgents,
                        ],
                        [
                            'label'   => 'PP Total del Equipo ($)',
                            'current' => round($totalPP, 2),
                            'target'  => 0,
                            'met'     => true,
                        ],
                    ],
                    'details'     => [
                        'calculator'        => static::class,
                        'version_name'      => $version->version_name,
                        'active_agents'     => $activeAgentCount,
                        'total_pp'          => round($totalPP, 2),
                        'prerequisite_met'  => $prerequisiteName !== null,
                        'prerequisite_name' => $prerequisiteName,
                    ],
                ];
            }
        }

        return $this->notAchieved(
            reason: "Agentes activos: {$activeAgentCount} — sin tier coincidente.",
            agentCount: $activeAgentCount,
            totalPp: $totalPP,
            minRequired: $tiers->min(fn (SchemeTier $t) => (int) ($t->conditions['min_agents'] ?? PHP_INT_MAX)),
            prerequisiteName: $prerequisiteName,
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function countActiveAgents(Promoter $promoter, Carbon $periodEnd): int
    {
        return $promoter->agents()->activeInPeriod($periodEnd->toDateString())->count();
    }

    /**
     * Calcula la PP total (Prima Pagada) de todos los agentes activos/en periodo
     * del promotor en el rango de fechas.
     */
    private function calculateTotalPP(Promoter $promoter, Carbon $start, Carbon $end): float
    {
        $agentIds = $promoter->agents()->activeInPeriod($end->toDateString())->pluck('id');
        if ($agentIds->isEmpty()) {
            return 0.0;
        }

        return (float) Policy::whereIn('agent_id', $agentIds)
            ->whereBetween('issue_date', [$start, $end])
            ->sum('premium_amount');
    }

    private function resolveActiveVersion(Scheme $scheme, Carbon $start, Carbon $end): ?SchemeVersion
    {
        $versions = $scheme->relationLoaded('versions')
            ? $scheme->versions
            : $scheme->versions()->get();

        $active = $versions->filter(function (SchemeVersion $v) use ($start, $end) {
            $starts = Carbon::parse($v->starts_at);
            $ends   = $v->ends_at ? Carbon::parse($v->ends_at) : null;
            if ($starts->greaterThan($end)) return false;
            if ($ends !== null && $ends->lessThan($start)) return false;
            return true;
        });

        return $active->sortByDesc('starts_at')->first();
    }

    private function calculateAmount(SchemeTier $tier, Agent|Promoter $user, float $base): float
    {
        if (!empty($tier->fixed_amount) && (float) $tier->fixed_amount > 0) {
            return (float) $tier->fixed_amount;
        }
        $percentage = $user instanceof Agent
            ? (float) ($tier->agent_percentage ?? 0)
            : (float) ($tier->promoter_percentage ?? 0);
        return round($base * ($percentage / 100), 2);
    }

    private function notAchieved(
        string $reason,
        int $agentCount = 0,
        float $totalPp = 0.0,
        ?int $minRequired = null,
        ?string $prerequisiteName = null,
    ): array {
        $breakdown = [];

        // Barra de dependencia (si aplica — el orquestador ya verificó, así que va 1/1)
        if ($prerequisiteName !== null) {
            $breakdown[] = [
                'label'   => $prerequisiteName,
                'current' => 1,
                'target'  => 1,
                'met'     => true,
            ];
        }

        $breakdown[] = [
            'label'   => 'Agentes Activos Calificados',
            'current' => $agentCount,
            'target'  => $minRequired ?? 0,
            'met'     => $minRequired !== null ? $agentCount >= $minRequired : false,
        ];
        $breakdown[] = [
            'label'   => 'PP Total del Equipo ($)',
            'current' => round($totalPp, 2),
            'target'  => 0,
            'met'     => $totalPp > 0,
        ];

        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Agentes Calificados',
                'current_value'  => $agentCount,
                'required_value' => 0.0,
            ],
            'progress_breakdown' => $breakdown,
            'details' => ['reason' => $reason],
        ];
    }
}
