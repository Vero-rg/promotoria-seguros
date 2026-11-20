# 00 — Project Overview

## Promotoría Seguros — Sistema de Gestión de Fuerza de Ventas

**Last Updated:** 2026-07-15

---

## 1. Tech Stack

| Layer          | Technology                           | Version            |
|----------------|--------------------------------------|--------------------|
| Backend        | Laravel (PHP)                        | ^12.0              |
| Language       | PHP                                  | ^8.2               |
| Frontend       | Vue 3 (Composition API)              | ^3.5               |
| SPA Bridge     | Inertia.js                           | ^2.0               |
| Bundler        | Vite                                 | ^6.0               |
| CSS Framework  | Tailwind CSS                         | ^3.4               |
| UI Components  | Element Plus + Radix Vue             | ^2.14 / ^1.9       |
| Icons          | Lucide Vue Next                      | ^0.468             |
| TypeScript     | TypeScript (frontend only)           | ^5.2               |
| Routing (FE)   | Ziggy (Laravel routes → JS)          | ^2.6               |
| Database       | SQLite (dev) / MySQL (prod-ready)    | —                  |
| ORM            | Laravel Eloquent                     | —                  |
| Auth           | Laravel Breeze (session-based)       | built-in           |
| Testing        | PHPUnit + Pest                       | ^11.5 / built-in   |
| Linting (PHP)  | Laravel Pint                         | ^1.18              |
| Linting (JS)   | ESLint + Prettier                    | ^9.17 / ^3.4       |

---

## 2. Project Structure

```
promotoria-seguros/
├── app/
│   ├── Http/
│   │   ├── Controllers/        # Route handlers (7 main controllers)
│   │   │   ├── Auth/           # Authentication flow
│   │   │   ├── Settings/       # Profile, Password, Appearance
│   │   │   ├── AgentController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── PolicyController.php
│   │   │   ├── PromoterController.php
│   │   │   └── SchemeController.php
│   │   ├── Middleware/         # HandleInertiaRequests, etc.
│   │   └── Requests/           # Form request validators
│   ├── Models/                 # Eloquent models (7 files)
│   │   ├── User.php
│   │   ├── Promoter.php
│   │   ├── Agent.php
│   │   ├── Policy.php
│   │   ├── Scheme.php
│   │   ├── SchemeVersion.php
│   │   └── SchemeTier.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── Services/
│       ├── CommissionService.php           # Legacy commission calculator
│       ├── BonusOrchestratorService.php    # Central bonus orchestrator
│       └── BonusCalculators/              # Strategy Pattern calculators
│           ├── BonusCalculatorInterface.php
│           ├── BonusCalculatorFactory.php
│           ├── ProductAliasResolver.php
│           ├── AgentFirstYearProductionCalculator.php
│           ├── PromoterFirstYearProductionCalculator.php
│           ├── ConnectionBonusCalculator.php
│           ├── MonthlyDevelopmentCalculator.php
│           ├── ActivityRatioCalculator.php
│           └── AdditionalAgentsCalculator.php
├── bootstrap/                  # App bootstrap, providers
├── config/                     # Laravel config files
├── database/
│   ├── factories/              # Model factories
│   ├── migrations/             # 16 migration files
│   └── seeders/                # Database seeders
├── public/                     # Public entry point
├── resources/
│   ├── css/                    # App CSS (Tailwind)
│   ├── js/
│   │   ├── Pages/              # Vue SFC pages, one folder per domain
│   │   │   ├── auth/
│   │   │   ├── Dashboard/
│   │   │   ├── Directory/      # Promoters + Agents unified directory
│   │   │   ├── Policies/
│   │   │   ├── Scheme/         # Commissions + Bonuses
│   │   │   │   ├── Commissions/
│   │   │   │   └── Bonuses/
│   │   │   └── settings/
│   │   ├── components/         # Reusable Vue components
│   │   ├── composables/        # Vue composables (e.g., useAppearance)
│   │   ├── layouts/            # Page layouts
│   │   ├── lib/                # Utility libraries
│   │   └── types/              # TypeScript type definitions
│   └── views/                  # Blade views (mainly app.blade.php shell)
├── routes/
│   ├── web.php                 # Main web routes (Inertia)
│   ├── auth.php                # Authentication routes
│   └── settings.php            # Profile/password/appearance routes
├── storage/                    # Logs, cache, file uploads
├── tests/                      # Pest/PHPUnit tests
└── vendor/                     # Composer dependencies
```

### Naming Conventions
- **Controllers:** `PascalCase`, suffixed with `Controller`, grouped in subdirectories for domain.
- **Models:** `PascalCase`, singular (e.g., `Agent`, `Policy`).
- **Migrations:** Lowercase snake_case with timestamp prefix.
- **Vue Pages:** `PascalCase` for components, organized in subfolders mirroring routes.
- **Database columns:** `snake_case`.
- **Routes:** `kebab-case` for named routes (e.g., `esquemas.bonos.crear`).
- **Services:** `PascalCase`, suffixed with `Service` or `Calculator`.

---

## 3. Architectural Decisions

### Auth Strategy
- **Laravel Breeze** with session-based authentication (Blade → Inertia hybrid).
- Guards: `web` guard, `users` provider (Eloquent).
- Email verification supported via signed URLs.
- Password reset with throttling (60s cooldown).
- No OAuth/JWT — purely cookie/session based. Suitable for internal business app.

### State Management
- **Inertia.js** handles all server-client state synchronization.
- No Vuex/Pinia — page props are passed from Laravel controllers to Vue pages.
- Form state is managed locally in Vue components via `useForm` (Inertia).
- Theme (light/dark) persisted via `localStorage` through `useAppearance` composable.

### API Style
- **No REST/JSON API.** All communication is server-rendered pages via Inertia.js.
- Routes are standard Laravel `web.php` routes returning `Inertia::render()`.
- Form submissions go through standard POST/PATCH/DELETE with CSRF protection.
- The backend and frontend are tightly coupled — this is a monolith SPA, not a separate API + client.

### Database ORM
- **Eloquent ORM** with standard Laravel conventions.
- Primary DB: SQLite (development), MySQL (production configuration available).
- Migrations handle all schema changes (no raw SQL in application code).
- JSON columns used for flexible conditions (`scheme_tiers.conditions`, `schemes.requires_product`, etc.).

### Deployment Setup
- Development: `composer dev` runs Laravel server + Vite dev + queue listener concurrently.
- Uses Vite for asset bundling with Laravel Vite Plugin.
- `storage:link` artisan command creates symlink for public file access.
- Queue driver: database (migrations include `jobs` table).
- Health check endpoint at `/up`.

### Key Design Patterns
- **Strategy Pattern:** Bonus calculators implement `BonusCalculatorInterface`, resolved by `BonusCalculatorFactory` based on `template_key`.
- **Orchestrator Pattern:** `BonusOrchestratorService` orchestrates all bonus calculations (dependency resolution, period expansion, result consolidation).
- **Trait:** `ProductAliasResolver` is a shared trait for product alias logic across calculators.
- **Resource Controllers:** Standard Laravel resource controllers used for CRUD.

---

## 4. Business Domain — High Level

This application manages an **insurance sales force**. The organizational hierarchy is:

```
User (system admin)
  └── Promoter (promotor) — sales team leader
       └── Agent (agente) — individual salesperson
            └── Policy (póliza) — insurance policy sold
```

### Core Business Concepts (Glossary)

| Term | Spanish | Definition |
|------|---------|------------|
| PNA | Prima Neta Acumulada | Total gross premium paid by clients. Sum of `premium_amount`. |
| PCA | Prima Computable Acumulada | PNA minus $1,350 per policy (the "computable" base for bonuses). |
| PP | Producción Primaria | Primary Production — sum of premiums in a period. |
| IRP | Índice de Retención de Pólizas | Policy retention index = (active policies / total policies) × 100. |
| ISR | Impuesto Sobre la Renta | Income tax retention (default 10%). |
| Facturación | Billing | Billing/factoring cost retention (default 5%). |
| Net Income | Ingreso Neto | Premium minus commission costs (ISR + billing deducted). |
| Scheme | Esquema | A commission or bonus configuration with versions and tiers. |
| Tier / Range | Banda / Rango | A conditional bracket within a scheme version. |
| Template Key | template_key | Immutable machine identifier for bonus type (e.g., `connection`). |

---

## 5. Module Index

| # | Module | Description | Context File |
|---|--------|-------------|--------------|
| 1 | **Authentication & Session** | Login, register, password reset, email verification, session management. | `02-module-auth.md` |
| 2 | **Dashboard** | Global KPIs (PNA, PCA, net income, volume by product), projected bonuses, intelligent alerts. | `03-module-dashboard.md` |
| 3 | **Directory (Promoters + Agents)** | Unified searchable directory of promoters and agents with filtering and status management. | `04-module-directory.md` |
| 4 | **Promoter Management** | CRUD for promoters, team view, commission calculation, bonus progress per promoter. | `04-module-directory.md` |
| 5 | **Agent Management** | CRUD for agents, individual stats, commission tracking, bonus progress, activation/deactivation. | `04-module-directory.md` |
| 6 | **Policy Management** | CRUD for insurance policies, status workflow, commission auto-calculation from scheme. | `05-module-policies.md` |
| 7 | **Commission Schemes** | Configuration of commission rules (product → percentage mapping) with versioning. | `06-module-schemes.md` |
| 8 | **Bonus Schemes** | Configuration of bonus rules with tiers, global rules, dependencies, and versoning. | `06-module-schemes.md` |
| 9 | **Bonus Calculation Engine** | Strategy pattern calculators: First Year Production, Connection, Monthly Development, Activity Ratio, Additional Agents. | `07-module-bonus-engine.md` |
| 10 | **User Settings** | Profile editing, password change, appearance (light/dark mode). | `08-module-settings.md` |
| 11 | **Product Commission Mapping** | Auto-resolution of commission percentages based on active commission scheme and product type. | `05-module-policies.md` |
| 12 | **Smart Alerts** | Dashboard alert system detecting agents/promoters near bonus thresholds, product mix gaps, period closures. | `03-module-dashboard.md` |

> **Note:** The project is actively growing. Current codebase shows 7 models, 16 migrations, and ~12 functional modules. The user anticipates ~100 tables and ~30 modules as the project matures. This documentation reflects the **current state** as of July 2026.

---

## 6. Key Package Dependencies

| Package | Purpose |
|---------|---------|
| `inertiajs/inertia-laravel` ^2.0 | Server-side Inertia adapter |
| `tightenco/ziggy` ^2.4 | JS route generation from Laravel routes |
| `element-plus` ^2.14 | Vue 3 UI component library (tables, forms, dialogs) |
| `radix-vue` ^1.9 | Headless UI primitives (accessible) |
| `@headlessui/vue` ^1.7 | Additional unstyled UI components |
| `tailwindcss` ^3.4 | Utility-first CSS framework |
| `tailwindcss-animate` ^1.0 | Tailwind animation utilities |
| `lucide-vue-next` ^0.468 | Icon library |
| `@vueuse/core` ^12.0 | Vue composition utilities |
| `class-variance-authority` ^0.7 | CSS class variance management |
| `concurrently` ^9.0 | Run multiple dev processes concurrently |

---

## 7. Environment & Configuration

- **Timezone:** `America/Mexico_City`
- **Locale:** `es` (set in `.env` as `APP_LOCALE=es`; fallback: `en`)
- **Cipher:** `AES-256-CBC`
- **Filesystem:** Public disk for user photo uploads (`storage/app/public/photos/`)
- **Queue:** Database driver
- **Session:** File-based (configurable via `.env`)

---

## 8. Known Architectural Notes & Warnings

1. **No API layer:** The app is purely Inertia-based with no REST/GraphQL endpoints. Adding a mobile app or external integration would require building a separate API.
2. **CommissionService vs BonusOrchestrator:** `CommissionService` is a legacy class (`app/Services/CommissionService.php`) that still references old schema structure (`code` field, `percentage`). It's partially superseded by `BonusOrchestratorService` but still present.
3. **Dashboard performance:** The DashboardController iterates over ALL active agents and promoters with their policies to calculate bonuses and alerts. This could become slow with thousands of records. No caching or pagination for bonus calculations.
4. **Product types are hardcoded:** METALIFE, PERFECTLIFE, PRIMORDIAL appear as string literals throughout the codebase. Adding a new product requires code changes in multiple places.
5. **Migration history shows refactoring:** The `2026_05_28_000002` migration removed `scheme_type`/`scheme_id` from policies table, indicating the commission model was restructured. Some cleanup may remain.
6. **Spanish/English mixing:** Code is in English (class names, methods), but user-facing strings, comments, and route names use Spanish. This is intentional for the Mexican market.
7. **Scheme deactivation logic:** Activating a commission deactivates ALL other commissions. Activating a bonus deactivates other bonuses with the same name. This is enforced in `SchemeController@store`.
8. **agent_automatic_percentage:** Used by `AgentFirstYearProductionCalculator` as the **Bono Automático (%)**. Policies paid automatically use this percentage; if ≥60% of the quarter's policies are automatic, the whole PCA is paid with it. The cash counterpart is `agent_percentage` (**Bono Efectivo %**).
