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
    ];

    // Relación: Un promotor tiene muchos agentes
    public function agents()
    {
        return $this->hasMany(Agent::class);
    }
}