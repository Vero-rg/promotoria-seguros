# 03 — Module: Dashboard & Smart Alerts

## Business Purpose
The central command panel for the insurance promotería. Displays global KPIs for the selected time period, projected bonuses across the entire sales force, and intelligent alerts to guide management decisions (who's close to a bonus, who needs help, period closing urgency).

## Key Files

| File | Role |
|------|------|
| `app/Http/Controllers/DashboardController.php` | All dashboard logic (~500 lines) |
| `resources/js/Pages/Dashboard/` | Vue frontend for the dashboard |
| `routes/web.php` (line 18) | Route: `GET /dashboard` → `DashboardController@index` |

## Routes
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/dashboard` | `DashboardController@index` | `dashboard` |

Query params: `filter` (today, month, year, q1-q4, custom), `start_date`, `end_date`.

---

## KPIs Calculated

### 1. Net Income (Ingreso Neto)

**Ingreso Neto = PNA − comisiones netas (agente + promotor).**

Commissions are resolved through `App\Services\CommissionBreakdownService` for every `Pagada` policy in the period:

- Gross commission = the amount stored on the policy when it is greater than 0; otherwise `pcaAmount() × percentage / 100`, where the percentage is the one stored on the policy or, if it is 0, the active commission scheme (`ProductCommissionService::map()`).
- Deductions are sequential: **ISR → impuesto cedular → costo de facturación**.

```php
$currentCommissions = $this->commissions->summarize($policies, CommissionBreakdownService::ROLE_AGENT);
$currentPromoterCommissions = $this->commissions->summarize($policies, CommissionBreakdownService::ROLE_PROMOTER);
$currentNetCommissions = $currentCommissions['net'] + $currentPromoterCommissions['net'];

$currentNetIncome = round($totalPNA - $currentNetCommissions, 2);
```

`Policy::netAmount()` still returns the sum of both net commissions for a single policy, but the Dashboard KPI no longer uses it directly.

### 2. PNA (Prima Neta Acumulada)

```php
$totalPNA = round($policies->sum('premium_amount'), 2);
```

### 3. PCA (Prima Computable Acumulada)

```php
$totalPCA = round($policies->sum(fn ($p) => $p->pcaAmount()), 2);
```

`Policy::pcaAmount()` = `max(0, premium_amount − 1,350)` per policy. The Dashboard card shows PNA and PCA as the detail of the Ingreso Neto card.

Note: The bonus calculators (e.g., `AgentFirstYearProductionCalculator`) compute PCA as raw `premium_amount` sum without the $1,350 deduction. This is a discrepancy — verify which is correct for each context.

### 4. Policy Volume by Product
Counts and premium totals broken down by product type:
- **METALIFE** (life insurance, cataloged as "Vida")
- **PERFECTLIFE** (life insurance, cataloged as "Vida")
- **PRIMORDIAL** (standalone product type)

Both count and dollar volume are tracked, with period-over-period comparison.

### 5. Projected Bonuses
Iterates ALL active agents with policies and ALL active promoters with team activity. For each, runs `BonusOrchestratorService::calculateAll()` to determine the total projected bonus payout. This is the most expensive operation — O(n × m) where n = people, m = bonus schemes.

### 6. Active Sales Force
- Count of active promoters (`is_active = true`)
- Count of active agents (`is_active = true`)

### 7. Policy Trend (`trends` + `trend_grouping`)
`buildTrend()` returns the paid-policy (`Pagada`) series for the selected period:

- **Grouping:** `day` when the range is 62 days or less (Hoy, Mes, trimestres, rangos personalizados cortos); `month` for longer ranges (Año).
- **Each point:** `{ date, label, count, premium }`, including buckets with zero activity so the chart always covers the whole period.
- The frontend lets the user switch between **Pólizas** (count) and **Prima vendida** (`premium` sum), shows the period totals plus the best day/month, and opens a detail panel with that bucket's policies and premium (with its share of the period) when a column is clicked.

---

## Top Performers (Top 5)

Both lists use the same `CommissionBreakdownService` as the KPIs, so `commission_breakdown` includes `gross`, `isr`, `cedular`, `billing` and `net`. The promoter list uses the policies of the selected period (bonus evaluation may internally expand to the scheme's frequency).

---

## Smart Alert System

The dashboard generates up to 20 intelligent alerts by evaluating the top 20 agents and top 15 promoters against their active bonus schemes.

### Alert Types

| Type | Trigger | Message Pattern |
|------|---------|-----------------|
| `period_close` | ≤10 days until quarter end | "⏰ Faltan X días para el cierre trimestral..." |
| `warning` | Agent has met PCA but missing only Primordial requirement for First Year Production | "¡Casi listo! A X solo le falta cumplir con el requisito de pólizas Primordial..." |
| `agent_near_bonus` | Agent at 50-99% of a bonus target | "🎯 X está al Y% de desbloquear «BonusName» — le falta $Z en metric" |
| `agent_product_mix` | Bonus requires product mix but agent only sells 1 type | "📦 X necesita diversificar productos..." |
| `agent_low_commission` | ≥2 policies but commission < 5% of volume | "💸 X tiene N pólizas pero su comisión es baja..." |
| `product_monthly_pace` | Agent hasn't issued required product policy this month, 4-7 days left | "⏳ X aún no tiene una póliza PRIMORDIAL este mes..." |
| `product_monthly_pace_urgent` | Same but ≤3 days left | "🚨 X aún no tiene una póliza PRIMORDIAL este mes..." |
| `promoter_near_bonus` | Promoter at 50-99% of a bonus target | "🏆 X está al Y% de «BonusName»..." |
| `promoter_recruits` | Missing ≤3 recruits for a bonus | "👥 A X le falta(n) N recluta(s) más..." |
| `promoter_irp` | IRP below required threshold | IRP warning |

### Performance Note
The alert system runs `BonusOrchestratorService::calculateAll()` for every top agent and promoter. This is computationally expensive. With 100+ agents, this could become slow. Currently limited to top 20 agents / 15 promoters for bonus-based alerts.

**Exception:** The `product_monthly_pace` / `product_monthly_pace_urgent` alert runs over **ALL first-year agents** (not just top 20) since the agents most likely to miss monthly requirements are often not top performers. This alert is much lighter — it only counts policies per product per month, no orchestrator calls. Capped at 10 alerts total to avoid flooding the dashboard.

---

## Period Filtering Logic

| Filter | Start | End |
|--------|-------|-----|
| `today` | Today 00:00 | Today 23:59 |
| `month` (default) | 1st of current month | Last day of current month |
| `year` | Jan 1 current year | Dec 31 current year |
| `q1` | Jan 1 | Mar 31 |
| `q2` | Apr 1 | Jun 30 |
| `q3` | Jul 1 | Sep 30 |
| `q4` | Oct 1 | Dec 31 |
| `custom` | `start_date` param | `end_date` param |

Previous period is auto-calculated as same-length period immediately before the current one.

---

## Dependencies
- **Policy Management** — reads policies to compute metrics
- **Bonus Engine** — calls `BonusOrchestratorService` for projected bonuses and alerts
- **Promoter/Agent Management** — reads active sales force count
- **Commission Schemes** — `CommissionBreakdownService` + `ProductCommissionService` resolve commission percentages

## Known Limitations / Tech Debt
1. **No caching:** Every dashboard load re-computes ALL bonuses for ALL active agents/promoters. This will not scale.
2. **Previous period bonus estimation:** Uses a simplified `calculateProjectedBonuses()` method instead of re-running the orchestrator. This means period-over-period bonus growth is approximate.
3. **Hardcoded product types:** METALIFE, PERFECTLIFE, PRIMORDIAL are string literals in the controller. Adding a product requires code changes.
4. **Alert limit of 20:** Hard ceiling at 20 alerts. No prioritization beyond the iteration order (top agents by volume, then top promoters).
5. **Promoter bonus period:** Bonus alerts and projections load policies for the quarter, but the Top Promoters card (volume and commissions) uses the selected period.
6. **PCA formula discrepancy:** The Dashboard computes PCA as `max(0, premium - 1,350)`. Bonus calculators (e.g., `AgentFirstYearProductionCalculator`) compute PCA as raw `premium_amount` sum. These are different formulas — verify which is correct.
7. **Full source:** `DashboardController.php` is ~600 lines. See the repository file directly. The key formulas are documented above with exact source code.
