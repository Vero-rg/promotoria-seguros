<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchemeVersion extends Model
{
    protected $fillable = ['scheme_id', 'version_name', 'starts_at', 'ends_at'];

    // Una versión pertenece a un esquema principal
    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }

    // Una versión tiene muchos rangos (tiers) configurados
    public function tiers()
    {
        return $this->hasMany(SchemeTier::class, 'scheme_version_id');
    }
}