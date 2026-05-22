<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchemeTier;

class Scheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'target',
        'is_active',
    ];

    // Relación: Un esquema (cuaderno) tiene muchas bandas/niveles
    public function tiers()
    {
        return $this->hasMany(SchemeTier::class);
    }
}