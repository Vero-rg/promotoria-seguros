<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use Illuminate\Http\Request;
use Inertia\Inertia; 

class SchemeController extends Controller
{
    // Método para la vista de Comisiones
    public function index()
    {
        // Traemos solo los esquemas de tipo 'commission'
        $schemes = Scheme::with('tiers')
            ->where('type', 'commission')
            ->orderBy('id', 'desc')
            ->get();
            
        return Inertia::render('Scheme/Index', [
            'schemes' => $schemes
        ]);
    }

    public function createCommission()
    {
         return Inertia::render('Scheme/Commissions/Create');
    }

    // Método para la vista de Bonos
    public function bonuses()
    {
        // Traemos solo los esquemas de tipo 'bonus'
        $schemes = Scheme::with('tiers')
            ->where('type', 'bonus')
            ->orderBy('id', 'desc')
            ->get();
            
        return Inertia::render('Scheme/Bonuses/Index', [
            'schemes' => $schemes
        ]);
    }

    public function createBonus()
    {
        return Inertia::render('Scheme/Bonuses/Create');
    }

    // --- Los métodos para guardar/actualizar/eliminar por API se mantienen igual ---

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:schemes,code',
            'type' => 'required|string',
            'target' => 'required|string',
            'is_active' => 'boolean',
            'version_name' => 'required|string',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'tiers' => 'required|array',
            'tiers.*.product_type' => 'required|string',
            'tiers.*.agent_percentage' => 'required|numeric',
            'tiers.*.promoter_percentage' => 'required|numeric',
        ]);

        $scheme = Scheme::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'type' => $validated['type'],
            'target' => $validated['target'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $version = $scheme->versions()->create([
            'version_name' => $validated['version_name'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        foreach ($validated['tiers'] as $tier) {
            $version->tiers()->create([
                'conditions' => ['product_type' => $tier['product_type']],
                'agent_percentage' => $tier['agent_percentage'],
                'promoter_percentage' => $tier['promoter_percentage'],
            ]);
        }

        return redirect()->route('esquemas.index');    
    }

    public function show(Scheme $scheme)
    {
        $scheme->load(['versions.tiers']);
        return Inertia::render('Scheme/Commissions/Show', [
            'scheme' => $scheme
        ]);
    }

    public function update(Request $request, Scheme $scheme)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $scheme->update($validated);
        return response()->json($scheme);
    }

    public function destroy(Scheme $scheme)
    {
        $scheme->delete();
        return response()->json(null, 204);
    }
}