<?php

namespace App\Services;

use App\Models\Policy;

class CommissionBreakdownService
{
    public const ROLE_AGENT = 'agent';

    public const ROLE_PROMOTER = 'promoter';

    private ?array $commissionMap = null;

    public function __construct(private ProductCommissionService $productCommissionService) {}

    /**
     * Suma el desglose de comisiones de una colección de pólizas para el rol indicado.
     *
     * @param  iterable<Policy>  $policies
     * @return array{gross: float, isr: float, cedular: float, billing: float, net: float}
     */
    public function summarize(iterable $policies, string $role): array
    {
        $totals = ['gross' => 0.0, 'isr' => 0.0, 'cedular' => 0.0, 'billing' => 0.0, 'net' => 0.0];

        foreach ($policies as $policy) {
            foreach ($this->forPolicy($policy, $role) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return array_map(fn (float $value) => round($value, 2), $totals);
    }

    /**
     * Desglose de la comisión de una póliza. Las deducciones se aplican en orden:
     * ISR → impuesto cedular → costo de facturación.
     *
     * @return array{gross: float, isr: float, cedular: float, billing: float, net: float}
     */
    public function forPolicy(Policy $policy, string $role): array
    {
        $gross = $this->grossCommission($policy, $role);

        if ($policy->status !== Policy::STATUS_PAGADA || $gross <= 0) {
            return ['gross' => 0.0, 'isr' => 0.0, 'cedular' => 0.0, 'billing' => 0.0, 'net' => 0.0];
        }

        $isr = $gross * ($this->retention($policy, 'isr_retention') / 100);
        $afterIsr = $gross - $isr;

        $cedular = $afterIsr * ($this->retention($policy, 'cedular_tax') / 100);
        $afterCedular = $afterIsr - $cedular;

        $billing = $afterCedular * ($this->retention($policy, 'billing_retention') / 100);
        $net = $afterCedular - $billing;

        return [
            'gross' => round($gross, 2),
            'isr' => round($isr, 2),
            'cedular' => round($cedular, 2),
            'billing' => round($billing, 2),
            'net' => round($net, 2),
        ];
    }

    /**
     * Comisión bruta: el monto almacenado en la póliza (si existe) o PCA por el
     * porcentaje de la póliza. Si la póliza no tiene porcentaje se usa el esquema
     * de comisión activo.
     */
    public function grossCommission(Policy $policy, string $role): float
    {
        $stored = (float) ($role === self::ROLE_PROMOTER
            ? $policy->promoter_commission_amount
            : $policy->commission_amount);

        if ($stored > 0) {
            return round($stored, 2);
        }

        $percentage = $this->percentageFor($policy, $role);

        if ($percentage <= 0) {
            return 0.0;
        }

        return round($policy->pcaAmount() * ($percentage / 100), 2);
    }

    /**
     * Porcentaje de comisión de la póliza para el rol, con respaldo al esquema activo.
     */
    public function percentageFor(Policy $policy, string $role): float
    {
        $percentage = (float) ($role === self::ROLE_PROMOTER
            ? $policy->promoter_commission_percentage
            : $policy->commission_percentage);

        if ($percentage > 0) {
            return $percentage;
        }

        return $this->percentageFromActiveScheme($policy->product_type, $role);
    }

    private function percentageFromActiveScheme(?string $productType, string $role): float
    {
        if (! $productType) {
            return 0.0;
        }

        $this->commissionMap ??= $this->productCommissionService->map();

        $product = $this->commissionMap[$productType] ?? null;

        if (! $product) {
            return 0.0;
        }

        return (float) ($role === self::ROLE_PROMOTER
            ? ($product['promoter_percentage'] ?? 0)
            : ($product['agent_percentage'] ?? 0));
    }

    private function retention(Policy $policy, string $attribute): float
    {
        return (float) ($policy->{$attribute} ?? 0);
    }
}
