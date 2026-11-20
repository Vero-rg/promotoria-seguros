# 06 — Module: Compensation Schemes (Commissions + Bonuses)

## Business Purpose
Manages the configuration of how promoters and agents get paid. There are two types of schemes:
1. **Commissions (Comisiones):** Percentage-based payouts per product type per policy sale.
2. **Bonuses (Bonos):** Conditional, tiered payouts based on performance metrics like PCA, PP, IRP, recruitment count, and product mix.

Schemes support **versioning** (time-based effective dates) and **tiered conditions** (brackets/ranges).

## Key Files

| File | Role |
|------|------|
| `app/Models/Scheme.php` | Scheme model with all global rule fields |
| `app/Models/SchemeVersion.php` | Version model (time-bounded) |
| `app/Models/SchemeTier.php` | Tier model (conditional brackets) |
| `app/Http/Controllers/SchemeController.php` | Full CRUD for both commission and bonus schemes |
| `resources/js/Pages/Scheme/Index.vue` | Commission list |
| `resources/js/Pages/Scheme/Commissions/Create.vue` | Create commission form |
| `resources/js/Pages/Scheme/Commissions/Edit.vue` | Edit commission form |
| `resources/js/Pages/Scheme/Commissions/Show.vue` | Commission detail |
| `resources/js/Pages/Scheme/Bonuses/Index.vue` | Bonus list |
| `resources/js/Pages/Scheme/Bonuses/Create.vue` | Create bonus form |
| `resources/js/Pages/Scheme/Bonuses/Edit.vue` | Edit bonus form |
| `resources/js/Pages/Scheme/Bonuses/Show.vue` | Bonus detail |
| `routes/web.php` | Routes: `/schemes` resource + `/esquemas` prefix group |

---

## Routes

### Commission Routes
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/schemes` | `SchemeController@index` | `schemes.index` |
| GET | `/esquemas` | `SchemeController@index` | `esquemas.index` |
| GET | `/esquemas/comisiones/crear` | `SchemeController@createCommission` | `esquemas.comisiones.crear` |
| GET | `/esquemas/comisiones/{scheme}/editar` | `SchemeController@editCommission` | `esquemas.comisiones.editar` |
| GET | `/esquemas/comisiones/{scheme}` | `SchemeController@show` | `esquemas.comisiones.show` |

### Bonus Routes
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/esquemas/bonos` | `SchemeController@bonuses` | `esquemas.bonos` |
| GET | `/esquemas/bonos/crear` | `SchemeController@createBonus` | `esquemas.bonos.crear` |
| GET | `/esquemas/bonos/{scheme}/editar` | `SchemeController@editBonus` | `esquemas.bonos.editar` |
| GET | `/esquemas/bonos/{scheme}` | `SchemeController@showBonus` | `esquemas.bonos.show` |

### Shared Resource
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| POST | `/schemes` | `SchemeController@store` | `schemes.store` |
| PUT/PATCH | `/schemes/{scheme}` | `SchemeController@update` | `schemes.update` |
| DELETE | `/schemes/{scheme}` | `SchemeController@destroy` | `schemes.destroy` |

---

## Scheme Data Model

### Scheme Types
| Type | `type` value | Description |
|------|-------------|-------------|
| Commission | `commission` | Per-product percentage. Only ONE can be active at a time. |
| Bonus | `bonus` | Conditional tiered payout. Multiple can be active simultaneously. |

### Target
| `target` | Who Gets Paid |
|----------|--------------|
| `agent` | Agent only |
| `promoter` | Promoter only |
| `both` | Both agent and promoter |

### Global Rules (apply to all tiers)
| Field | Purpose | Example |
|-------|---------|---------|
| `metric_base` | Which metric to use: PCA, PP, or PNA | `PCA` |
| `frequency` | Payment frequency: única, mensual, trimestral, anual | `trimestral` |
| `requires_product` | Array of `{product_type, min_count}` objects. Each product has its own minimum count. Valid types: `"Vida"` (alias for METALIFE + PERFECTLIFE), `"PRIMORDIAL"`. Max 2 entries. | `[{"product_type":"PRIMORDIAL","min_count":3}]` |
| `min_product_count` | Minimum policies of required type | `3` |
| `requires_mix` | Must sell multiple product types | `true` |
| `dependency_scheme_id` | Prerequisite bonus's `template_key` | `"agent_first_year_production"` |
| `min_irp` | Minimum IRP % globally required | `91.0` |
| `min_collection_efficiency` | Minimum collection efficiency % | `85.0` |
| `quarterly_recruits` | Recruitment quotas per quarter | `{"q1":3,"q2":4,"q3":5,"q4":5}` |
| `pna_equivalences` | PNA weighting table | (See schema doc) |

---

## Version & Tier Structure

```
Scheme (e.g., "Bono de Producción Q1 2026")
  └── SchemeVersion (e.g., "v1 — Q1 2026", starts_at: 2026-01-01, ends_at: 2026-03-31)
       ├── SchemeTier (conditions: {"min_pca": 25000}, agent_percentage: 5.0)
       ├── SchemeTier (conditions: {"min_pca": 50000}, agent_percentage: 7.5)
       └── SchemeTier (conditions: {"min_pca": 100000}, agent_percentage: 10.0)
```

When evaluating, the system finds the active version (where `starts_at ≤ periodEnd ≤ ends_at` or `ends_at` is null), then checks tiers in descending order of percentage (best payout first).

### Source: `app/Models/Scheme.php` (FULL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchemeVersion;
use App\Models\SchemeTier;

class Scheme extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'template_key',
        'target',
        'is_active',
        'metric_base',
        'frequency',
        'requires_anticipos',
        'anticipos_config',
        'applies_annual_adjustment',
        'requires_product',
        'min_product_count',
        'requires_mix',
        'dependency_scheme_id',
        'min_irp',
        'min_collection_efficiency',
        'quarterly_recruits',
        'pna_equivalences',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'requires_anticipos' => 'boolean',
        'applies_annual_adjustment' => 'boolean',
        'requires_mix' => 'boolean',
        'anticipos_config' => 'array',
        'requires_product' => 'array',
        'quarterly_recruits' => 'array',
        'pna_equivalences' => 'array',
        'dependency_scheme_id' => 'string',
    ];

    public function versions()
    {
        return $this->hasMany(SchemeVersion::class);
    }

    public function tiers()
    {
        return $this->hasManyThrough(SchemeTier::class, SchemeVersion::class);
    }
}
```

### Source: `app/Models/SchemeVersion.php` (FULL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchemeVersion extends Model
{
    protected $fillable = ['scheme_id', 'version_name', 'starts_at', 'ends_at'];

    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }

    public function tiers()
    {
        return $this->hasMany(SchemeTier::class, 'scheme_version_id');
    }
}
```

### Source: `app/Models/SchemeTier.php` (FULL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SchemeVersion;

class SchemeTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheme_version_id',
        'conditions',
        'agent_percentage',
        'agent_automatic_percentage',
        'promoter_percentage',
        'fixed_amount',
    ];

    protected $casts = [
        'conditions' => 'array',
    ];

    public function version()
    {
        return $this->belongsTo(SchemeVersion::class, 'scheme_version_id');
    }
}
```

---

## Activation Logic & Full Controller Source

### Routes Source (from `routes/web.php`)

```php
Route::resource('schemes', SchemeController::class);

Route::prefix('esquemas')->name('esquemas.')->group(function () {
    Route::get('/', [SchemeController::class, 'index'])->name('index');
    Route::get('/comisiones/crear', [SchemeController::class, 'createCommission'])->name('comisiones.crear');
    Route::get('/comisiones/{scheme}/editar', [SchemeController::class, 'editCommission'])->name('comisiones.editar');
    Route::get('/comisiones/{scheme}', [SchemeController::class, 'show'])->name('comisiones.show');
    Route::get('/bonos', [SchemeController::class, 'bonuses'])->name('bonos');
    Route::get('/bonos/crear', [SchemeController::class, 'createBonus'])->name('bonos.crear');
    Route::get('/bonos/{scheme}/editar', [SchemeController::class, 'editBonus'])->name('bonos.editar');
    Route::get('/bonos/{scheme}', [SchemeController::class, 'showBonus'])->name('bonos.show');
});
```

### Source: `app/Http/Controllers/SchemeController.php` (FULL)

The activation logic for commissions and bonuses is embedded in the `store()` and `update()` methods. Key points:

- **Commission activation:** `Scheme::where('type', 'commission')->where('is_active', true)->update(['is_active' => false])` — deactivates ALL other commissions.
- **Bonus activation:** `Scheme::where('type', 'bonus')->where('name', $name)->where('is_active', true)->update(['is_active' => false])` — deactivates bonuses with the same name.
- **Update:** The `where('id', '!=', $scheme->id)` clause ensures the current scheme is not deactivated when updating itself.
- **Tiers sync on update:** Old tiers are deleted and recreated (`$version->tiers()->delete()` then create new ones).

```php
<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SchemeController extends Controller
{
    public function index()
    {
        $schemes = Scheme::with(['tiers', 'versions'])
            ->where('type', 'commission')
            ->orderBy('id', 'desc')
            ->get();
        return Inertia::render('Scheme/Index', ['schemes' => $schemes]);
    }

    public function createCommission()
    {
         return Inertia::render('Scheme/Commissions/Create');
    }

    public function editCommission(Scheme $scheme)
    {
         $scheme->load(['versions.tiers']);
         return Inertia::render('Scheme/Commissions/Edit', ['scheme' => $scheme]);
    }

    public function bonuses()
    {
        $schemes = Scheme::with(['tiers', 'versions'])
            ->where('type', 'bonus')
            ->orderBy('id', 'desc')
            ->get();
        return Inertia::render('Scheme/Bonuses/Index', ['schemes' => $schemes]);
    }

    public function createBonus()
    {
        return Inertia::render('Scheme/Bonuses/Create');
    }

    public function editBonus(Scheme $scheme)
    {
         $scheme->load(['versions.tiers']);
         return Inertia::render('Scheme/Bonuses/Edit', ['scheme' => $scheme]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'template_key' => 'nullable|string|max:255',
            'target' => 'required|string|in:promoter,agent,both',
            'is_active' => 'boolean',
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

        $isActive = $validated['is_active'] ?? true;

        // Commission activation: deactivate ALL other commissions
        if ($validated['type'] === 'commission' && $isActive) {
            Scheme::where('type', 'commission')->where('is_active', true)->update(['is_active' => false]);
        }

        // Bonus activation: deactivate other bonuses with the same name
        if ($validated['type'] === 'bonus' && $isActive) {
            Scheme::where('type', 'bonus')
                ->where('name', $validated['name'])
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $scheme = Scheme::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'template_key' => $validated['template_key'] ?? null,
            'target' => $validated['target'],
            'is_active' => $isActive,
            'metric_base' => $validated['metric_base'],
            'frequency' => $validated['frequency'],
            'requires_anticipos' => $validated['requires_anticipos'] ?? false,
            'anticipos_config' => $validated['anticipos_config'] ?? null,
            'applies_annual_adjustment' => $validated['applies_annual_adjustment'] ?? false,
            'requires_product' => $validated['requires_product'] ?? [],
            'min_product_count' => $validated['min_product_count'] ?? 0,
            'requires_mix' => $validated['requires_mix'] ?? false,
            'dependency_scheme_id' => $validated['dependency_scheme_id'] ?? null,
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
            ? redirect()->route('esquemas.bonos')->with('success', 'Bono creado correctamente.')
            : redirect()->route('esquemas.index')->with('success', 'Comisión creada correctamente.');
    }

    public function show(Scheme $scheme)
    {
        $scheme->load(['versions.tiers']);
        return Inertia::render('Scheme/Commissions/Show', ['scheme' => $scheme]);
    }

    public function showBonus(Scheme $scheme)
    {
        $scheme->load(['versions.tiers']);
        return Inertia::render('Scheme/Bonuses/Show', ['scheme' => $scheme]);
    }

    public function update(Request $request, Scheme $scheme)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'is_active' => 'boolean',
            'template_key' => 'nullable|string|max:255',
            'target' => 'required|string|in:promoter,agent,both',
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

        $isActive = $validated['is_active'] ?? $scheme->is_active;
        if ($scheme->type === 'commission' && $isActive) {
            Scheme::where('type', 'commission')
                ->where('is_active', true)
                ->where('id', '!=', $scheme->id)
                ->update(['is_active' => false]);
        }
        if ($scheme->type === 'bonus' && $isActive) {
            Scheme::where('type', 'bonus')
                ->where('name', $validated['name'])
                ->where('is_active', true)
                ->where('id', '!=', $scheme->id)
                ->update(['is_active' => false]);
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
            'dependency_scheme_id' => $validated['dependency_scheme_id'] ?? null,
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

        return to_route($scheme->type === 'bonus' ? 'esquemas.bonos' : 'esquemas.index')
            ->with('success', 'Esquema actualizado exitosamente.');
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
```

---

## Dependencies
- **Bonus Engine** — Bonus calculators read schemes to determine payout rules
- **Policy Management** — Commission percentages from schemes are used in policy forms
- **Dashboard** — Reads schemes to list active bonuses

## Known Limitations / Tech Debt
1. **No bulk scheme operations:** No way to clone, template, or batch-update schemes.
2. **Complex JSON validation:** The store/update methods validate JSON arrays as `nullable|array` without deep validation of JSON structure. Malformed `conditions`, `pna_equivalences`, or `quarterly_recruits` could pass validation but break calculator logic.
3. **No scheme simulation:** Cannot preview "what would happen if" — schemes must be saved and activated to see effects.
4. **Version overlap:** No validation prevents overlapping effective dates between versions of the same scheme.
5. **`dependency_scheme_id` stored as string:** References `template_key`, not an actual foreign key. No referential integrity — if a dependency scheme is renamed or deleted, the dependency silently breaks.
6. **Commission type doesn't use template_key:** Commissions don't have a `template_key` (NULL). They're resolved by the simple product-type matching in `getProductCommissionMap`, not through the Strategy pattern.
7. **`min_product_count` column is deprecated** for bonuses — `requires_product` now uses `[{product_type, min_count}]` format (per 2026-07-15 migration). The column remains in the schema for backward compatibility with any legacy code still reading it.
8. **Vue bonus forms not yet updated** — the Create/Edit bonus forms still use the old multi-select + single number UI. They need to be updated to the repeatable-row UI for the new `requires_product` format.
