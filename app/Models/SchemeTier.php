<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchemeTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheme_id',
        'min_amount',
        'max_amount',
        'percentage',
        'fixed_amount',
    ];

    // Relación: Una banda pertenece a un esquema
    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }
}