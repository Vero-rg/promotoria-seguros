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
        'cedular_tax_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'cedular_tax_enabled' => 'boolean',
    ];

    // Relación: Un agente pertenece a un promotor
    public function promoter()
    {
        return $this->belongsTo(Promoter::class);
    }

    /**
     * Nombre corto del agente para desgloses.
     *
     * Quita el prefijo del promotor cuando el nombre del agente lo repite
     * (p. ej. «PRUEBA B - Anticipo mensual · Agente 1» → «Agente 1»).
     */
    public function shortName(?string $promoterName = null): string
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            return '';
        }

        $promoterName = trim((string) ($promoterName ?? $this->promoter?->name ?? ''));

        if ($promoterName === '' || mb_stripos($name, $promoterName) !== 0) {
            return $name;
        }

        $stripped = preg_replace('/^[\s·\-:|–—]+/u', '', mb_substr($name, mb_strlen($promoterName)));
        $stripped = trim((string) $stripped);

        return $stripped !== '' ? $stripped : $name;
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

        // Función de formato del mes según el motor de BD (MySQL en producción, SQLite en pruebas).
        $monthExpression = match ($query->getModel()->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', deactivated_at)",
            'pgsql'  => "to_char(deactivated_at, 'YYYY-MM')",
            default  => "DATE_FORMAT(deactivated_at, '%Y-%m')",
        };

        return $query->where(function ($q) use ($monthKey, $monthExpression) {
            $q->where('is_active', true)
              ->orWhere(function ($q2) use ($monthKey, $monthExpression) {
                  $q2->where('is_active', false)
                     ->whereNotNull('deactivated_at')
                     ->whereRaw("{$monthExpression} >= ?", [$monthKey]);
              });
        });
    }
}