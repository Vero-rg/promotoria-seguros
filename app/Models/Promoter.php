<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Agent;

class Promoter extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'photo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relación: Un promotor tiene muchos agentes
    public function agents()
    {
        return $this->hasMany(Agent::class);
    }
}