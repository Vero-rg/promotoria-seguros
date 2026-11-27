<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;

    public const STATUS_ACTIVA = 'Activa';
    public const STATUS_NO_TOMADA = 'No tomada';
    public const STATUS_PAGADA = 'Pagada';

    public const STATUSES = [
        self::STATUS_ACTIVA,
        self::STATUS_NO_TOMADA,
        self::STATUS_PAGADA,
    ];

    public const STATUS_COLORS = [
        self::STATUS_ACTIVA => 'green',
        self::STATUS_NO_TOMADA => 'gray',
        self::STATUS_PAGADA => 'blue',
    ];

    /** Deducción fija sobre el PNA para obtener el PCA (por póliza). */
    public const PCA_DEDUCTION = 1350.00;

    /**
     * Productos cuyo monto NO participa en el cálculo de bonos.
     *
     * Estas pólizas solo aportan en comisiones y en el conteo de requisitos
     * de producto (requires_product), pero su prima no suma a PP, PCA, PNA
     * ni a los índices ponderados por monto de ningún bono.
     */
    public const BONUS_AMOUNT_EXCLUDED_PRODUCTS = ['PRIMORDIAL'];

    public const PAYMENT_METHOD_CASH = 'Efectivo';

    public const PAYMENT_METHOD_AUTOMATIC = 'Automático';

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_AUTOMATIC,
    ];

    protected $fillable = [
        'agent_id',
        'policy_number',
        'client_name',
        'issue_date',
        'premium_amount',
        'commission_percentage',
        'commission_amount',
        'promoter_commission_percentage',
        'promoter_commission_amount',
        'isr_retention',
        'cedular_tax',
        'billing_retention',
        'status',
        'product_type',
        'payment_method',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'premium_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'promoter_commission_amount' => 'decimal:2',
        'isr_retention' => 'decimal:2',
        'cedular_tax' => 'decimal:2',
        'billing_retention' => 'decimal:2',
    ];

    // ─── Relaciones ─────────────────────────────────

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    // ─── Métodos de Estatus ─────────────────────────

    /**
     * Cambia el estatus de la póliza al nuevo valor.
     */
    public function changeStatus(string $newStatus): self
    {
        if (!in_array($newStatus, self::STATUSES)) {
            throw new \InvalidArgumentException("Estatus inválido: {$newStatus}");
        }

        $this->update(['status' => $newStatus]);

        return $this;
    }

    /**
     * Marca la póliza como activa.
     */
    public function activate(): self
    {
        return $this->changeStatus(self::STATUS_ACTIVA);
    }

    /**
     * Marca la póliza como no tomada.
     */
    public function markAsNoTomada(): self
    {
        return $this->changeStatus(self::STATUS_NO_TOMADA);
    }

    /**
     * Marca la póliza como pagada.
     */
    public function markAsPaid(): self
    {
        return $this->changeStatus(self::STATUS_PAGADA);
    }

    /**
     * Determina si la póliza está activa.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVA;
    }

    // ─── Montos ─────────────────────────────────────

    /**
     * Indica si el monto de una póliza participa en el cálculo de bonos.
     */
    public static function isCountableForBonusAmount(?string $productType): bool
    {
        return ! in_array(
            mb_strtoupper(trim((string) $productType)),
            self::BONUS_AMOUNT_EXCLUDED_PRODUCTS,
            true,
        );
    }

    /**
     * Excluye las pólizas cuyo monto no participa en el cálculo de bonos.
     */
    public function scopeCountableForBonusAmount(Builder $query): Builder
    {
        $placeholders = implode(', ', array_fill(0, count(self::BONUS_AMOUNT_EXCLUDED_PRODUCTS), '?'));

        return $query->whereRaw(
            "UPPER(TRIM(COALESCE(product_type, ''))) NOT IN ({$placeholders})",
            array_map('mb_strtoupper', self::BONUS_AMOUNT_EXCLUDED_PRODUCTS),
        );
    }

    /**
     * Prima Computable Acumulada: PNA menos la deducción fija por póliza.
     * Es la base para calcular comisiones y bonos.
     */
    public function pcaAmount(): float
    {
        return round(max(0, (float) $this->premium_amount - self::PCA_DEDUCTION), 2);
    }

    /**
     * Calcula el monto neto de la comisión del agente después de
     * deducciones por ISR, impuesto cedular y costo de facturación,
     * aplicadas secuencialmente sobre la comisión.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function agentNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return $this->netCommissionAmount((float) $this->commission_amount);
    }

    /**
     * Calcula el monto neto de la comisión del promotor después de
     * deducciones por ISR, impuesto cedular y costo de facturación,
     * aplicadas secuencialmente sobre la comisión.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function promoterNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return $this->netCommissionAmount((float) $this->promoter_commission_amount);
    }

    /**
     * Calcula el monto neto total de la póliza: suma de las comisiones
     * netas del agente y del promotor después de deducciones.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function netAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return round($this->agentNetAmount() + $this->promoterNetAmount(), 2);
    }

    /**
     * Deducciones secuenciales sobre una comisión: ISR → impuesto cedular → facturación.
     */
    private function netCommissionAmount(float $commissionAmount): float
    {
        $isrPct = (float) ($this->isr_retention ?? 0);
        $cedularPct = (float) ($this->cedular_tax ?? 0);
        $billingPct = (float) ($this->billing_retention ?? 0);

        $afterIsr = $commissionAmount - ($commissionAmount * ($isrPct / 100));
        $afterCedular = $afterIsr - ($afterIsr * ($cedularPct / 100));
        $afterBilling = $afterCedular - ($afterCedular * ($billingPct / 100));

        return round($afterBilling, 2);
    }
}