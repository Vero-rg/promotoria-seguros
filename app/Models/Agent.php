<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Policy;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'promoter_id',
        'name',
        'photo',
        'is_active',
        'deactivated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    // Relación: Un agente pertenece a un promotor
    public function promoter()
    {
        return $this->belongsTo(Promoter::class);
    }

    // Relación: Un agente tiene muchas pólizas (ventas)
    public function policies()
    {
        return $this->hasMany(Policy::class);
    }

    /**
     * Scope: Agentes que deben considerarse "activos" para un periodo dado.
     *
     * Un agente inactivo SOLO aparece hasta el mes en que fue dado de baja.
     * Si el periodo termina en un mes posterior al de su baja, se excluye.
     *
     * Ejemplo: dado de baja el 13/05/2026 → aparece en mayo, desaparece en junio.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $periodEnd  Fecha de fin del periodo (Y-m-d).
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveInPeriod($query, string $periodEnd)
    {
        $monthKey = substr($periodEnd, 0, 7); // "YYYY-MM"

        return $query->where(function ($q) use ($monthKey) {
            $q->where('is_active', true)
              ->orWhere(function ($q2) use ($monthKey) {
                  $q2->where('is_active', false)
                     ->whereNotNull('deactivated_at')
                     ->whereRaw("DATE_FORMAT(deactivated_at, '%Y-%m') >= ?", [$monthKey]);
              });
        });
    }
}