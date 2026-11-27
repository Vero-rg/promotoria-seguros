# 05 — Module: Policy Management

## Business Purpose
Full CRUD for insurance policies (pólizas). Each policy represents a sale made by an agent. The system tracks premium amounts, commission calculations for both agent and promoter, tax/factoring deductions, product type, and a status workflow.

## Key Files

| File | Role |
|------|------|
| `app/Models/Policy.php` | Policy model, status constants, relationships, net amount calculations |
| `app/Http/Controllers/PolicyController.php` | Full CRUD + status update endpoint + commission map resolver |
| `app/Http/Requests/StorePolicyRequest.php` | Validation rules for creating policies (Spanish messages) |
| `app/Http/Requests/UpdatePolicyRequest.php` | Validation rules for updating policies (Spanish messages, unique exclusion) |
| `app/Http/Requests/ImportPoliciesRequest.php` | Validation for the Excel/CSV import file |
| `app/Services/PolicyImportService.php` | Parses the spreadsheet, validates each row and creates the policies |
| `app/Services/ProductCommissionService.php` | Resolves product → commission % from the active commission schemes |
| `app/Http/Controllers/PolicyImportController.php` | Import endpoint + Excel template download |
| `resources/js/Pages/Policies/Partials/PolicyForm.vue` | Shared form component — client-side commission auto-calc + financial summary preview |
| `resources/js/Pages/Policies/Index.vue` | Policy list view |
| `resources/js/Pages/Policies/Create.vue` | Create policy form (wraps `PolicyForm.vue`) |
| `resources/js/Pages/Policies/Edit.vue` | Edit policy form (wraps `PolicyForm.vue`) |
| `resources/js/Pages/Policies/Show.vue` | Single policy detail view |
| `tests/Feature/PolicyNetAmountTest.php` | Tests for `agentNetAmount()`, `promoterNetAmount()`, `netAmount()` (sequential ISR→billing) |
| `routes/web.php` | Routes: `/policies` resource + status update |

---

## Routes

| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/policies` | `PolicyController@index` | `policies.index` |
| GET | `/policies/create` | `PolicyController@create` | `policies.create` |
| POST | `/policies` | `PolicyController@store` | `policies.store` |
| GET | `/policies/{policy}` | `PolicyController@show` | `policies.show` |
| GET | `/policies/{policy}/edit` | `PolicyController@edit` | `policies.edit` |
| PUT/PATCH | `/policies/{policy}` | `PolicyController@update` | `policies.update` |
| DELETE | `/policies/{policy}` | `PolicyController@destroy` | `policies.destroy` |
| PATCH | `/policies/{policy}/status` | `PolicyController@updateStatus` | `policies.status` |
| GET | `/policies/import/template` | `PolicyImportController@template` | `policies.import.template` |
| POST | `/policies/import` | `PolicyImportController@import` | `policies.import` |

### Source: Routes (from `routes/web.php`)

```php
Route::get('policies/import/template', [PolicyImportController::class, 'template'])->name('policies.import.template');
Route::post('policies/import', [PolicyImportController::class, 'import'])->name('policies.import');
Route::resource('policies', PolicyController::class);
Route::patch('policies/{policy}/status', [PolicyController::class, 'updateStatus'])->name('policies.status');
```

---

## ⚠️ CRITICAL: Exact Commission / ISR / Cedular / Billing Formulas

This is the ground truth for all money calculations in the system. These formulas are implemented in `Policy.php` and used by Dashboard, Promoter detail, and anywhere net amounts are computed.

### PNA and PCA

- **PNA** (`premium_amount`) — gross premium paid by the client.
- **PCA** (`pcaAmount()`) — `max(0, PNA − 1_350)`. The fixed `Policy::PCA_DEDUCTION` ($1,350) is subtracted per policy.
- Agent and promoter commissions are calculated **on the PCA**, not on the PNA: `commission_amount = PCA × commission_percentage`.

### Agent Net Commission (`agentNetAmount()`)

```
Step 1: ISR deduction = commission_amount × (isr_retention / 100)
Step 2: After-ISR amount = commission_amount - ISR deduction
Step 3: Cedular deduction = After-ISR amount × (cedular_tax / 100)
Step 4: After-cedular amount = After-ISR amount - Cedular deduction
Step 5: Billing deduction = After-cedular amount × (billing_retention / 100)
Step 6: Net = After-cedular amount - Billing deduction
```

Deductions are applied **sequentially**: ISR first, then impuesto cedular on the amount after ISR, then billing on the amount after cedular. Percentages use the DB-stored values from `isr_retention`, `cedular_tax` and `billing_retention`. The calculation ONLY runs if `status === 'Pagada'`. Otherwise returns `0.0`.

**Example:** `commission_amount = 50,040`, ISR = 10%, cedular = 5%, billing = 5%
- ISR = 50,040 × 0.10 = 5,004.00
- After ISR = 50,040 − 5,004 = 45,036.00
- Cedular = 45,036 × 0.05 = 2,251.80
- After cedular = 45,036 − 2,251.80 = 42,784.20
- Billing = 42,784.20 × 0.05 = 2,139.21
- Net = 42,784.20 − 2,139.21 = **40,644.99**

### Promoter Net Commission (`promoterNetAmount()`)

```
Step 1: ISR deduction = promoter_commission_amount × (isr_retention / 100)
Step 2: After-ISR amount = promoter_commission_amount - ISR deduction
Step 3: Cedular deduction = After-ISR amount × (cedular_tax / 100)
Step 4: After-cedular amount = After-ISR amount - Cedular deduction
Step 5: Billing deduction = After-cedular amount × (billing_retention / 100)
Step 6: Net = After-cedular amount - Billing deduction
```

Same sequential structure but uses `promoter_commission_amount` as the base.

### Dashboard Net Income

```
Net Income = PNA − (agent net commissions + promoter net commissions)
```

`CommissionBreakdownService` resolves each `Pagada` policy's gross commission (`PCA × percentage`, falling back to the active commission scheme when the policy has no percentage) and applies ISR → impuesto cedular → facturación sequentially. `Policy::netAmount()` still returns agent + promoter net commission for a single policy, but the Dashboard KPI subtracts that total from the period PNA.

---

---

## Policy Model — Key Fields

### Financial Fields
- `premium_amount` — PNA: gross premium paid by client
- `commission_percentage` — Agent commission % (from scheme config), applied to the PCA
- `commission_amount` — Agent commission in MXN
- `promoter_commission_percentage` — Promoter override %, applied to the PCA
- `promoter_commission_amount` — Promoter override in MXN
- `isr_retention` — ISR tax retention % (required on create/edit)
- `cedular_tax` — Impuesto cedular % (optional; defaults to 0, or 5 when the selected agent has `cedular_tax_enabled`)
- `billing_retention` — Factoring/billing cost % (no default; required when creating/editing)

### Identification
- `policy_number` — Unique external insurance policy number (digits only)
- `client_name` — Name of the insured person (optional)
- `issue_date` — Date the policy was issued
- `product_type` — One of: `METALIFE`, `PERFECTLIFE`, `PRIMORDIAL`
- `payment_method` — Payment channel: `Efectivo` or `Automático` (required on create/edit; the create form preselects `Automático`; the column is NOT NULL and defaults to `Automático`; legacy rows were backfilled to `Automático`; imports default to `Efectivo` when the cell is empty)
- `agent_id` — Foreign key to the selling agent

### Status Field
Three possible values (constants defined on model):
- `STATUS_ACTIVA` = `'Activa'` — Active policy
- `STATUS_NO_TOMADA` = `'No tomada'` — Policy rejected/not taken by client
- `STATUS_PAGADA` = `'Pagada'` — Policy fully paid

Status colors for UI:
- Activa → green
- No tomada → gray
- Pagada → blue

---

## Key Methods & Full Source

### Source: `app/Models/Policy.php` (FULL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;

    public const STATUS_ACTIVA = 'Activa';
    public const STATUS_NO_TOMADA = 'No tomada';
    public const STATUS_PAGADA = 'Pagada';

    public const STATUSES = [
        self::STATUS_ACTIVA,
        self::STATUS_NO_TOMADA,
        self::STATUS_PAGADA,
    ];

    public const STATUS_COLORS = [
        self::STATUS_ACTIVA => 'green',
        self::STATUS_NO_TOMADA => 'gray',
        self::STATUS_PAGADA => 'blue',
    ];

    /** Deducción fija sobre el PNA para obtener el PCA (por póliza). */
    public const PCA_DEDUCTION = 1350.00;

    protected $fillable = [
        'agent_id',
        'policy_number',
        'client_name',
        'issue_date',
        'premium_amount',
        'commission_percentage',
        'commission_amount',
        'promoter_commission_percentage',
        'promoter_commission_amount',
        'isr_retention',
        'cedular_tax',
        'billing_retention',
        'status',
        'product_type',
        'payment_method',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'premium_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'promoter_commission_amount' => 'decimal:2',
        'isr_retention' => 'decimal:2',
        'cedular_tax' => 'decimal:2',
        'billing_retention' => 'decimal:2',
    ];

    // ─── Relaciones ─────────────────────────────────

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    // ─── Métodos de Estatus ─────────────────────────

    public function changeStatus(string $newStatus): self
    {
        if (!in_array($newStatus, self::STATUSES)) {
            throw new \InvalidArgumentException("Estatus inválido: {$newStatus}");
        }
        $this->update(['status' => $newStatus]);
        return $this;
    }

    public function activate(): self
    {
        return $this->changeStatus(self::STATUS_ACTIVA);
    }

    public function markAsNoTomada(): self
    {
        return $this->changeStatus(self::STATUS_NO_TOMADA);
    }

    public function markAsPaid(): self
    {
        return $this->changeStatus(self::STATUS_PAGADA);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVA;
    }

    /**
     * Prima Computable Acumulada: PNA menos la deducción fija por póliza.
     * Es la base para calcular comisiones y bonos.
     */
    public function pcaAmount(): float
    {
        return round(max(0, (float) $this->premium_amount - self::PCA_DEDUCTION), 2);
    }

    /**
     * Calcula el monto neto de la comisión del agente después de
     * deducciones por ISR, impuesto cedular y costo de facturación,
     * aplicadas secuencialmente sobre la comisión.
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function agentNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return $this->netCommissionAmount((float) $this->commission_amount);
    }

    /**
     * Calcula el monto neto de la comisión del promotor después de
     * deducciones por ISR, impuesto cedular y costo de facturación,
     * aplicadas secuencialmente sobre la comisión.
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function promoterNetAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return $this->netCommissionAmount((float) $this->promoter_commission_amount);
    }

    /**
     * Calcula el monto neto total de la póliza: suma de las comisiones
     * netas del agente y del promotor después de deducciones.
     *
     * Solo aplica si la póliza está en estatus Pagada; de lo contrario retorna 0.
     */
    public function netAmount(): float
    {
        if ($this->status !== self::STATUS_PAGADA) {
            return 0.0;
        }

        return round($this->agentNetAmount() + $this->promoterNetAmount(), 2);
    }

    /**
     * Deducciones secuenciales sobre una comisión: ISR → impuesto cedular → facturación.
     */
    private function netCommissionAmount(float $commissionAmount): float
    {
        $isrPct      = (float) ($this->isr_retention ?? 0);
        $cedularPct  = (float) ($this->cedular_tax ?? 0);
        $billingPct  = (float) ($this->billing_retention ?? 0);

        $afterIsr     = $commissionAmount - ($commissionAmount * ($isrPct / 100));
        $afterCedular = $afterIsr - ($afterIsr * ($cedularPct / 100));
        $afterBilling = $afterCedular - ($afterCedular * ($billingPct / 100));

        return round($afterBilling, 2);
    }
}
```

---

## Product Commission Mapping (`getProductCommissionMap`)

This method, defined in `PolicyController`, resolves the current commission percentages for each product type by reading the **active commission scheme**.

### Logic:
1. Load all active schemes where `type = 'commission'` and `is_active = true`.
2. For each scheme, get the latest version (by `starts_at` desc).
3. For each tier in that version, extract `product_type` from conditions.
4. Build a map: `{ product_type → { agent_percentage, promoter_percentage } }`.
5. First encounter wins (if multiple schemes define the same product).

### Result Example:
```php
[
    'METALIFE'    => ['agent_percentage' => 35.0, 'promoter_percentage' => 5.0],
    'PERFECTLIFE' => ['agent_percentage' => 30.0, 'promoter_percentage' => 5.0],
    'PRIMORDIAL'  => ['agent_percentage' => 20.0, 'promoter_percentage' => 3.0],
]
```

This map is passed to the frontend for the create/edit forms so the UI can auto-fill commission values based on selected product type.

### Source: `app/Http/Controllers/PolicyController.php` (FULL — refactored with Form Requests)

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequest;
use App\Http\Requests\UpdatePolicyRequest;
use App\Models\Policy;
use App\Models\Agent;
use App\Models\Scheme;
use App\Models\Promoter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PolicyController extends Controller
{
    public function getProductCommissionMap(): array
    {
        $schemes = Scheme::with(['versions.tiers'])
            ->where('type', 'commission')
            ->where('is_active', true)
            ->get();

        $map = [];

        foreach ($schemes as $scheme) {
            $latestVersion = $scheme->versions->sortByDesc('starts_at')->first();
            if (!$latestVersion || !$latestVersion->tiers) continue;

            foreach ($latestVersion->tiers as $tier) {
                $conditions = $tier->conditions ?? [];
                $productType = $conditions['product_type'] ?? null;
                if (!$productType) continue;

                if (!isset($map[$productType])) {
                    $map[$productType] = [
                        'agent_percentage' => (float) ($tier->agent_percentage ?? 0),
                        'promoter_percentage' => (float) ($tier->promoter_percentage ?? 0),
                    ];
                }
            }
        }

        return $map;
    }

    public function index()
    {
        $policies = Policy::with('agent.promoter')->latest()->get();
        return Inertia::render('Policies/Index', [
            'policies' => $policies,
            'promoters' => Promoter::orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Policies/Create', [
            'agents' => Agent::where('is_active', true)->orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function store(StorePolicyRequest $request)
    {
        $validated = $request->validated();
        Policy::create($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza creada exitosamente.');
    }

    public function updateStatus(Request $request, Policy $policy)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', Policy::STATUSES),
        ], [
            'status.in' => 'El estatus seleccionado no es válido.',
        ]);

        $policy->changeStatus($validated['status']);
        return back()->with('success', "Estatus cambiado a \"{$validated['status']}\".");
    }

    public function show(Policy $policy)
    {
        $policy->load('agent.promoter');
        return Inertia::render('Policies/Show', ['policy' => $policy]);
    }

    public function edit(Policy $policy)
    {
        return Inertia::render('Policies/Edit', [
            'policy' => $policy,
            'agents' => Agent::where('is_active', true)->orderBy('name')->get(),
            'productCommissionMap' => $this->getProductCommissionMap(),
        ]);
    }

    public function update(UpdatePolicyRequest $request, Policy $policy)
    {
        $validated = $request->validated();
        $policy->update($validated);
        return redirect()->route('policies.index')->with('success', 'Póliza actualizada exitosamente.');
    }

    public function destroy(Policy $policy)
    {
        $policy->delete();
        return redirect()->route('policies.index')->with('success', 'Póliza eliminada.');
    }
}
```

### Source: `app/Http/Requests/StorePolicyRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_id' => 'required|exists:agents,id',
            'policy_number' => 'required|string|unique:policies',
            'client_name' => 'nullable|string|max:255',
            'issue_date' => 'required|date',
            'premium_amount' => 'required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'promoter_commission_percentage' => 'nullable|numeric',
            'promoter_commission_amount' => 'nullable|numeric',
            'isr_retention' => 'required|numeric|min:0|max:100',
            'cedular_tax' => 'nullable|numeric|min:0|max:100',
            'billing_retention' => 'required|numeric|min:0|max:100',
            'status' => 'nullable|string',
            'product_type' => 'nullable|string',
            'payment_method' => ['required', Rule::in(Policy::PAYMENT_METHODS)],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'exists' => 'El :attribute seleccionado es inválido.',
            'unique' => 'El :attribute ya está registrado.',
        ];
    }

    public function attributes(): array
    {
        return [
            'agent_id' => 'agente',
            'policy_number' => 'número de póliza',
            'issue_date' => 'fecha de emisión',
            'premium_amount' => 'PNA',
            'commission_percentage' => 'porcentaje de comisión (agente)',
            'commission_amount' => 'monto de comisión (agente)',
            'promoter_commission_percentage' => 'porcentaje de comisión (promotor)',
            'promoter_commission_amount' => 'monto de comisión (promotor)',
            'isr_retention' => 'retención de ISR',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
            'product_type' => 'tipo de producto',
        ];
    }
}
```

### Source: `app/Http/Requests/UpdatePolicyRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $policyId = $this->route('policy')->id;

        return [
            'agent_id' => 'sometimes|required|exists:agents,id',
            'policy_number' => 'sometimes|required|string|unique:policies,policy_number,' . $policyId,
            'client_name' => 'nullable|string|max:255',
            'issue_date' => 'sometimes|required|date',
            'premium_amount' => 'sometimes|required|numeric',
            'commission_percentage' => 'nullable|numeric',
            'commission_amount' => 'nullable|numeric',
            'promoter_commission_percentage' => 'nullable|numeric',
            'promoter_commission_amount' => 'nullable|numeric',
            'isr_retention' => 'required|numeric|min:0|max:100',
            'cedular_tax' => 'nullable|numeric|min:0|max:100',
            'billing_retention' => 'required|numeric|min:0|max:100',
            'status' => 'sometimes|string',
            'product_type' => 'nullable|string',
            'payment_method' => ['required', Rule::in(Policy::PAYMENT_METHODS)],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'date' => 'El campo :attribute debe ser una fecha válida.',
            'exists' => 'El :attribute seleccionado es inválido.',
            'unique' => 'El :attribute ya está registrado.',
        ];
    }

    public function attributes(): array
    {
        return [
            'agent_id' => 'agente',
            'policy_number' => 'número de póliza',
            'issue_date' => 'fecha de emisión',
            'premium_amount' => 'PNA',
            'commission_percentage' => 'porcentaje de comisión (agente)',
            'commission_amount' => 'monto de comisión (agente)',
            'promoter_commission_percentage' => 'porcentaje de comisión (promotor)',
            'promoter_commission_amount' => 'monto de comisión (promotor)',
            'isr_retention' => 'retención de ISR',
            'cedular_tax' => 'impuesto cedular',
            'billing_retention' => 'costo de facturación',
            'status' => 'estatus',
            'product_type' => 'tipo de producto',
            'payment_method' => 'conducto de pago',
        ];
    }
}
```

> **Frontend/backend duplication note:** Commission amounts (`commission_amount`, `promoter_commission_amount`) are calculated **client-side** in the Vue Create/Edit forms as `PCA × percentage / 100` (PCA = PNA − $1,350). The backend Controller does NOT independently recompute them — it simply saves whatever validated values the frontend submitted (validation is now handled by `StorePolicyRequest` / `UpdatePolicyRequest` Form Request classes). There is no model observer or `prepareForValidation` hook for recomputation. The frontend is the sole source of truth for what gets persisted.

---

## Create/Edit Form Behavior

The create form:
1. Shows a dropdown of active agents (`is_active = true`).
2. The PNA field shows the computed PCA (`PNA − $1,350`) in a green box below it.
3. When the user selects a product type, the form auto-populates `commission_percentage` and `promoter_commission_percentage` from `productCommissionMap`.
4. `commission_amount` is calculated client-side as `PCA × commission_percentage / 100`.
5. ISR defaults to 10% and is **required**; impuesto cedular starts at 0 (optional, blank is stored as 0) unless the selected agent has `cedular_tax_enabled`, in which case it is prefilled with 5; costo de facturación starts empty and is **required**.
6. `policy_number` only accepts digits and both `policy_number` and `client_name` disable browser autofill (no card/address suggestions).

The financial summary preview in `PolicyForm.vue` (`summary` computed property) applies ISR, then impuesto cedular, then billing sequentially — matching the backend `agentNetAmount()` and `promoterNetAmount()` methods exactly.

> **Frontend/backend duplication note:** Commission amounts (`commission_amount`, `promoter_commission_amount`) are calculated **client-side** in the Vue Create/Edit forms as `PCA × percentage / 100`. The backend Controller does NOT independently recompute them — it simply saves whatever values the frontend submitted. There is no model observer, Form Request `prepareForValidation` hook, or backend recalculation. The frontend is the sole source of truth for what gets persisted.

---

## Bulk Import from Excel (`PolicyImportController`)

The policies list (`Policies/Index.vue`) has an **Importar pólizas** button that opens a dialog where the user can download the template and upload an `.xlsx`, `.xls` or `.csv` file (max 10 MB).

### Template columns

| # | Column | Required | Notes |
|---|--------|----------|-------|
| 1 | `Número de póliza` | Yes | Digits only, unique. Stored as text in the template so Excel keeps all digits. |
| 2 | `Cliente` | No | Free text. |
| 3 | `Producto` | Yes | **Dropdown list** (`C2:C5000`) fed from the products of the active commission schemes (listed in the `Catálogo` sheet). |
| 4 | `Agente` | Yes | **Dropdown list** (`D2:D5000`) fed from the active agents listed in the `Catálogo` sheet. |
| 5 | `Fecha de emisión` | Yes | `dd/mm/aaaa`, `aaaa-mm-dd` or an Excel date cell. |
| 6 | `PNA` | Yes | Numeric. |
| 7 | `% Impuesto cedular` | No | Blank is stored as `0`, or `5` when the agent has `cedular_tax_enabled`. |
| 8 | `% Costo facturación` | Yes | Numeric percentage. |
| 9 | `Estatus` | No | **Dropdown list** (`I2:I5000`): `Activa`, `No tomada` or `Pagada`. Blank defaults to `Activa`. |
| 10 | `Conducto de pago` | No | **Dropdown list** (`J2:J5000`): `Efectivo` or `Automático`. Blank defaults to `Efectivo`. |

Headers are matched by normalized text, so aliases such as `No. Póliza`, `prima` or `cedular` also work, and column order is irrelevant.

The retention ISR is **not captured in the template**: every imported policy is stored with `isr_retention = 10`. Legacy files that still carry a `% Retención ISR` column keep importing — the column is simply ignored.

### Behavior
- The template contains **only the header row** — no example rows are included, so users start typing at row 2.
- The file is read with **PhpSpreadsheet** (`phpoffice/phpspreadsheet`), always from the **first sheet**.
- The template applies **data validation dropdowns** to the Producto, Agente, Estatus and Conducto de pago columns (rows 2–5000), with a Spanish input message and a blocking error message. Agent/product lists come from the `Catálogo` sheet ranges; status and payment lists are inline (`"Activa,No tomada,Pagada"`, `"Efectivo,Automático"`). Note that PhpSpreadsheet inverts the OOXML `showDropDown` attribute, so the code calls `setShowDropDown(true)` to make the arrow visible.
- Agent and product commissions are resolved from the active commission scheme, and the amounts are computed on the **PCA** (`PNA − Policy::PCA_DEDUCTION`), exactly like the create/edit form.
- The ISR retention is always `10`: `PolicyImportService::DEFAULT_ISR_RETENTION`.
- Rows are validated individually: valid rows are created, invalid rows are skipped and reported as `Fila N: <motivo>` (unknown agent/product, duplicated or missing policy number, invalid date, missing PNA or billing cost).
- The result is flashed as `import_result` (`{ imported, errors[] }`) and shown inside the dialog. Flash messages are shared to Inertia via `handleInertiaRequests`.

---

## Status Update Endpoint

`PATCH /policies/{policy}/status` accepts only the `status` field. Validates it against `Policy::STATUSES`. Calls `$policy->changeStatus()`.

This is a separate endpoint from the full `update` to allow quick status toggles from the list view.

---

## Dependencies
- **Agent Management** — Each policy belongs to an agent
- **Commission Schemes** — Active commission scheme determines percentages
- **Dashboard** — Dashboard reads policies for all KPI calculations
- **Bonus Engine** — Bonus calculators read policies for PCA, PP, policy counts

## Known Limitations / Tech Debt
1. **No policy edit history / audit trail:** Changes to financial fields are not logged.
2. **No bulk status change:** Each policy must be updated individually.
3. **Frontend/backend commission duplication:** `commission_amount` and `promoter_commission_amount` are computed client-side in Vue and saved as-is by the backend. Validation is handled by dedicated Form Request classes (`StorePolicyRequest`, `UpdatePolicyRequest`), but no independent backend recomputation occurs. A model observer or `prepareForValidation` hook would be more robust.
4. **Commission auto-calculation only on create/edit:** If the active commission scheme changes after policies exist, existing policies retain old commission values. There is no bulk recalculation mechanism.
5. **Product types hardcoded:** `METALIFE`, `PERFECTLIFE`, `PRIMORDIAL` appear as string literals in the controller, dashboard, and calculators. An `enum` or database table would be more maintainable.
6. **No policy cancellation/refund workflow:** Only three statuses. There's no concept of partial payments, renewals, or cancellations.
