<?php

namespace App\Services\BonusCalculators;

use App\Models\Agent;
use App\Models\Policy;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Models\SchemeTier;
use App\Models\SchemeVersion;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Calcula el Bono Adicional por Agentes con Compensación Trimestral.
 *
 * REGLAS DE NEGOCIO:
 *   1. El Promoter DEBE haber ganado el bono prerequisite (dependency_scheme_id).
 *   2. Solo cuentan como agentes con compensación aquellos que ganaron el
 *      Bono Activity Ratio en el mismo periodo evaluado.
 *   3. Cada agente cuenta UNA sola vez por trimestre: para el siguiente
 *      trimestre debe volver a ganar Activity Ratio para sumar de nuevo.
 *   4. El porcentaje se aplica sobre la PP total acumulada de la promotoría.
 *   5. Los tramos son acumulativos: un conteo mayor al del tramo más alto
 *      conserva ese tramo (ej. 6 agentes → tramo «5+»).
 *
 * Condiciones leídas de $tier->conditions:
 *   - min_agents  (int)       Mínimo de agentes con compensación.
 *   - max_agents  (int|null)  Máximo de agentes (null = sin límite).
 */
class AdditionalAgentsCalculator implements BonusCalculatorInterface
{
    /** Llaves del esquema que determina si un agente tiene compensación. */
    private const ACTIVITY_RATIO_KEYS = ['activity_ratio', 'Activity Ratio'];

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
        $activityRatioScheme = $this->resolveActivityRatioScheme();

        if ($activityRatioScheme === null) {
            return $this->notAchieved(
                reason: 'No hay un esquema Activity Ratio activo; no es posible determinar qué agentes tienen compensación.',
                prerequisiteName: $prerequisiteName,
            );
        }

        $qualifiedAgents  = $this->collectQualifiedAgents($user, $activityRatioScheme, $periodStart, $periodEnd);
        $qualifiedCount   = count($qualifiedAgents);
        $activeAgentCount = $this->countActiveAgents($user, $periodEnd);
        $totalPP          = $this->calculateTotalPP($user, $periodStart, $periodEnd);

        // ── 4. Resolver tramo por número de agentes con compensación ────
        $tier = $this->matchTier($tiers, $qualifiedCount);

        if ($tier === null) {
            return $this->notAchieved(
                reason: "Agentes con compensación (Activity Ratio ganado): {$qualifiedCount} — sin tramo coincidente.",
                agentCount: $qualifiedCount,
                totalPp: $totalPP,
                minRequired: $this->minimumAgentRequirement($tiers),
                prerequisiteName: $prerequisiteName,
                activeAgentCount: $activeAgentCount,
            );
        }

        $minAgents = (int) ($tier->conditions['min_agents'] ?? 0);
        $tierIndex = $tiers->search(fn (SchemeTier $t) => $t->id === $tier->id);

        return [
            'is_achieved' => true,
            'amount'      => $this->calculateAmount($tier, $user, $totalPP),
            'tier_index'  => $tierIndex === false ? null : $tierIndex,
            'tier_data'   => $tier->toArray(),
            'progress'    => [
                'metric_label'   => 'Agentes con compensación',
                'current_value'  => $qualifiedCount,
                'required_value' => $minAgents,
            ],
            'progress_breakdown' => [
                $this->dependencyBar($prerequisiteName, $dependencyMet),
                [
                    'label'   => 'Agentes con Activity Ratio ganado',
                    'current' => $qualifiedCount,
                    'target'  => $minAgents,
                    'met'     => $qualifiedCount >= $minAgents,
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
                'qualified_agents'  => $qualifiedCount,
                'active_agents'     => $activeAgentCount,
                'agents'            => $qualifiedAgents,
                'total_pp'          => round($totalPP, 2),
                'prerequisite_met'  => $prerequisiteName !== null,
                'prerequisite_name' => $prerequisiteName,
                'metrics'           => ['min_agents' => $qualifiedCount],
            ],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Obtiene el esquema Activity Ratio activo que define la compensación
     * de cada agente.
     */
    private function resolveActivityRatioScheme(): ?Scheme
    {
        return Scheme::where('type', 'bonus')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereIn('template_key', self::ACTIVITY_RATIO_KEYS)
                    ->orWhereIn('name', self::ACTIVITY_RATIO_KEYS);
            })
            ->with(['versions.tiers'])
            ->first();
    }

    /**
     * Evalúa el Bono Activity Ratio de cada agente activo del promotor en el
     * periodo y retorna únicamente a quienes lo ganaron.
     *
     * @return array<int, array{agent_id: int, agent_name: string}>
     */
    private function collectQualifiedAgents(
        Promoter $promoter,
        Scheme $activityRatioScheme,
        Carbon $start,
        Carbon $end
    ): array {
        $activityRatioScheme->loadMissing(['versions.tiers']);
        $calculator = new ActivityRatioCalculator();
        $qualified  = [];

        $agents = $promoter->agents()->activeInPeriod($end->toDateString())->get();

        foreach ($agents as $agent) {
            try {
                $result = $calculator->calculate($agent, $activityRatioScheme, $start, $end);
            } catch (\Throwable $exception) {
                Log::warning("AdditionalAgents: no se pudo evaluar Activity Ratio del agente {$agent->id}: {$exception->getMessage()}");
                continue;
            }

            if ($result['is_achieved'] ?? false) {
                $qualified[] = [
                    'agent_id'   => $agent->id,
                    'agent_name' => $agent->name,
                ];
            }
        }

        return $qualified;
    }

    /**
     * Selecciona el tramo correspondiente al número de agentes calificados.
     *
     * Los tramos son acumulativos: si el conteo supera el rango del tramo con
     * el mínimo más alto, se otorga ese tramo (ej. 6 agentes → «5+»).
     */
    private function matchTier(Collection $tiers, int $agentCount): ?SchemeTier
    {
        $ordered = $tiers
            ->sortBy(fn (SchemeTier $t) => (int) ($t->conditions['min_agents'] ?? 0))
            ->values();

        foreach ($ordered as $tier) {
            $conditions = $tier->conditions ?? [];
            $minAgents  = (int) ($conditions['min_agents'] ?? 0);
            $maxAgents  = isset($conditions['max_agents']) && $conditions['max_agents'] !== '' && $conditions['max_agents'] !== null
                ? (int) $conditions['max_agents']
                : PHP_INT_MAX;

            if ($agentCount >= $minAgents && $agentCount <= $maxAgents) {
                return $tier;
            }
        }

        $topTier = $ordered->last();
        if ($topTier !== null && $agentCount >= (int) ($topTier->conditions['min_agents'] ?? 0)) {
            return $topTier;
        }

        return null;
    }

    /**
     * Requisito mínimo de agentes entre todos los tramos (para mostrar la meta).
     */
    private function minimumAgentRequirement(Collection $tiers): int
    {
        return (int) $tiers->min(fn (SchemeTier $t) => (int) ($t->conditions['min_agents'] ?? PHP_INT_MAX));
    }

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
            ->where('status', Policy::STATUS_PAGADA)
            ->sum('premium_amount');
    }

    /**
     * Barra del bono prerequisite.
     *
     * @return array{label: string, current: int, target: int, met: bool, _isDependency: bool}
     */
    private function dependencyBar(?string $prerequisiteName, bool $met): array
    {
        return [
            'label'         => $prerequisiteName ?? 'Bono Producción 1er Año de Vida Trimestral',
            'current'       => $met ? 1 : 0,
            'target'        => 1,
            'met'           => $met,
            '_isDependency' => true,
        ];
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
        int $activeAgentCount = 0,
    ): array {
        $breakdown = [];

        // Barra de dependencia: el orquestador la reemplaza con el estado real
        // del bono prerequisite cuando no fue alcanzado.
        if ($prerequisiteName !== null) {
            $breakdown[] = $this->dependencyBar($prerequisiteName, true);
        }

        $breakdown[] = [
            'label'   => 'Agentes con Activity Ratio ganado',
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
                'metric_label'   => 'Agentes con compensación',
                'current_value'  => $agentCount,
                'required_value' => $minRequired ?? 0,
            ],
            'progress_breakdown' => $breakdown,
            'details' => [
                'reason'        => $reason,
                'active_agents' => $activeAgentCount,
                'metrics'       => ['min_agents' => $agentCount],
            ],
        ];
    }
}
