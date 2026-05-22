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
}