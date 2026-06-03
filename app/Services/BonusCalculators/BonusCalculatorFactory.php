<?php

namespace App\Services\BonusCalculators;

use InvalidArgumentException;

/**
 * Factory que instancia la calculadora de bono correcta según el nombre
 * del esquema (Scheme::$name).
 *
 * Respeta el Principio de Abierto/Cerrado (OCP): para agregar un nuevo
 * bono basta con agregar un nuevo case en el mapa sin modificar el factory.
 */
class BonusCalculatorFactory
{
    /**
     * Mapa de nombre de esquema → FQCN de la calculadora Strategy.
     *
     * Las claves deben coincidir con el campo `name` en la tabla `schemes`.
     *
     * @var array<string, class-string<BonusCalculatorInterface>>
     */
    /**
     * Mapa de nombre/template_key de esquema → FQCN de la calculadora Strategy.
     *
     * Incluye tanto los template_key (usados por el frontend Create.vue) como
     * los nombres descriptivos que pueden llegar almacenados en BD.
     *
     * @var array<string, class-string<BonusCalculatorInterface>>
     */
    protected array $calculatorMap = [
        // ── Bonos de Promotor ────────────────────────────
        'connection'                         => ConnectionBonusCalculator::class,
        'Conexión'                           => ConnectionBonusCalculator::class,
        'first_year_production'              => PromoterFirstYearProductionCalculator::class,
        'Producción de 1er Año Trimestral'   => PromoterFirstYearProductionCalculator::class,
        'monthly_development'                => MonthlyDevelopmentCalculator::class,
        'Desarrollo Mensual'                 => MonthlyDevelopmentCalculator::class,
        'activity_ratio'                     => ActivityRatioCalculator::class,
        'Activity Ratio'                     => ActivityRatioCalculator::class,
        'additional_agents'                  => AdditionalAgentsCalculator::class,
        'Adicional por Agentes con Compensación' => AdditionalAgentsCalculator::class,

        // ── Bonos de Agente ──────────────────────────────
        'agent_first_year_production'        => AgentFirstYearProductionCalculator::class,
        'Producción 1er Año Vida Trimestral (3 meses)' => AgentFirstYearProductionCalculator::class,
    ];

    /**
     * Crea la instancia de la calculadora adecuada para el esquema dado.
     *
     * @param  string  $schemeName  El valor de Scheme::$name.
     * @return BonusCalculatorInterface
     *
     * @throws InvalidArgumentException Si no existe calculadora para ese nombre.
     */
    public function make(string $schemeName): BonusCalculatorInterface
    {
        if (!isset($this->calculatorMap[$schemeName])) {
            throw new InvalidArgumentException(
                "No existe una calculadora de bono para el esquema: «{$schemeName}». " .
                'Esquemas disponibles: ' . implode(', ', array_keys($this->calculatorMap))
            );
        }

        $class = $this->calculatorMap[$schemeName];

        /** @var BonusCalculatorInterface */
        return app($class);
    }

    /**
     * Registra (o sobrescribe) una calculadora en tiempo de ejecución.
     *
     * Útil para testing o para paquetes externos que quieran extender el sistema.
     *
     * @param  string  $schemeName
     * @param  class-string<BonusCalculatorInterface>  $calculatorClass
     * @return void
     */
    public function register(string $schemeName, string $calculatorClass): void
    {
        $this->calculatorMap[$schemeName] = $calculatorClass;
    }

    /**
     * Retorna la lista de nombres de esquema soportados.
     *
     * @return array<int, string>
     */
    public function supportedSchemes(): array
    {
        return array_keys($this->calculatorMap);
    }
}
