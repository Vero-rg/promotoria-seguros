<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anticipo mensual de un bono trimestral.
 *
 * Guarda el monto que el usuario recibió como adelanto en un mes concreto.
 * Si no existe registro, el monto mostrado es el calculado automáticamente.
 */
class BonusAdvance extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheme_id',
        'user_type',
        'user_id',
        'period_month',
        'amount',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
