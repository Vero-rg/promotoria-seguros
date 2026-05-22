<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use Illuminate\Http\Request;
use Inertia\Inertia; // Agregamos Inertia para renderizar vistas Vue

class SchemeController extends Controller
{
    // Método para la vista de Comisiones
    public function commissions()
    {
        // Traemos solo los esquemas de tipo 'commission'
        $schemes = Scheme::with('tiers')
            ->where('type', 'commission')
            ->orderBy('id', 'desc')
            ->get();
            
        return Inertia::render('Scheme/Partials/Comissions', [
            'schemes' => $schemes
        ]);
    }

    // Método para la vista de Bonos
    public function bonuses()
    {
        // Traemos solo los esquemas de tipo 'bonus'
        $schemes = Scheme::with('tiers')
            ->where('type', 'bonus')
            ->orderBy('id', 'desc')
            ->get();
            
        return Inertia::render('Scheme/Partials/Bonnuses', [
            'schemes' => $schemes
        ]);
    }

    // --- Los métodos para guardar/actualizar/eliminar por API se mantienen igual ---

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'target' => 'required|string',
            'is_active' => 'boolean',
        ]);

        $scheme = Scheme::create($validated);
        
        // Si usas Inertia para todo, quizás quieras hacer un redirect en lugar de JSON
        // return redirect()->back()->with('success', 'Esquema creado');
        return response()->json($scheme, 201);
    }

    public function show(Scheme $scheme)
    {
        $scheme->load('tiers');
        return response()->json($scheme);
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