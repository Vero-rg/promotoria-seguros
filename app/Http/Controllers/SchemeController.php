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

    public function editCommission(Scheme $scheme)
    {
         $scheme->load(['versions.tiers']);
         return Inertia::render('Scheme/Commissions/Edit', [
             'scheme' => $scheme
         ]);
    }

    // Método para la vista de Bonos
    public function bonuses()
    {
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

    public function editBonus(Scheme $scheme)
    {
         $scheme->load(['versions.tiers']);
         return Inertia::render('Scheme/Bonuses/Edit', [
             'scheme' => $scheme
         ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:schemes,code',
            'type' => 'required|string',
            'target' => 'required|string|in:promoter,agent',
            'is_active' => 'boolean',
            
            // Reglas Globales
            'metric_base' => 'required|string|in:PCA,PP,PNA',
            'frequency' => 'required|string|in:mensual,trimestral,anual',
            'requires_anticipos' => 'boolean',
            'anticipos_config' => 'nullable|array',
            'applies_annual_adjustment' => 'boolean',
            'requires_product' => 'nullable|array',
            'min_product_count' => 'nullable|integer|min:0',
            'requires_mix' => 'boolean',
            'dependency_scheme_id' => 'nullable|string',
            'min_irp' => 'nullable|numeric|min:0|max:100',
            'min_collection_efficiency' => 'nullable|numeric|min:0|max:100',
            'quarterly_recruits' => 'nullable|array',
            'pna_equivalences' => 'nullable|array',
            'pna_equivalences.*.min_pna' => 'nullable|numeric',
            'pna_equivalences.*.max_pna' => 'nullable|numeric',
            'pna_equivalences.*.policies' => 'nullable|numeric',

            // Version y Tiers
            'version_name' => 'required|string',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'tiers' => 'required|array',
            'tiers.*.conditions' => 'nullable|array',
            'tiers.*.product_type' => 'nullable|string',
            'tiers.*.agent_percentage' => 'nullable|numeric',
            'tiers.*.promoter_percentage' => 'nullable|numeric',
        ]);

        $scheme = Scheme::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'type' => $validated['type'],
            'target' => $validated['target'],
            'is_active' => $validated['is_active'] ?? true,
            
            // Asignación de Reglas Globales (Asegurar casteos en el Modelo Scheme)
            'metric_base' => $validated['metric_base'],
            'frequency' => $validated['frequency'],
            'requires_anticipos' => $validated['requires_anticipos'] ?? false,
            'anticipos_config' => $validated['anticipos_config'] ?? null,
            'applies_annual_adjustment' => $validated['applies_annual_adjustment'] ?? false,
            'requires_product' => $validated['requires_product'] ?? [],
            'min_product_count' => $validated['min_product_count'] ?? 0,
            'requires_mix' => $validated['requires_mix'] ?? false,
            'dependency_scheme_id' => $validated['dependency_scheme_id'],
            'min_irp' => $validated['min_irp'] ?? 0,
            'min_collection_efficiency' => $validated['min_collection_efficiency'] ?? 0,
            'quarterly_recruits' => $validated['quarterly_recruits'] ?? null,
            'pna_equivalences' => $validated['pna_equivalences'] ?? null,
        ]);

        $version = $scheme->versions()->create([
            'version_name' => $validated['version_name'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        foreach ($validated['tiers'] as $tier) {
            $conditions = $tier['conditions'] ?? [];
            if (isset($tier['product_type'])) {
                $conditions['product_type'] = $tier['product_type'];
            }
            $version->tiers()->create([
                'conditions' => $conditions,
                'agent_percentage' => (float) ($tier['agent_percentage'] ?? 0),
                'promoter_percentage' => (float) ($tier['promoter_percentage'] ?? 0),
            ]);    
        }

        return $scheme->type === 'bonus'
            ? redirect()->route('esquemas.bonos') 
            : redirect()->route('esquemas.index');
    }

    public function show(Scheme $scheme)
    {
        $scheme->load(['versions.tiers']);
        return Inertia::render('Scheme/Commissions/Show', [
            'scheme' => $scheme
        ]);
    }

    public function showBonus(Scheme $scheme)
    {
        $scheme->load(['versions.tiers']);
        return Inertia::render('Scheme/Bonuses/Show', [
            'scheme' => $scheme
        ]);
    }

    public function update(Request $request, Scheme $scheme)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',

            // Reglas Globales (Update)
            'metric_base' => 'required|string|in:PCA,PP,PNA',
            'frequency' => 'required|string|in:mensual,trimestral,anual',
            'requires_anticipos' => 'boolean',
            'anticipos_config' => 'nullable|array',
            'applies_annual_adjustment' => 'boolean',
            'requires_product' => 'nullable|array',
            'min_product_count' => 'nullable|integer|min:0',
            'requires_mix' => 'boolean',
            'dependency_scheme_id' => 'nullable|string',
            'min_irp' => 'nullable|numeric|min:0|max:100',
            'min_collection_efficiency' => 'nullable|numeric|min:0|max:100',
            'quarterly_recruits' => 'nullable|array',
            'pna_equivalences' => 'nullable|array',
            'pna_equivalences.*.min_pna' => 'nullable|numeric',
            'pna_equivalences.*.max_pna' => 'nullable|numeric',
            'pna_equivalences.*.policies' => 'nullable|numeric',


            // Versiones
            'version_name' => 'required|string',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'tiers' => 'required|array',
            'tiers.*.conditions' => 'nullable|array',
            'tiers.*.product_type' => 'nullable|string',
            'tiers.*.agent_percentage' => 'nullable|numeric',
            'tiers.*.promoter_percentage' => 'nullable|numeric',
        ]);

        $scheme->update([
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'metric_base' => $validated['metric_base'],
            'frequency' => $validated['frequency'],
            'requires_anticipos' => $validated['requires_anticipos'] ?? false,
            'anticipos_config' => $validated['anticipos_config'] ?? null,
            'applies_annual_adjustment' => $validated['applies_annual_adjustment'] ?? false,
            'requires_product' => $validated['requires_product'] ?? [],
            'min_product_count' => $validated['min_product_count'] ?? 0,
            'requires_mix' => $validated['requires_mix'] ?? false,
            'dependency_scheme_id' => $validated['dependency_scheme_id'],
            'min_irp' => $validated['min_irp'] ?? 0,
            'min_collection_efficiency' => $validated['min_collection_efficiency'] ?? 0,
            'quarterly_recruits' => $validated['quarterly_recruits'] ?? null,
            'pna_equivalences' => $validated['pna_equivalences'] ?? null,
        ]);
 
         $version = $scheme->versions()->latest()->first();
         if ($version) {
             $version->update([
                 'version_name' => $validated['version_name'],
                 'starts_at' => $validated['starts_at'],
                 'ends_at' => $validated['ends_at'] ?? null,
             ]);
         }
 
        return redirect()->back();
    }

    public function destroy(Scheme $scheme)
    {
        $scheme->delete();
        return response()->json(null, 204);
    }
}