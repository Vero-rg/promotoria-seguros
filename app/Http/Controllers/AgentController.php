<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Promoter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgentController extends Controller
{
    // El index no es necesario aquí, ya que el directorio maneja la vista general
    public function index()
    {
        return redirect()->route('directorio');
    }

    // El create también se maneja de forma unificada desde PromoterController@create
    public function create()
    {
        return redirect()->route('promoters.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'promoter_id' => 'nullable|exists:promoters,id',
            'name' => 'required|string|max:255',
        ]);

        Agent::create($validated);
        
        return redirect()->route('directorio')->with('success', 'Agente registrado correctamente.');
    }

    public function show(Agent $agent)
    {
        $agent->load(['promoter', 'policies']);
        return Inertia::render('Directory/Show', [
            'entity' => $agent,
            'type' => 'agent'
        ]);
    }

    public function edit(Agent $agent)
    {
        return Inertia::render('Directory/Edit', [
            'entity' => $agent,
            'type' => 'agent',
            'promoters' => Promoter::orderBy('name')->get()
        ]);
    }

    public function update(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'promoter_id' => 'nullable|exists:promoters,id',
            'name' => 'sometimes|required|string|max:255',
        ]);

        $agent->update($validated);
        return redirect()->route('directorio')->with('success', 'Agente actualizado correctamente.');
    }

    public function destroy(Agent $agent)
    {
        $agent->delete();
        return redirect()->route('directorio')->with('success', 'Agente eliminado correctamente.');
    }
}