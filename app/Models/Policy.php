<?php

namespace App\Models;

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
        'billing_retention',
        'status',
        'product_type',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'premium_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'promoter_commission_amount' => 'decimal:2',
        'isr_retention' => 'decimal:2',
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

    /**
     * Calcula el monto neto de la comisión del agente después de
     * deducciones por ISR y costo de facturación sobre la comisión.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function agentNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        $agentComm = (float) $this->commission_amount;
        $isrPct    = (float) ($this->isr_retention ?? 0);
        $billPct   = (float) ($this->billing_retention ?? 0);

        $isr     = $agentComm * ($isrPct / 100);
        $billing = $agentComm * ($billPct / 100);

        return round($agentComm - $isr - $billing, 2);
    }

    /**
     * Calcula el monto neto de la comisión del promotor después de
     * deducciones por ISR y costo de facturación sobre la comisión.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function promoterNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        $promoterComm = (float) $this->promoter_commission_amount;
        $isrPct       = (float) ($this->isr_retention ?? 0);
        $billPct      = (float) ($this->billing_retention ?? 0);

        $isr     = $promoterComm * ($isrPct / 100);
        $billing = $promoterComm * ($billPct / 100);

        return round($promoterComm - $isr - $billing, 2);
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
}