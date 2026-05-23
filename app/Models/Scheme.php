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
        'code',
        'type',
        'target',
        'is_active',
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