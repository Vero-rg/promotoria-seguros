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
        $schemes = Scheme::with(['tiers', 'versions'])
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
        $schemes = Scheme::with(['tiers', 'versions'])
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
            'type' => 'required|string',
            'template_key' => 'nullable|string|max:255',
            'target' => 'required|string|in:promoter,agent,both',
            'is_active' => 'boolean',
            
            // Reglas Globales
            'metric_base' => 'required|string|in:PCA,PP,PNA',
            'frequency' => 'required|string|in:única,mensual,trimestral,anual',
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
            'tiers.*.agent_automatic_percentage' => 'nullable|numeric',
            'tiers.*.promoter_percentage' => 'nullable|numeric',
        ]);

        // Si es una comisión y se activa, desactivar todas las demás comisiones activas
        $isActive = $validated['is_active'] ?? true;
        if ($validated['type'] === 'commission' && $isActive) {
            Scheme::where('type', 'commission')->where('is_active', true)->update(['is_active' => false]);
        }

        // Si es un bono y se activa, desactivar otros bonos activos con el mismo nombre
        if ($validated['type'] === 'bonus' && $isActive) {
            Scheme::where('type', 'bonus')
                ->where('name', $validated['name'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        // ── Resolver dependency_scheme_id ─────────────────────────────
        // El frontend puede enviar un nombre de esquema (string) o un ID (int).
        // Si es un string no numérico, lo buscamos por nombre (template_key o name).
        $dependencyId = null;
        if (!empty($validated['dependency_scheme_id'])) {
            $depInput = $validated['dependency_scheme_id'];
            if (is_numeric($depInput)) {
                $dependencyId = (int) $depInput;
            } else {
                $depScheme = Scheme::where('type', 'bonus')
                    ->where(function ($q) use ($depInput) {
                        $q->where('name', $depInput)
                          ->orWhere('template_key', $depInput);
                    })
                    ->first();
                $dependencyId = $depScheme?->id;
            }
        }

        $scheme = Scheme::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'template_key' => $validated['template_key'] ?? null,
            'target' => $validated['target'],
            'is_active' => $isActive,
            
            // Asignación de Reglas Globales (Asegurar casteos en el Modelo Scheme)
            'metric_base' => $validated['metric_base'],
            'frequency' => $validated['frequency'],
            'requires_anticipos' => $validated['requires_anticipos'] ?? false,
            'anticipos_config' => $validated['anticipos_config'] ?? null,
            'applies_annual_adjustment' => $validated['applies_annual_adjustment'] ?? false,
            'requires_product' => $validated['requires_product'] ?? [],
            'min_product_count' => $validated['min_product_count'] ?? 0,
            'requires_mix' => $validated['requires_mix'] ?? false,
            'dependency_scheme_id' => $dependencyId,
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
                'agent_automatic_percentage' => (float) ($tier['agent_automatic_percentage'] ?? 0),
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
            'type' => 'required|string',
            'is_active' => 'boolean',
            'template_key' => 'nullable|string|max:255',
            'target' => 'required|string|in:promoter,agent,both',

            // Reglas Globales (Update)
            'metric_base' => 'required|string|in:PCA,PP,PNA',
            'frequency' => 'required|string|in:única,mensual,trimestral,anual',
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
            'tiers.*.agent_automatic_percentage' => 'nullable|numeric',
            'tiers.*.promoter_percentage' => 'nullable|numeric',
        ]);

        // Si es una comisión y se activa, desactivar las demás comisiones activas
        $isActive = $validated['is_active'] ?? $scheme->is_active;
        if ($scheme->type === 'commission' && $isActive) {
            Scheme::where('type', 'commission')
                ->where('is_active', true)
                ->where('id', '!=', $scheme->id)
                ->update(['is_active' => false]);
        }

        // Si es un bono y se activa, desactivar otros bonos activos con el mismo nombre
        if ($scheme->type === 'bonus' && $isActive) {
            Scheme::where('type', 'bonus')
                ->where('name', $validated['name'])
                ->where('is_active', true)
                ->where('id', '!=', $scheme->id)
                ->update(['is_active' => false]);
        }

        // ── Resolver dependency_scheme_id ─────────────────────────────
        $dependencyId = null;
        if (!empty($validated['dependency_scheme_id'])) {
            $depInput = $validated['dependency_scheme_id'];
            if (is_numeric($depInput)) {
                $dependencyId = (int) $depInput;
            } else {
                $depScheme = Scheme::where('type', 'bonus')
                    ->where(function ($q) use ($depInput) {
                        $q->where('name', $depInput)
                          ->orWhere('template_key', $depInput);
                    })
                    ->where('id', '!=', $scheme->id) // No puede depender de sí mismo
                    ->first();
                $dependencyId = $depScheme?->id;
            }
        }

        $scheme->update([
            'name' => $validated['name'],
            'is_active' => $isActive,
            'template_key' => $validated['template_key'] ?? $scheme->template_key,
            'target' => $validated['target'],
            'metric_base' => $validated['metric_base'],
            'frequency' => $validated['frequency'],
            'requires_anticipos' => $validated['requires_anticipos'] ?? false,
            'anticipos_config' => $validated['anticipos_config'] ?? null,
            'applies_annual_adjustment' => $validated['applies_annual_adjustment'] ?? false,
            'requires_product' => $validated['requires_product'] ?? [],
            'min_product_count' => $validated['min_product_count'] ?? 0,
            'requires_mix' => $validated['requires_mix'] ?? false,
            'dependency_scheme_id' => $dependencyId,
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
             // Sincronizar tiers: eliminar viejos y crear nuevos
             $version->tiers()->delete();
             foreach ($validated['tiers'] as $tier) {
                 $conditions = $tier['conditions'] ?? [];
                 if (isset($tier['product_type'])) {
                     $conditions['product_type'] = $tier['product_type'];
                 }
                 $version->tiers()->create([
                     'conditions' => $conditions,
                     'agent_percentage' => (float) ($tier['agent_percentage'] ?? 0),
                     'agent_automatic_percentage' => (float) ($tier['agent_automatic_percentage'] ?? 0),
                     'promoter_percentage' => (float) ($tier['promoter_percentage'] ?? 0),
                 ]);
             }
         }
 
        return to_route($scheme->type === 'bonus' ? 'esquemas.bonos' : 'esquemas.index')->with('success', 'Esquema actualizado exitosamente.');
    }

    public function destroy(Scheme $scheme)
    {
        $type = $scheme->type;
        $scheme->delete();

        return $type === 'bonus'
            ? redirect()->route('esquemas.bonos')->with('success', 'Esquema eliminado exitosamente.')
            : redirect()->route('esquemas.index')->with('success', 'Esquema eliminado exitosamente.');
    }
}