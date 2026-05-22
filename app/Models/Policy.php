<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'policy_number',
        'issue_date',
        'premium_amount',
        'commission_percentage',
        'commission_amount',
        'isr_retention',
        'billing_retention',
        'status',
    ];

    // Relación: Una póliza pertenece a un agente
    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}