# Context Documentation — Promotoría Seguros

> Generated: 2026-07-15
> 
> This documentation set is designed to be pasted into another AI assistant's context window **one file at a time**. Each file is self-contained but cross-references others where relevant.

---

## File Index

| File | Content | Lines (approx.) | Use When... |
|------|---------|-----------------|-------------|
| **`00-overview.md`** | Tech stack, architecture, naming conventions, module index, known issues | ~280 | Starting any conversation about this project |
| **`01-database-schema.md`** | All 10 tables, columns, relationships, ER diagram, migration history | ~350 | Working on database queries, migrations, or data model changes |
| **`02-module-auth.md`** | Authentication: login, register, password reset, email verification, routes | ~90 | Working on auth, permissions, or user management |
| **`03-module-dashboard.md`** | Dashboard: KPIs (PNA, PCA, net income), projected bonuses, smart alerts, exact formula source | ~150 | Working on dashboard, KPIs, or alerting features |
| **`04-module-directory.md`** | Promoters + Agents: unified directory, search, CRUD, activation/deactivation, detail views, full model source | ~230 | Working on sales force management, directory, or agent lifecycle |
| **`05-module-policies.md`** | Policies: CRUD, status workflow, ISR/billing formulas (sequential), full Policy.php + PolicyController.php + PolicyForm.vue source, tests | ~540 | Working on policy management, commission forms, or net amount calculations |
| **`06-module-schemes.md`** | Schemes: commission and bonus configuration, versioning, tiers, global rules, full SchemeController.php + model source | ~350 | Working on scheme configuration UI or rule editing |
| **`07-module-bonus-engine.md`** | Bonus Engine: Strategy Pattern, all 6 calculators, orchestrator, dependency resolution, factory + interface source, formula summary | ~310 | Working on bonus calculations, adding new bonus types, or debugging payouts |
| **`08-module-settings.md`** | User Settings: profile, password, appearance (light/dark), account deletion | ~70 | Working on user settings or theme toggling |

---

## How to Use with Another AI

### Scenario 1: "I need to add a new bonus type"
Provide in this order:
1. `00-overview.md` (architecture context)
2. `07-module-bonus-engine.md` (bonus engine details)
3. `06-module-schemes.md` (scheme configuration)
4. `01-database-schema.md` (tables involved)

### Scenario 2: "I need to fix a dashboard KPI calculation"
Provide:
1. `00-overview.md`
2. `03-module-dashboard.md`
3. `05-module-policies.md`
4. `01-database-schema.md`

### Scenario 3: "I need to understand the full data model"
Provide:
1. `00-overview.md`
2. `01-database-schema.md`

### Scenario 4: "Help me refactor the commission system"
Provide:
1. `00-overview.md`
2. `06-module-schemes.md`
3. `07-module-bonus-engine.md`
4. `05-module-policies.md`

---

## Quick Glossary

| Term | Meaning |
|------|---------|
| **Promotor** | Sales team leader (manages agents) |
| **Agente** | Individual sales agent (sells policies) |
| **Póliza** | Insurance policy (a sale) |
| **PNA** | Gross premium (Prima Neta Acumulada) |
| **PCA** | Computable premium (PNA - $1,500 per policy) |
| **PP** | Primary Production (sum of premiums) |
| **IRP** | Policy Retention Index |
| **ISR** | Income tax retention |
| **Esquema** | Compensation scheme (commission or bonus) |
| **Banda/Rango** | Tier/bracket within a scheme |
| **Template Key** | Machine identifier for a bonus type |

---

## Project Status (as of 2026-07-15)

- **Active development:** The project is in active build-out phase.
- **Migrations:** 16 migration files covering core schema.
- **Models:** 7 Eloquent models.
- **Controllers:** 16 controllers (including auth and settings).
- **Services:** 1 orchestrator + 6 strategy calculators + 1 legacy service.
- **Frontend:** Vue 3 + Inertia.js with ~6 page groups.
- **Testing:** PHPUnit + Pest configured; test files expected to grow.
