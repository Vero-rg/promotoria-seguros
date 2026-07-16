# 04 — Module: Directory (Promoters + Agents)

## Business Purpose
Unified directory managing the entire sales force hierarchy: Promoters (team leaders) and Agents (salespeople). Provides CRUD operations, search, filtering, photo management, activation/deactivation, and detailed individual views with performance statistics.

## Key Files

| File | Role |
|------|------|
| `app/Models/Promoter.php` | Promoter model + relationship to agents |
| `app/Models/Agent.php` | Agent model + relationships + `activeInPeriod` scope |
| `app/Http/Controllers/PromoterController.php` | Directory index, promoter CRUD, promoter detail view |
| `app/Http/Controllers/AgentController.php` | Agent CRUD (redirect index/create to directory), agent detail |
| `resources/js/Pages/Directory/Index.vue` | Main directory list view |
| `resources/js/Pages/Directory/Create.vue` | Create promoter or agent form |
| `resources/js/Pages/Directory/Edit.vue` | Edit promoter or agent form |
| `resources/js/Pages/Directory/Show.vue` | Individual detail view for promoter or agent |
| `routes/web.php` | Routes: `/directorio`, `/promoters`, `/agents` |

---

## Routes

### Directory (unified view)
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/directorio` | `PromoterController@index` | `directorio` |

### Promoters (resource)
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/promoters` | `PromoterController@index` | `promoters.index` |
| GET | `/promoters/create` | `PromoterController@create` | `promoters.create` |
| POST | `/promoters` | `PromoterController@store` | `promoters.store` |
| GET | `/promoters/{promoter}` | `PromoterController@show` | `promoters.show` |
| GET | `/promoters/{promoter}/edit` | `PromoterController@edit` | `promoters.edit` |
| PUT/PATCH | `/promoters/{promoter}` | `PromoterController@update` | `promoters.update` |
| DELETE | `/promoters/{promoter}` | `PromoterController@destroy` | `promoters.destroy` |

### Agents (resource)
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/agents` | `AgentController@index` → redirects to `directorio` | |
| GET | `/agents/create` | `AgentController@create` → redirects to `promoters.create` | |
| POST | `/agents` | `AgentController@store` | `agents.store` |
| GET | `/agents/{agent}` | `AgentController@show` | `agents.show` |
| GET | `/agents/{agent}/edit` | `AgentController@edit` | `agents.edit` |
| PUT/PATCH | `/agents/{agent}` | `AgentController@update` | `agents.update` |
| DELETE | `/agents/{agent}` | `AgentController@destroy` | `agents.destroy` |

---

## Directory Index (Unified Search)

The `GET /directorio` route returns a merged list of promoters and agents, sorted by `created_at` descending. Each item is tagged with `type` (`promoter` or `agent`) and a `display_id` (`P{id}` or `A{id}`).

### Search & Filter Logic
- **Search by name:** Case-insensitive LIKE match on `name`.
- **Search by ID prefix:** `P123` searches for promoter ID 123, `A456` searches for agent ID 456.
- **Search by numeric:** Plain numbers match against `id` for both types.
- **Filter by type:** `?type=promoter` or `?type=agent` limits results.
- **Filter by date:** `?date=YYYY-MM-DD` matches `created_at`.
- **Filter by status:** `?status=active` or `?status=inactive` matches `is_active`.

---

## Promoter Detail View (`PromoterController@show`)

When viewing a promoter, the system shows:

### Stats Cards
1. **Active Agents** — Count of agents with `is_active = true` under this promoter.
2. **New Recruitments** — Agents created under this promoter within the date range.
3. **Team Sales Volume** — Sum of `premium_amount` for all 'Pagada' policies by all agents.
4. **Bonuses Achieved** — Count of bonus schemes unlocked by this promoter (via `BonusOrchestratorService`).

### Agent Table (Operational Directory)
For each agent under the promoter:
- Agent name and photo
- Product required (from active bonus schemes' `requires_product`)
- Commission generated (calculated from active commission scheme)
- Bonus progress bars (via `BonusOrchestratorService::toFrontendFormat()`)

### Commission Breakdown (Promoter view)

Calculated per-agent, per-policy from the active commission scheme with full ISR/billing breakdown using the **sequential** formula (matching `Policy::promoterNetAmount()`):

```
For each policy:
    gross_i      = premium_amount × promoter_percentage / 100
    isr_i        = gross_i × (isr_retention / 100)
    after_isr_i  = gross_i - isr_i
    billing_i    = after_isr_i × (billing_retention / 100)
    net_i        = after_isr_i - billing_i           (= Policy::promoterNetAmount())
```

Then sum per-agent and for the team total. Returns `team_commission_breakdown` (gross, isr, billing, net) and per-agent breakdown in `commissionsByAgent` (with `total_gross`, `total_isr`, `total_billing`, `total_net`).

### Agent Commission Breakdown (Agent view)

Same sequential formula, using `agent_percentage` from `getProductCommissionMap()`. Returns `commission_breakdown` (gross, isr, billing, net).

> **Full source for `PromoterController.php` (~400 lines) and `AgentController.php` (~200 lines) are in the repository. The commission breakdown in both controllers now uses the sequential ISR→billing formula per-policy. This still duplicates the tier-matching logic from `PolicyController::getProductCommissionMap()` but the ISR/billing math is now consistent with `Policy::agentNetAmount()`/`promoterNetAmount()`.

### Source: Commission Breakdown Template (`resources/js/Pages/Directory/Show.vue`)

The commission breakdown is rendered via `stats.team_commission_breakdown` (promoter team total), `stats.commissions[].total_gross|total_isr|total_billing|total_net` (per-agent), and `stats.commission_breakdown` (agent view). Labels use sentence-case Spanish without hardcoded percentages (since `isr_retention`/`billing_retention` can vary per policy).

**Agent view commission card:**
- `"Comisión total generada"` → `stats.commission_breakdown.gross`
- `"− Retención de ISR"` → `stats.commission_breakdown.isr`
- `"− Costo de facturación"` → `stats.commission_breakdown.billing`
- `"Total neto"` → `stats.commission_breakdown.net` (emphasized)

**Promoter view team header:**
- `"Total"`, `"ISR: −"`, `"Facturación: −"`, `"Neto:"` from `team_commission_breakdown`

**Per-agent breakdown under promoter:**
- `"Comisión total generada"`, `"− Retención de ISR"`, `"− Costo de facturación"` with `total_net` as the main displayed figure

---

## Agent Detail View (`AgentController@show`)

### Stats
1. **Policies Count** — Total policies within date range.
2. **Total Commissions** — Sum of premium × agent_percentage from the product commission map.
3. **Bonus Progress** — All active bonus schemes evaluated for this agent via `BonusOrchestratorService`.

---

## Agent Activation / Deactivation

### `deactivated_at` Logic
When an agent's `is_active` is toggled OFF:
- `deactivated_at` is set to `now()`
- The agent remains visible in reports for the month of deactivation

When toggled back ON:
- `deactivated_at` is set to `null`

### `activeInPeriod` Scope (Agent model) — FULL IMPLEMENTATION

```php
/**
 * Scope: Agentes que deben considerarse "activos" para un periodo dado.
 *
 * Un agente inactivo SOLO aparece hasta el mes en que fue dado de baja.
 * Si el periodo termina en un mes posterior al de su baja, se excluye.
 *
 * Ejemplo: dado de baja el 13/05/2026 → aparece en mayo, desaparece en junio.
 */
public function scopeActiveInPeriod($query, string $periodEnd)
{
    $monthKey = substr($periodEnd, 0, 7); // "YYYY-MM"

    return $query->where(function ($q) use ($monthKey) {
        $q->where('is_active', true)
          ->orWhere(function ($q2) use ($monthKey) {
              $q2->where('is_active', false)
                 ->whereNotNull('deactivated_at')
                 ->whereRaw("DATE_FORMAT(deactivated_at, '%Y-%m') >= ?", [$monthKey]);
          });
    });
}
```

This means: if an agent was deactivated May 13, they appear in May reports but not June reports. Critical for bonus calculations where team composition mid-quarter matters.

### Source: `app/Models/Promoter.php` (FULL)

```php
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
        'photo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function agents()
    {
        return $this->hasMany(Agent::class);
    }
}
```

### Source: `app/Models/Agent.php` (FULL)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Policy;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'promoter_id',
        'name',
        'photo',
        'is_active',
        'deactivated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public function promoter()
    {
        return $this->belongsTo(Promoter::class);
    }

    public function policies()
    {
        return $this->hasMany(Policy::class);
    }

    public function scopeActiveInPeriod($query, string $periodEnd)
    {
        $monthKey = substr($periodEnd, 0, 7);
        return $query->where(function ($q) use ($monthKey) {
            $q->where('is_active', true)
              ->orWhere(function ($q2) use ($monthKey) {
                  $q2->where('is_active', false)
                     ->whereNotNull('deactivated_at')
                     ->whereRaw("DATE_FORMAT(deactivated_at, '%Y-%m') >= ?", [$monthKey]);
              });
        });
    }
}
```

> **Full source for `PromoterController.php` (~400 lines) and `AgentController.php` (~200 lines) are in the repository. The key business logic sections are documented in prose above. The commission breakdown in `PromoterController@show` iterates tiers manually — this duplicates `PolicyController::getProductCommissionMap()` logic and should be extracted.

---

## Photo Management
- Photos stored in `storage/app/public/photos/`
- Accepted formats: JPEG, PNG, JPG, WebP
- Max size: 2MB
- When updating, old photo is deleted from storage before new one is saved
- Uses Laravel's `Storage::disk('public')`

---

## Dependencies
- **Bonus Engine** — `BonusOrchestratorService` for promoter/agent bonus progress
- **Policy Management** — Reads policies for sales volume and commission calculations
- **Commission Schemes** — Reads commission percentages from active scheme

## Known Limitations / Tech Debt
1. **Agent index/create redirect:** `AgentController@index` and `@create` redirect to the directory/promoter routes. This is intentional (unified directory) but may surprise developers expecting standard resource behavior.
2. **No soft deletes:** Deleting an agent with policies via `ON DELETE CASCADE` permanently removes all their policy records. Consider implementing Laravel soft deletes.
3. **Promoter deactivation:** Unlike agents, promoters don't have `deactivated_at` tracking. There's no `activeInPeriod` scope on promoters.
4. **Commission tier-matching duplication:** The promoter detail view manually iterates scheme tiers to find commission percentages. This duplicates `PolicyController::getProductCommissionMap()` logic. The ISR/billing math is now consistent (sequential, per-policy) but the tier-lookup should be centralized.
5. **Photo storage:** No thumbnail generation. Photos are stored at original resolution.
