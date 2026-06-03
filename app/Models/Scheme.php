<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;

class Scheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'template_key',
        'target',
        'is_active',
        // Reglas Globales
        'metric_base',
        'frequency',
        'requires_anticipos',
        'anticipos_config',
        'applies_annual_adjustment',
        'requires_product',
        'min_product_count',
        'requires_mix',
        'dependency_scheme_id',
        'min_irp',
        'min_collection_efficiency',
        'quarterly_recruits',
        'pna_equivalences',
    ];

    // Casteo para manejar los JSON de configuración directamente como Arrays en PHP
    protected $casts = [
        'is_active' => 'boolean',
        'requires_anticipos' => 'boolean',
        'applies_annual_adjustment' => 'boolean',
        'requires_mix' => 'boolean',
        'anticipos_config' => 'array',
        'requires_product' => 'array',
        'quarterly_recruits' => 'array',
        'pna_equivalences' => 'array',
        'dependency_scheme_id' => 'string',
    ];

    // Relación: Un esquema (cuaderno) tiene muchas bandas/niveles a través de sus versiones
    public function versions()
    {
        return $this->hasMany(SchemeVersion::class);
    }

    // Relación: Un esquema tiene muchos rangos a través de sus versiones
    public function tiers()
    {
        return $this->hasManyThrough(SchemeTier::class, SchemeVersion::class);
    }
}