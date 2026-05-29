<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;

    public const STATUS_ACTIVA = 'Activa';
    public const STATUS_CANCELADA = 'Cancelada';
    public const STATUS_PAGADA = 'Pagada';

    public const STATUSES = [
        self::STATUS_ACTIVA,
        self::STATUS_CANCELADA,
        self::STATUS_PAGADA,
    ];

    public const STATUS_COLORS = [
        self::STATUS_ACTIVA => 'green',
        self::STATUS_CANCELADA => 'red',
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
     * Marca la póliza como cancelada.
     */
    public function cancel(): self
    {
        return $this->changeStatus(self::STATUS_CANCELADA);
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
     * Calcula el monto neto después de todas las deducciones.
     */
    public function netAmount(): float
    {
        $premium = (float) $this->premium_amount;
        $agentComm = (float) $this->commission_amount;
        $promoterComm = (float) $this->promoter_commission_amount;
        $isr = $premium * ((float) $this->isr_retention / 100);
        $billing = $premium * ((float) $this->billing_retention / 100);

        return $premium - $agentComm - $promoterComm - $isr - $billing;
    }
}