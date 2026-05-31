<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Services\BonusCalculators\BonusCalculatorFactory;
use App\Services\BonusCalculators\BonusCalculatorInterface;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador Central de Cálculo de Bonos.
 *
 * Responsabilidades:
 *   1. Recibir un usuario (Agent o Promoter) + periodo.
 *   2. Obtener todos los esquemas de tipo 'bonus' activos.
 *   3. Resolver dependencias entre esquemas (dependency_scheme_id).
 *   4. Usar BonusCalculatorFactory para instanciar la Strategy correcta.
 *   5. Ejecutar el cálculo y retornar resultados consolidados.
 *
 * Principio de Responsabilidad Única (SRP):
 *   Este servicio NO calcula bonos — solo ORQUESTA. El cálculo real
 *   está delegado a las clases Strategy (BonusCalculatorInterface).
 *
 * Principio de Abierto/Cerrado (OCP):
 *   Agregar un nuevo tipo de bono no requiere modificar este orquestador,
 *   solo registrar la nueva Strategy en BonusCalculatorFactory.
 */
class BonusOrchestratorService
{
    /**
     * @param  BonusCalculatorFactory  $factory  Fábrica de calculadoras Strategy.
     */
    public function __construct(
        protected BonusCalculatorFactory $factory = new BonusCalculatorFactory(),
    ) {
    }

    /**
     * Calcula TODOS los bonos aplicables a un usuario en un periodo.
     *
     * Flujo:
     *   1. Carga esquemas activos de tipo 'bonus'.
     *   2. Ordena topológicamente por dependencias.
     *   3. Itera y evalúa cada esquema con su Strategy correspondiente.
     *
     * @param  Agent|Promoter  $user          Usuario a evaluar.
     * @param  Carbon          $periodStart   Inicio del periodo.
     * @param  Carbon          $periodEnd     Fin del periodo.
     *
     * @return array{
     *     user_id: int,
     *     user_type: string,
     *     period: array{start: string, end: string},
     *     results: array<int, array>,
     *     summary: array{total_achieved: int, total_amount: float}
     * }
     */
    public function calculateAll(
        Agent|Promoter $user,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        // ── 1. Cargar esquemas activos filtrados por target ──────────────
        $target = $user instanceof Agent ? 'agent' : 'promoter';
        $schemes = $this->fetchActiveBonusSchemes($target);

        if ($schemes->isEmpty()) {
            return $this->emptyResult($user, $periodStart, $periodEnd);
        }

        // ── 2. Ordenar por dependencias (topological sort simplificado) ──
        $orderedSchemes = $this->resolveDependencyOrder($schemes);

        // ── 3. Calcular cada esquema en orden, respetando dependencias ──
        $results      = [];
        $achievedMap  = [];   // scheme_id => bool (si fue alcanzado)

        foreach ($orderedSchemes as $scheme) {
            // ═══ Verificación de dependencia ═══════════════════════════════
            if (!empty($scheme->dependency_scheme_id)) {
                $depId = (int) $scheme->dependency_scheme_id;

                if (!isset($achievedMap[$depId])) {
                    // La dependencia no ha sido evaluada aún → no se puede calcular
                    $results[] = $this->skipResult($scheme, "Dependencia (ID: {$depId}) no evaluada.");
                    continue;
                }

                if ($achievedMap[$depId] === false) {
                    // La dependencia no fue alcanzada → este bono no aplica
                    $results[] = $this->skipResult(
                        $scheme,
                        "El bono prerequisite (ID: {$depId}) no fue alcanzado."
                    );
                    continue;
                }
            }

            // ═══ Obtener Strategy y calcular ═══════════════════════════════
            try {
                $calculator = $this->factory->make($scheme->name);
            } catch (\InvalidArgumentException $e) {
                Log::warning("BonusOrchestrator: {$e->getMessage()}");
                $results[] = $this->skipResult($scheme, 'Calculadora no encontrada.');
                continue;
            }

            // Asegurar que las relaciones necesarias estén cargadas
            $scheme->loadMissing(['versions.tiers']);

            $calcResult = $calculator->calculate($user, $scheme, $periodStart, $periodEnd);

            // Enriquecer el resultado con metadata del esquema
            $calcResult['scheme_id']   = $scheme->id;
            $calcResult['scheme_name'] = $scheme->name;
            $calcResult['target']      = $scheme->target;
            $calcResult['frequency']   = $scheme->frequency;
            $calcResult['metric_base'] = $scheme->metric_base;

            $results[]     = $calcResult;
            $achievedMap[$scheme->id] = $calcResult['is_achieved'];
        }

        // ── 4. Consolidar resumen ────────────────────────────────────────
        $totalAchieved = count(array_filter($results, fn($r) => $r['is_achieved'] ?? false));
        $totalAmount   = array_sum(array_column($results, 'amount'));

        return [
            'user_id'   => $user->id,
            'user_type' => $user instanceof Agent ? 'agent' : 'promoter',
            'period'    => [
                'start' => $periodStart->toDateString(),
                'end'   => $periodEnd->toDateString(),
            ],
            'results'   => $results,
            'summary'   => [
                'total_schemes_evaluated' => count($results),
                'total_achieved'          => $totalAchieved,
                'total_amount'            => round($totalAmount, 2),
            ],
        ];
    }

    /**
     * Calcula un solo bono específico para un usuario.
     *
     * Útil para endpoints que necesitan evaluar un bono puntual
     * (ej. previsualización en el frontend).
     *
     * @param  Agent|Promoter  $user
     * @param  string          $schemeName  Nombre del esquema (Scheme::$name).
     * @param  Carbon          $periodStart
     * @param  Carbon          $periodEnd
     * @return array
     */
    public function calculateOne(
        Agent|Promoter $user,
        string $schemeName,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $scheme = Scheme::where('name', $schemeName)
            ->where('type', 'bonus')
            ->where('is_active', true)
            ->with(['versions.tiers'])
            ->first();

        if ($scheme === null) {
            return [
                'is_achieved' => false,
                'amount'      => 0.0,
                'error'       => "Esquema «{$schemeName}» no encontrado o inactivo.",
            ];
        }

        // Verificar dependencia
        if (!empty($scheme->dependency_scheme_id)) {
            $depAchieved = $this->checkDependency(
                $user,
                (int) $scheme->dependency_scheme_id,
                $periodStart,
                $periodEnd
            );

            if (!$depAchieved) {
                return [
                    'is_achieved' => false,
                    'amount'      => 0.0,
                    'scheme_id'   => $scheme->id,
                    'scheme_name' => $scheme->name,
                    'details'     => [
                        'reason' => "El bono prerequisite (ID: {$scheme->dependency_scheme_id}) no fue alcanzado.",
                    ],
                ];
            }
        }

        try {
            $calculator = $this->factory->make($schemeName);
        } catch (\InvalidArgumentException $e) {
            return [
                'is_achieved' => false,
                'amount'      => 0.0,
                'error'       => $e->getMessage(),
            ];
        }

        $result = $calculator->calculate($user, $scheme, $periodStart, $periodEnd);

        $result['scheme_id']   = $scheme->id;
        $result['scheme_name'] = $scheme->name;
        $result['target']      = $scheme->target;
        $result['frequency']   = $scheme->frequency;
        $result['metric_base'] = $scheme->metric_base;

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Métodos privados ──────────────────────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Obtiene los esquemas de tipo 'bonus' activos filtrados por target.
     *
     * @param  string  $target  'agent' o 'promoter'.
     * @return Collection<int, Scheme>
     */
    private function fetchActiveBonusSchemes(string $target): Collection
    {
        return Scheme::where('type', 'bonus')
            ->where('is_active', true)
            ->where('target', $target)
            ->with(['versions.tiers'])
            ->get();
    }

    /**
     * Ordena los esquemas para que aquellos SIN dependencias se procesen primero.
     *
     * Algoritmo simplificado de topological sort:
     *   1. Esquemas sin dependency_scheme_id van primero.
     *   2. Esquemas cuya dependencia ya está en el grupo anterior van después.
     *   3. Esquemas con dependencias circulares o desconocidas van al final.
     *
     * @param  Collection<int, Scheme>  $schemes
     * @return array<int, Scheme>
     */
    private function resolveDependencyOrder(Collection $schemes): array
    {
        $schemeIds = $schemes->pluck('id')->toArray();

        $independent = [];  // Sin dependencias
        $dependent   = [];  // Con dependencias resolubles
        $orphans     = [];  // Dependencias no encontradas en este lote

        foreach ($schemes as $scheme) {
            if (empty($scheme->dependency_scheme_id)) {
                $independent[] = $scheme;
            } elseif (in_array((int) $scheme->dependency_scheme_id, $schemeIds, true)) {
                $dependent[] = $scheme;
            } else {
                $orphans[] = $scheme;
            }
        }

        // Ordenar dependientes para que sus dependencias estén antes
        usort($dependent, function (Scheme $a, Scheme $b) use ($independent) {
            $aDepInIndependent = $this->isDependencyInList($a, $independent);
            $bDepInIndependent = $this->isDependencyInList($b, $independent);

            if ($aDepInIndependent && !$bDepInIndependent) return -1;
            if (!$aDepInIndependent && $bDepInIndependent) return 1;
            return 0;
        });

        return array_merge($independent, $dependent, $orphans);
    }

    /**
     * Verifica si la dependencia de un esquema está en una lista dada.
     */
    private function isDependencyInList(Scheme $scheme, array $list): bool
    {
        if (empty($scheme->dependency_scheme_id)) {
            return true;
        }

        foreach ($list as $item) {
            if ($item instanceof Scheme && $item->id === (int) $scheme->dependency_scheme_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si un esquema prerequisite fue alcanzado por el usuario.
     *
     * Realiza una llamada recursiva controlada (máx. 1 nivel de profundidad
     * para evitar dependencias circulares).
     */
    private function checkDependency(
        Agent|Promoter $user,
        int $dependencySchemeId,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): bool {
        $depScheme = Scheme::where('id', $dependencySchemeId)
            ->where('is_active', true)
            ->with(['versions.tiers'])
            ->first();

        if ($depScheme === null) {
            Log::warning("BonusOrchestrator: Dependencia (ID: {$dependencySchemeId}) no encontrada o inactiva.");
            return false;
        }

        try {
            $calculator = $this->factory->make($depScheme->name);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $result = $calculator->calculate($user, $depScheme, $periodStart, $periodEnd);

        return $result['is_achieved'] ?? false;
    }

    /**
     * Genera un resultado estándar para un bono saltado por dependencia.
     */
    private function skipResult(Scheme $scheme, string $reason): array
    {
        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'scheme_id'   => $scheme->id,
            'scheme_name' => $scheme->name,
            'target'      => $scheme->target,
            'frequency'   => $scheme->frequency,
            'metric_base' => $scheme->metric_base,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => '—',
                'current_value'  => 0.0,
                'required_value' => 0.0,
            ],
            'progress_breakdown' => [],
            'details' => ['skipped' => true, 'reason' => $reason],
        ];
    }

    /**
     * Transforma el resultado crudo del orquestador al formato que espera
     * el frontend Vue (Show.vue) para la «Ruta de Bonos» / «Panel de Bonos».
     *
     * Cada entrada retornada es directamente consumible por el componente:
     *   - name, description, step
     *   - target, progress, unlocked
     *   - depends_on_previous
     *   - conditions: array<{label, current, target}>
     *
     * @param  array  $orchestratorResult  El retorno de $this->calculateAll().
     * @return array<int, array>
     */
    public function toFrontendFormat(array $orchestratorResult): array
    {
        $results = $orchestratorResult['results'] ?? [];

        return array_values(array_map(function (array $result, int $index) use ($results) {
            // Determinar si depende del bono anterior en secuencia
            $dependsOnPrevious = $index > 0
                && !($results[$index - 1]['is_achieved'] ?? false);

            // Construir conditions a partir del progress_breakdown
            $conditions = [];
            $breakdown = $result['progress_breakdown'] ?? [];

            if (!empty($breakdown)) {
                foreach ($breakdown as $item) {
                    $conditions[] = [
                        'label'   => $item['label']   ?? '—',
                        'current' => $item['current'] ?? 0,
                        'target'  => $item['target']  ?? 0,
                        'met'     => $item['met']     ?? false,
                    ];
                }
            } else {
                // Fallback: usar el progress simple
                $prog = $result['progress'] ?? [];
                if (!empty($prog['metric_label'])) {
                    $cv = $prog['current_value']  ?? 0;
                    $rv = $prog['required_value'] ?? 0;
                    $conditions[] = [
                        'label'   => $prog['metric_label'],
                        'current' => $cv,
                        'target'  => $rv,
                        'met'     => $rv > 0 ? $cv >= $rv : ($cv > 0),
                    ];
                }
            }

            $isAchieved = $result['is_achieved'] ?? false;

            // target para la barra de progreso general: el primer target del breakdown
            $primaryTarget = $conditions[0]['target'] ?? 0;
            $primaryProgress = $conditions[0]['current'] ?? 0;

            return [
                'name'                => $result['scheme_name']   ?? '—',
                'description'         => $result['scheme_name']   ?? '—',
                'step'                => $index + 1,
                'target'              => $primaryTarget,
                'progress'            => $primaryProgress,
                'unlocked'            => $isAchieved,
                'depends_on_previous'  => $dependsOnPrevious,
                'conditions'          => $conditions,
                // Metadatos extra para debug / extensibilidad
                'amount'              => $result['amount']        ?? 0.0,
                'scheme_id'           => $result['scheme_id']     ?? null,
                'frequency'           => $result['frequency']     ?? null,
                'metric_base'         => $result['metric_base']   ?? null,
                'tier_index'          => $result['tier_index']    ?? null,
            ];
        }, $results, array_keys($results)));
    }

    /**
     * Genera un resultado vacío cuando no hay esquemas que evaluar.
     */
    private function emptyResult(
        Agent|Promoter $user,
        Carbon $periodStart,
        Carbon $periodEnd
    ): array {
        return [
            'user_id'   => $user->id,
            'user_type' => $user instanceof Agent ? 'agent' : 'promoter',
            'period'    => [
                'start' => $periodStart->toDateString(),
                'end'   => $periodEnd->toDateString(),
            ],
            'results'   => [],
            'summary'   => [
                'total_schemes_evaluated' => 0,
                'total_achieved'          => 0,
                'total_amount'            => 0.0,
            ],
        ];
    }
}
