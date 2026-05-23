<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchemeVersion;


class SchemeTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheme_version_id',
        'conditions',
        'agent_percentage',
        'promoter_percentage',
        'fixed_amount',
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    // Relación: Una banda pertenece a un esquema
    public function version()
    {
        return $this->belongsTo(SchemeVersion::class, 'scheme_version_id');
    }
}