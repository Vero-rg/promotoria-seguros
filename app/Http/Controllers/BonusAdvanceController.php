<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBonusAdvanceRequest;
use App\Models\Agent;
use App\Models\Promoter;
use App\Models\Scheme;
use App\Services\BonusAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Guarda el monto del anticipo mensual de un bono.
 */
class BonusAdvanceController extends Controller
{
    public function store(SaveBonusAdvanceRequest $request, BonusAdvanceService $advances): RedirectResponse
    {
        $data = $request->validated();

        $scheme = Scheme::find($data['scheme_id']);

        if ($scheme === null || ! $advances->supports($scheme) || $scheme->target !== $data['user_type']) {
            Log::warning('Intento de guardar un anticipo en un bono no válido.', [
                'scheme_id' => $data['scheme_id'],
                'user_type' => $data['user_type'],
            ]);

            return back()->with('error', 'El bono seleccionado no permite anticipos mensuales.');
        }

        $userModel = $data['user_type'] === 'agent' ? Agent::class : Promoter::class;

        if (! $userModel::whereKey($data['user_id'])->exists()) {
            return back()->with('error', 'El usuario del anticipo no existe.');
        }

        $advances->save(
            scheme: $scheme,
            userType: $data['user_type'],
            userId: (int) $data['user_id'],
            periodMonth: $data['period_month'],
            amount: (float) $data['amount'],
            createdBy: $request->user()?->id,
        );

        return back()->with('success', 'Anticipo actualizado correctamente.');
    }
}
