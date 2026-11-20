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
     *   3. Para cada esquema, resuelve el periodo efectivo según su frecuencia
     *      (mensual → mes, trimestral → trimestre, anual → año).
     *   4. Itera y evalúa cada esquema con su Strategy correspondiente.
     *
     * @param  Agent|Promoter  $user             Usuario a evaluar.
     * @param  Carbon          $periodStart      Inicio del periodo (referencia para consulta).
     * @param  Carbon          $periodEnd        Fin del periodo (referencia para consulta).
     * @param  Carbon|null     $visualRangeStart Inicio del rango visual (UI). Si es null, se usa $periodStart.
     * @param  Carbon|null     $visualRangeEnd   Fin del rango visual (UI). Si es null, se usa $periodEnd.
     * @param  bool            $monthlyView      Si es true, los bonos se evalúan mes a mes dentro
     *                                           del rango visual: los mensuales se suman por mes
     *                                           (con desglose), y los trimestrales/anuales se
     *                                           limitan al mes cuando el rango es de un solo mes.
     *
     * @return array{...}
     */
    public function calculateAll(
        Agent|Promoter $user,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?Carbon $visualRangeStart = null,
        ?Carbon $visualRangeEnd = null,
        bool $monthlyView = false,
    ): array {
        // ── Rango visual (lo que el usuario ve en la UI) ──────────────
        $visualStart = $visualRangeStart ?? $periodStart;
        $visualEnd   = $visualRangeEnd   ?? $periodEnd;

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
            $dependencyAchieved = true; // Sin dependencia → siempre true
            $dependencyName     = null;

            if (!empty($scheme->dependency_scheme_id)) {
                $depTemplateKey = $scheme->dependency_scheme_id;
                $depScheme = $schemes->firstWhere('template_key', $depTemplateKey);
                $dependencyName = $depScheme?->name;

                if ($depScheme === null || !isset($achievedMap[$depScheme->id])) {
                    // La dependencia no ha sido evaluada aún (no debería ocurrir
                    // con el ordenamiento topológico, pero nos curamos en salud).
                    $results[] = $this->skipResult($scheme, "Dependencia ({$depTemplateKey}) no evaluada.", $this->resolveEffectivePeriod($scheme, $visualEnd), $visualStart, $visualEnd, $dependencyName);
                    continue;
                }

                $dependencyAchieved = $achievedMap[$depScheme->id];
            }

            // ═══ Obtener Strategy y calcular ═══════════════════════════════
            try {
                $calculator = $this->factory->make($scheme->template_key);
            } catch (\InvalidArgumentException $e) {
                Log::warning("BonusOrchestrator: {$e->getMessage()}");
                $results[] = $this->skipResult($scheme, 'Calculadora no encontrada.', $this->resolveEffectivePeriod($scheme, $visualEnd), $visualStart, $visualEnd);
                continue;
            }

            // Asegurar que las relaciones necesarias estén cargadas
            $scheme->loadMissing(['versions.tiers']);

            // ═══ Evaluar con el periodo correspondiente (mes / trimestre / año) ═══
            $evaluation      = $this->evaluateScheme($user, $scheme, $calculator, $visualStart, $visualEnd, $monthlyView);
            $effectivePeriod = $evaluation['period'];

            // ═══ Si la dependencia no fue alcanzada, el bono falla
            //      automáticamente, pero AUN ASÍ mostramos el progreso
            //      de sus métricas para que el usuario vea qué le falta. ═══
            if (! $dependencyAchieved) {
                $evaluation = $this->applyDependencyBlock($evaluation, $dependencyName);
            }

            $calcResult = $evaluation['result'];

            // Enriquecer el resultado con metadata del esquema
            $calcResult['scheme_id']          = $scheme->id;
            $calcResult['scheme_name']        = $scheme->name;
            $calcResult['template_key']       = $scheme->template_key;
            $calcResult['target']             = $scheme->target;
            $calcResult['frequency']          = $scheme->frequency;
            $calcResult['metric_base']        = $scheme->metric_base;
            $calcResult['requires_anticipos'] = (bool) ($scheme->requires_anticipos ?? false);

            // ── Metadatos de dependencia ──────────────────────────────────
            $calcResult['dependency_scheme_id'] = $scheme->dependency_scheme_id;
            if (!empty($scheme->dependency_scheme_id)) {
                $depScheme = $schemes->firstWhere('template_key', $scheme->dependency_scheme_id);
                $calcResult['dependency_scheme_name'] = $depScheme?->name;
            } else {
                $calcResult['dependency_scheme_name'] = null;
            }

            // ── Metadatos de periodo (para que el frontend muestre alertas) ──
            $calcResult['effective_period_start'] = $effectivePeriod['start']->toDateString();
            $calcResult['effective_period_end']   = $effectivePeriod['end']->toDateString();
            $calcResult['visual_period_start']    = $visualStart->toDateString();
            $calcResult['visual_period_end']      = $visualEnd->toDateString();

            // ── Desglose mes a mes (solo bonos mensuales en rangos multi-mes) ──
            $calcResult['monthly_breakdown'] = $this->buildMonthlyBreakdown($evaluation['months']);
            $calcResult['monthly_total']     = count($evaluation['months']) > 1;

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
            'visual_period' => [
                'start' => $visualStart->toDateString(),
                'end'   => $visualEnd->toDateString(),
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
     * @param  string          $templateKey  Llave técnica inmutable del bono (Scheme::$template_key).
     * @param  Carbon          $periodStart
     * @param  Carbon          $periodEnd
     * @return array
     */
    public function calculateOne(
        Agent|Promoter $user,
        string $templateKey,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $scheme = Scheme::where('template_key', $templateKey)
            ->where('type', 'bonus')
            ->where('is_active', true)
            ->with(['versions.tiers'])
            ->first();

        if ($scheme === null) {
            return [
                'is_achieved' => false,
                'amount'      => 0.0,
                'error'       => "Esquema con template_key «{$templateKey}» no encontrado o inactivo.",
            ];
        }

        // Verificar dependencia
        if (!empty($scheme->dependency_scheme_id)) {
            $depAchieved = $this->checkDependency(
                $user,
                $scheme->dependency_scheme_id,
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
                        'reason' => "El bono prerequisite ({$scheme->dependency_scheme_id}) no fue alcanzado.",
                    ],
                ];
            }
        }

        try {
            $calculator = $this->factory->make($scheme->template_key);
        } catch (\InvalidArgumentException $e) {
            return [
                'is_achieved' => false,
                'amount'      => 0.0,
                'error'       => $e->getMessage(),
            ];
        }

        // ═══ Resolver periodo efectivo según frecuencia ══════════════════
        $effectivePeriod = $this->resolveEffectivePeriod($scheme, $periodEnd);

        $result = $calculator->calculate(
            $user,
            $scheme,
            $effectivePeriod['start'],
            $effectivePeriod['end']
        );

        $result['scheme_id']          = $scheme->id;
        $result['scheme_name']        = $scheme->name;
        $result['template_key']       = $scheme->template_key;
        $result['target']             = $scheme->target;
        $result['frequency']          = $scheme->frequency;
        $result['metric_base']        = $scheme->metric_base;
        $result['requires_anticipos'] = (bool) ($scheme->requires_anticipos ?? false);

        // ── Metadatos de dependencia ──────────────────────────────────
        $result['dependency_scheme_id'] = $scheme->dependency_scheme_id;
        if (!empty($scheme->dependency_scheme_id)) {
            $depScheme = Scheme::where('template_key', $scheme->dependency_scheme_id)
                ->where('type', 'bonus')
                ->first();
            $result['dependency_scheme_name'] = $depScheme?->name;
        } else {
            $result['dependency_scheme_name'] = null;
        }

        // ── Metadatos de periodo ──────────────────────────────────────
        $result['effective_period_start'] = $effectivePeriod['start']->toDateString();
        $result['effective_period_end']   = $effectivePeriod['end']->toDateString();
        $result['visual_period_start']    = $periodStart->toDateString();
        $result['visual_period_end']      = $periodEnd->toDateString();

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
        $schemeTemplateKeys = $schemes->pluck('template_key')->filter()->toArray();

        $independent = [];  // Sin dependencias
        $dependent   = [];  // Con dependencias resolubles
        $orphans     = [];  // Dependencias no encontradas en este lote

        foreach ($schemes as $scheme) {
            if (empty($scheme->dependency_scheme_id)) {
                $independent[] = $scheme;
            } elseif (in_array($scheme->dependency_scheme_id, $schemeTemplateKeys, true)) {
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
            if ($item instanceof Scheme && $item->template_key === $scheme->dependency_scheme_id) {
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
        string $dependencySchemeKey,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): bool {
        $depScheme = Scheme::where('template_key', $dependencySchemeKey)
            ->where('is_active', true)
            ->with(['versions.tiers'])
            ->first();

        if ($depScheme === null) {
            Log::warning("BonusOrchestrator: Dependencia ({$dependencySchemeKey}) no encontrada o inactiva.");
            return false;
        }

        try {
            $calculator = $this->factory->make($depScheme->template_key);
        } catch (\InvalidArgumentException) {
            return false;
        }

        // Usar el periodo efectivo de la dependencia (según su frecuencia)
        $depEffectivePeriod = $this->resolveEffectivePeriod($depScheme, $periodEnd);

        $result = $calculator->calculate(
            $user,
            $depScheme,
            $depEffectivePeriod['start'],
            $depEffectivePeriod['end']
        );

        return $result['is_achieved'] ?? false;
    }

    /**
     * Genera un resultado estándar para un bono saltado por dependencia.
     *
     * @param  Scheme  $scheme
     * @param  string  $reason
     * @param  array{start: Carbon, end: Carbon}  $effectivePeriod
     * @param  Carbon  $visualStart
     * @param  Carbon  $visualEnd
     * @return array
     */
    private function skipResult(
        Scheme $scheme,
        string $reason,
        array $effectivePeriod = [],
        ?Carbon $visualStart = null,
        ?Carbon $visualEnd = null,
        ?string $dependencyName = null,
    ): array {
        $effStart = $effectivePeriod['start'] ?? null;
        $effEnd   = $effectivePeriod['end'] ?? null;

        return [
            'is_achieved' => false,
            'amount'      => 0.0,
            'scheme_id'   => $scheme->id,
            'scheme_name'        => $scheme->name,
            'target'             => $scheme->target,
            'frequency'          => $scheme->frequency,
            'metric_base'        => $scheme->metric_base,
            'requires_anticipos'       => (bool) ($scheme->requires_anticipos ?? false),
            'dependency_scheme_id'   => $scheme->dependency_scheme_id,
            'dependency_scheme_name' => $dependencyName,
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => '—',
                'current_value'  => 0.0,
                'required_value' => 0.0,
            ],
            'progress_breakdown' => [],
            'details' => ['skipped' => true, 'reason' => $reason],
            // ── Metadatos de periodo ──────────────────────────────────
            'effective_period_start' => $effStart?->toDateString(),
            'effective_period_end'   => $effEnd?->toDateString(),
            'visual_period_start'    => $visualStart?->toDateString(),
            'visual_period_end'      => $visualEnd?->toDateString(),
            'monthly_breakdown'      => [],
            'monthly_total'          => false,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // ── Helpers de Evaluación (desglose mensual) ──────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Evalúa un esquema resolviendo su periodo según la frecuencia y el modo
     * de desglose mensual.
     *
     * Reglas cuando $monthlyView es true:
     *   - Bonos mensuales → se evalúan mes a mes dentro del rango visual y se suman.
     *   - Bonos trimestrales/anuales → si el rango visual abarca un solo mes,
     *     se evalúan SOLO con ese mes; si no, se evalúan sobre el periodo
     *     completo de su frecuencia (trimestre / año).
     *
     * @return array{result: array, period: array{start: Carbon, end: Carbon}, months: array<int, array>}
     */
    private function evaluateScheme(
        Agent|Promoter $user,
        Scheme $scheme,
        BonusCalculatorInterface $calculator,
        Carbon $visualStart,
        Carbon $visualEnd,
        bool $monthlyView,
    ): array {
        if ($monthlyView && $this->isMonthlyScheme($scheme)) {
            return $this->evaluateMonthlyScheme($user, $scheme, $calculator, $visualStart, $visualEnd);
        }

        $period = ($monthlyView && $this->isSingleMonthRange($visualStart, $visualEnd))
            ? $this->resolveMonthPeriod($visualEnd)
            : $this->resolveEffectivePeriod($scheme, $visualEnd);

        return [
            'result' => $calculator->calculate($user, $scheme, $period['start'], $period['end']),
            'period' => $period,
            'months' => [],
        ];
    }

    /**
     * Evalúa un bono mensual mes a mes y devuelve la suma como resultado principal.
     *
     * Cuando el rango visual abarca un solo mes se conserva el resultado tal cual
     * (sin desglose). Cuando abarca varios meses se retorna el agregado y el
     * detalle de cada mes para que el frontend lo despliegue.
     *
     * @return array{result: array, period: array{start: Carbon, end: Carbon}, months: array<int, array>}
     */
    private function evaluateMonthlyScheme(
        Agent|Promoter $user,
        Scheme $scheme,
        BonusCalculatorInterface $calculator,
        Carbon $visualStart,
        Carbon $visualEnd,
    ): array {
        $months = [];

        foreach ($this->splitIntoMonths($visualStart, $visualEnd) as $bucket) {
            $months[] = [
                'month'  => $bucket['start']->format('Y-m'),
                'label'  => $this->monthLabel($bucket['start']),
                'start'  => $bucket['start'],
                'end'    => $bucket['end'],
                'result' => $calculator->calculate($user, $scheme, $bucket['start'], $bucket['end']),
            ];
        }

        $lastMonth = end($months);

        return [
            'result' => count($months) === 1
                ? $months[0]['result']
                : $this->aggregateMonthlyResults($months),
            'period' => [
                'start' => $months[0]['start'],
                'end'   => $lastMonth['end'],
            ],
            'months' => count($months) > 1 ? $months : [],
        ];
    }

    /**
     * Suma los resultados mensuales en un único resultado consolidado.
     *
     * El monto es la suma de los meses y el bono se considera desbloqueado si
     * al menos un mes lo estuvo. El breakdown muestra una línea por mes con el
     * monto generado, de modo que al colapsar la tarjeta se vea «cuánto obtuvo
     * de cada mes».
     *
     * @param  array<int, array>  $months
     */
    private function aggregateMonthlyResults(array $months): array
    {
        $totalAmount   = 0.0;
        $achievedCount = 0;
        $breakdown     = [];

        foreach ($months as $entry) {
            $amount = (float) ($entry['result']['amount'] ?? 0);
            $totalAmount += $amount;

            if ($entry['result']['is_achieved'] ?? false) {
                $achievedCount++;
            }

            $breakdown[] = [
                'label'            => $entry['label'],
                'current'          => round($amount, 2),
                'target'           => 0,
                'met'              => (bool) ($entry['result']['is_achieved'] ?? false),
                '_isMonthlyAmount' => true,
            ];
        }

        return [
            'is_achieved' => $achievedCount > 0,
            'amount'      => round($totalAmount, 2),
            'tier_index'  => null,
            'tier_data'   => null,
            'progress'    => [
                'metric_label'   => 'Monto acumulado por mes',
                'current_value'  => round($totalAmount, 2),
                'required_value' => 0.0,
            ],
            'progress_breakdown' => $breakdown,
            'details'            => [
                'monthly'         => array_map(fn (array $entry) => [
                    'month'       => $entry['month'],
                    'amount'      => (float) ($entry['result']['amount'] ?? 0),
                    'is_achieved' => (bool) ($entry['result']['is_achieved'] ?? false),
                ], $months),
                'achieved_months' => $achievedCount,
                'total_months'    => count($months),
            ],
        ];
    }

    /**
     * Anula un bono cuyo prerequisite no fue alcanzado, conservando el progreso
     * de sus métricas para que el usuario vea qué le falta.
     *
     * @param  array{result: array, period: array, months: array}  $evaluation
     * @return array{result: array, period: array, months: array}
     */
    private function applyDependencyBlock(array $evaluation, ?string $dependencyName): array
    {
        $depLabel = $dependencyName ?? 'Bono Prerequisite';

        // Si es un bono mensual agregado, se reconstruye el breakdown con montos
        // en cero para no mostrar cantidades que no se pagarán.
        $breakdown = [];
        if (!empty($evaluation['months'])) {
            foreach ($evaluation['months'] as $entry) {
                $breakdown[] = [
                    'label'            => $entry['label'],
                    'current'          => 0.0,
                    'target'           => 0,
                    'met'              => false,
                    '_isMonthlyAmount' => true,
                ];
            }
        } else {
            $breakdown = $evaluation['result']['progress_breakdown'] ?? [];
        }

        $depCondition = [
            'label'         => $depLabel,
            'current'       => 0,
            'target'        => 1,
            'met'           => false,
            '_isDependency' => true,
        ];

        $firstItem = $breakdown[0] ?? null;
        if ($firstItem && ($firstItem['label'] ?? '') === $depLabel) {
            $breakdown[0] = $depCondition;
        } else {
            array_unshift($breakdown, $depCondition);
        }

        $result = $evaluation['result'];
        $result['is_achieved']       = false;
        $result['amount']            = 0.0;
        $result['tier_index']        = null;
        $result['tier_data']         = null;
        $result['progress_breakdown'] = $breakdown;
        $evaluation['result']        = $result;

        foreach ($evaluation['months'] as $index => $entry) {
            $entry['result']['is_achieved'] = false;
            $entry['result']['amount']      = 0.0;
            $evaluation['months'][$index]   = $entry;
        }

        return $evaluation;
    }

    /**
     * Construye el desglose mensual consumible por el frontend.
     *
     * @param  array<int, array>  $months  Entradas de evaluateMonthlyScheme().
     * @return array<int, array>
     */
    private function buildMonthlyBreakdown(array $months): array
    {
        return array_map(function (array $entry) {
            $result = $entry['result'];

            $conditions = [];
            foreach ($result['progress_breakdown'] ?? [] as $item) {
                $conditions[] = [
                    'label'   => $item['label']   ?? '—',
                    'current' => $item['current'] ?? 0,
                    'target'  => $item['target']  ?? 0,
                    'met'     => $item['met']     ?? false,
                ];
            }

            return [
                'month'        => $entry['month'],
                'label'        => $entry['label'],
                'period_label' => $this->formatPeriodLabel(
                    $entry['start']->toDateString(),
                    $entry['end']->toDateString(),
                ),
                'unlocked'     => (bool) ($result['is_achieved'] ?? false),
                'amount'       => (float) ($result['amount'] ?? 0),
                'conditions'   => $conditions,
            ];
        }, $months);
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
     *   - periodo_evaluado: string — rango real usado por el motor
     *   - mostrar_alerta_periodo: bool — true si el periodo real es más amplio que el visual
     *   - monthly_breakdown: array — montos por mes de los bonos mensuales
     *   - monthly_total: bool — true si el monto es la suma de varios meses
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
                        '_isDependency' => $item['_isDependency'] ?? false,
                        '_isMonthlyAmount' => $item['_isMonthlyAmount'] ?? false,
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

            // ── Metadatos de periodo de evaluación ────────────────────
            $periodoEvaluado = $this->formatPeriodLabel(
                $result['effective_period_start'] ?? null,
                $result['effective_period_end'] ?? null,
            );
            $mostrarAlertaPeriodo = $this->isPeriodWiderThanVisual($result);

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
                'template_key'        => $result['template_key']  ?? null,
                'frequency'           => $result['frequency']          ?? null,
                'metric_base'         => $result['metric_base']        ?? null,
                'requires_anticipos'     => $result['requires_anticipos']     ?? false,
                'dependency_scheme_id'   => $result['dependency_scheme_id']   ?? null,
                'dependency_scheme_name' => $result['dependency_scheme_name'] ?? null,
                'tier_index'             => $result['tier_index']             ?? null,
                // ── Periodo de evaluación (nuevo) ────────────────────
                'periodo_evaluado'       => $periodoEvaluado,
                'mostrar_alerta_periodo'  => $mostrarAlertaPeriodo,
                // ── Desglose mes a mes (bonos mensuales en rangos multi-mes) ──
                'monthly_breakdown'      => $result['monthly_breakdown'] ?? [],
                'monthly_total'          => (bool) ($result['monthly_total'] ?? false),
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
            'visual_period' => [
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

    // ═══════════════════════════════════════════════════════════════════════
    // ── Helpers de Resolución de Periodo ──────────────────────────────────
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Indica si el esquema se liquida mes a mes.
     *
     * Aplica a los bonos de frecuencia «mensual» y al Bono de Desarrollo
     * Mensual (que comercialmente se evalúa por trimestre).
     */
    private function isMonthlyScheme(Scheme $scheme): bool
    {
        if (strtolower(trim((string) $scheme->frequency)) === 'mensual') {
            return true;
        }

        return $scheme->template_key === 'monthly_development';
    }

    /**
     * Indica si el rango visual abarca un único mes calendario.
     */
    private function isSingleMonthRange(Carbon $start, Carbon $end): bool
    {
        return $start->format('Y-m') === $end->format('Y-m');
    }

    /**
     * Divide un rango de fechas en meses calendario completos.
     *
     * @return array<int, array{start: Carbon, end: Carbon}>
     */
    private function splitIntoMonths(Carbon $start, Carbon $end): array
    {
        $buckets = [];
        $cursor  = $start->copy()->startOfMonth();
        $last    = $end->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $buckets[] = [
                'start' => $cursor->copy()->startOfMonth(),
                'end'   => $cursor->copy()->endOfMonth(),
            ];
            $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $buckets;
    }

    /**
     * Resuelve el periodo correspondiente al mes calendario de una fecha.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function resolveMonthPeriod(Carbon $referenceDate): array
    {
        return [
            'start' => $referenceDate->copy()->startOfMonth(),
            'end'   => $referenceDate->copy()->endOfMonth(),
        ];
    }

    /**
     * Etiqueta legible de un mes, ej. «Enero 2026».
     */
    private function monthLabel(Carbon $date): string
    {
        return ucfirst($date->copy()->locale('es')->translatedFormat('F Y'));
    }

    /**
     * Resuelve el periodo efectivo de evaluación para un esquema de bono
     * según su frecuencia configurada.
     *
     * Reglas:
     *   - mensual / única → el mes calendario que contiene la fecha de referencia.
     *   - trimestral       → el trimestre calendario completo.
     *   - anual            → el año calendario completo.
     *
     * Esto garantiza que los bonos trimestrales/anuales siempre evalúen
     * el periodo acumulado completo, independientemente del rango visual
     * que el usuario tenga seleccionado en la UI.
     *
     * Nota: los bonos mensuales se resuelven mes a mes en evaluateScheme()
     * cuando el orquestador corre en modo $monthlyView.
     *
     * @param  Scheme  $scheme         Esquema de bono (contiene $scheme->frequency).
     * @param  Carbon  $referenceDate  Fecha de referencia (normalmente el fin del rango visual).
     * @return array{start: Carbon, end: Carbon}
     */
    private function resolveEffectivePeriod(Scheme $scheme, Carbon $referenceDate): array
    {
        // El Bono de Desarrollo Mensual se evalúa comercialmente por trimestre completo.
        if ($scheme->template_key === 'monthly_development') {
            return [
                'start' => $referenceDate->copy()->startOfQuarter(),
                'end'   => $referenceDate->copy()->endOfQuarter(),
            ];
        }

        $frequency = strtolower($scheme->frequency ?? 'mensual');

        return match ($frequency) {
            'trimestral' => [
                'start' => $referenceDate->copy()->startOfQuarter(),
                'end'   => $referenceDate->copy()->endOfQuarter(),
            ],
            'anual' => [
                'start' => $referenceDate->copy()->startOfYear(),
                'end'   => $referenceDate->copy()->endOfYear(),
            ],
            default => [ // 'mensual', 'única', o cualquier otro valor
                'start' => $referenceDate->copy()->startOfMonth(),
                'end'   => $referenceDate->copy()->endOfMonth(),
            ],
        };
    }

    /**
     * Formatea un rango de fechas como etiqueta legible para el frontend.
     *
     * @param  string|null  $start  Fecha de inicio (Y-m-d).
     * @param  string|null  $end    Fecha de fin (Y-m-d).
     * @return string  Ej: "01/01/2026 al 31/03/2026"
     */
    private function formatPeriodLabel(?string $start, ?string $end): string
    {
        if ($start === null || $end === null) {
            return '—';
        }

        $s = Carbon::parse($start);
        $e = Carbon::parse($end);

        return $s->format('d/m/Y') . ' al ' . $e->format('d/m/Y');
    }

    /**
     * Determina si el periodo efectivo de evaluación es más amplio que
     * el rango visual que el usuario tiene seleccionado en la UI.
     *
     * Esto activa una alerta en el frontend para que el usuario sepa
     * que el progreso mostrado incluye producción acumulada de meses
     * anteriores.
     *
     * @param  array  $result  Un resultado individual del orquestador.
     * @return bool
     */
    private function isPeriodWiderThanVisual(array $result): bool
    {
        $effStart = $result['effective_period_start'] ?? null;
        $effEnd   = $result['effective_period_end']   ?? null;
        $visStart = $result['visual_period_start']    ?? null;
        $visEnd   = $result['visual_period_end']      ?? null;

        if ($effStart === null || $effEnd === null || $visStart === null || $visEnd === null) {
            return false;
        }

        $effStartDate = Carbon::parse($effStart);
        $effEndDate   = Carbon::parse($effEnd);
        $visStartDate = Carbon::parse($visStart);
        $visEndDate   = Carbon::parse($visEnd);

        // True si el periodo efectivo comienza antes del visual O termina después
        return $effStartDate->lt($visStartDate) || $effEndDate->gt($visEndDate);
    }
}
