# Promotoría Seguros — AI Coding Instructions

> **Purpose:** Single source of truth for any AI coding agent working on this project.  
> **Last Updated:** 2026-07-15

---

## Project Overview

**Promotoría Seguros** is an internal web application for managing an insurance sales force in Mexico. It allows back-office administrators to:

- Manage the sales hierarchy: **Promoters** (team leaders) and **Agents** (salespeople).
- Register and track insurance **Policies** (pólizas) sold by agents, with financial data (premium, commissions, taxes, billing costs).
- Configure **Compensation Schemes**: commissions (per-product percentages) and bonuses (tiered, conditional payouts based on performance metrics like PCA, PP, IRP, recruitment quotas).
- View a **Dashboard** with global KPIs (PNA, PCA, net income, policy volume by product), projected bonus payouts, and smart alerts.
- The system is an **internal tool for office staff**, not a self-service portal for promoters or agents.

---

## Tech Stack

### Backend (PHP)
| Dependency | Version | Purpose |
|------------|---------|---------|
| PHP | ^8.2 | Language runtime |
| Laravel Framework | ^12.0 | Full-stack PHP framework |
| Laravel Tinker | ^2.10.1 | Interactive REPL |
| Inertia.js (inertia-laravel) | ^2.0 | Server-side adapter for SPA bridge |
| Ziggy (tightenco/ziggy) | ^2.4 | Exposes Laravel routes to JavaScript |

### Frontend (TypeScript / Vue)
| Dependency | Version | Purpose |
|------------|---------|---------|
| Vue 3 | ^3.5.13 | UI framework (Composition API) |
| Inertia.js (@inertiajs/vue3) | ^2.0-beta.3 | Client-side SPA bridge |
| TypeScript | ^5.2 | Type safety |
| Vite | ^6.0 | Bundler & dev server |
| Tailwind CSS | ^3.4 | Utility-first CSS |
| Element Plus | ^2.14 | UI component library (tables, forms, dialogs) |
| Radix Vue | ^1.9.11 | Accessible headless UI primitives |
| @headlessui/vue | ^1.7.23 | Additional unstyled components |
| Lucide Vue Next | ^0.468 | Icon library |
| @vueuse/core | ^12.0 | Vue composable utilities |
| class-variance-authority | ^0.7 | CSS class variant management |
| clsx + tailwind-merge | ^2.1 / ^2.5 | Class merging utilities |
| tailwindcss-animate | ^1.0 | Animation utilities |

### Dev Dependencies
| Dependency | Version | Purpose |
|------------|---------|---------|
| ESLint | ^9.17 | JavaScript/TypeScript linting |
| Prettier | ^3.4 | Code formatting |
| eslint-plugin-vue | ^9.32 | Vue-specific ESLint rules |
| @vue/eslint-config-typescript | ^14.3 | TypeScript ESLint integration |
| prettier-plugin-organize-imports | ^4.1 | Import organization |
| prettier-plugin-tailwindcss | ^0.6.9 | Tailwind class ordering |
| vue-tsc | ^2.2 | Vue type checking |
| Laravel Pint | ^1.18 | PHP code style fixer |
| PHPUnit | ^11.5.3 | Testing framework |
| Pest | (bundled w/ starter) | Elegant PHP testing |
| Laravel Sail | ^1.41 | Docker dev environment (optional) |

---

## Architecture

### High-Level Communication
```
Browser → Inertia.js (SPA) → Laravel Controller → Eloquent Model → Database
                                      ↓
                              Service / Action Layer
                                      ↓
                              Inertia::render('PageName', props)
                                      ↓
                              Vue 3 Component (renders with props)
```

- **No REST API.** The app is a monolithic SPA using Inertia.js. All data flows as page props from controllers to Vue components. Form submissions go through standard POST/PATCH/DELETE requests with CSRF.
- **No Vue Router.** Routing is entirely server-side. The frontend uses Ziggy to generate URLs from Laravel named routes.

### Directory Structure

```
project-root/
├── app/
│   ├── Http/
│   │   ├── Controllers/        # Route handlers — keep THIN
│   │   │   ├── Auth/           # Authentication controllers
│   │   │   └── Settings/       # Profile & password controllers
│   │   ├── Middleware/         # HTTP middleware
│   │   └── Requests/           # Form Request validation classes (PUT VALIDATION HERE)
│   ├── Models/                 # Eloquent models — put relationships, scopes, accessors HERE
│   ├── Providers/              # Service providers
│   └── Services/               # Business logic services & calculators
│       └── BonusCalculators/   # Strategy pattern: one calculator per bonus type
├── bootstrap/                  # App bootstrap (app.php, providers.php)
├── config/                     # Laravel config (app, auth, database, session, etc.)
├── database/
│   ├── factories/              # Model factories for testing/seeding
│   ├── migrations/             # Database migrations (chronological, prefixed)
│   └── seeders/                # Database seeders
├── docs/context/               # Architectural documentation (for other AIs)
├── public/                     # Web root (index.php, compiled assets)
├── resources/
│   ├── css/app.css             # Tailwind entry & CSS custom properties (shadcn-vue theme)
│   ├── js/
│   │   ├── app.ts              # Inertia + Vue + ElementPlus bootstrap
│   │   ├── Pages/              # Vue SFC pages — one folder per route group
│   │   │   ├── auth/           # Login, register, forgot/reset password
│   │   │   ├── Dashboard/      # Dashboard page
│   │   │   ├── Directory/      # Promoters + Agents unified views
│   │   │   ├── Policies/       # Policy CRUD views
│   │   │   ├── Scheme/         # Commission & Bonus scheme views
│   │   │   │   ├── Commissions/
│   │   │   │   └── Bonuses/
│   │   │   └── settings/       # Profile, password
│   │   ├── Components/         # Reusable Vue components (shadcn-vue UI)
│   │   │   └── ui/             # shadcn-vue UI primitives
│   │   ├── composables/        # Vue composables (e.g., useInitials)
│   │   ├── layouts/            # Page layout components
│   │   ├── lib/                # Utility libraries
│   │   └── types/              # TypeScript type definitions
│   └── views/                  # Blade views (minimal — mainly app.blade.php shell)
├── routes/
│   ├── web.php                 # Main routes → loads sub-files
│   ├── auth.php                # Auth routes
│   ├── settings.php            # Settings routes
│   └── console.php             # Artisan console routes
├── storage/                    # Logs, cache, uploads (app/public/photos/)
├── tests/
│   ├── Feature/                # Feature tests (HTTP)
│   ├── Unit/                   # Unit tests
│   ├── Pest.php                # Pest configuration
│   └── TestCase.php            # Base test case
└── vendor/                     # Composer dependencies
```

---

## Coding Standards & Language Rules

### Language Separation (CRITICAL — NEVER VIOLATE)

| Context | Language | Case Rule |
|---------|----------|-----------|
| **Code** (variables, functions, classes, methods, comments, commit messages, technical docs) | **English** | Follow language conventions |
| **User-facing text** (UI labels, buttons, success/error messages, notifications, emails, validation messages) | **Spanish** | **Sentence case** — only first word and proper nouns capitalized. Never Title Case or ALL CAPS. |

**Examples:**

```
✅ CORRECT (code):     class PromoterController extends Controller { ... }
✅ CORRECT (code):     // Calculate the PCA for the given period
✅ CORRECT (UI):       "Póliza creada exitosamente."
✅ CORRECT (UI):       "El campo nombre es obligatorio."
❌ WRONG (UI):         "Póliza Creada Exitosamente"   (Title Case)
❌ WRONG (UI):         "POLIZA CREADA EXITOSAMENTE"   (ALL CAPS)
❌ WRONG (UI):         "Policy created successfully."  (English in UI)
❌ WRONG (code):       "El campo nombre es obligatorio." (Spanish in code comment)
```

---

## Architecture & Design Principles

### SOLID Principles
Apply SOLID throughout. In particular:
- **S** (Single Responsibility): Each class does ONE thing. One calculator = one bonus type.
- **O** (Open/Closed): Extend via new classes (Strategy pattern), not by modifying existing ones. Use `BonusCalculatorFactory` to register new calculators.
- **L** (Liskov): All bonus calculators implement `BonusCalculatorInterface` and are substitutable.
- **I** (Interface Segregation): Keep interfaces focused. `BonusCalculatorInterface` has exactly one method: `calculate()`.
- **D** (Dependency Inversion): Inject dependencies via constructor. `BonusOrchestratorService` receives `BonusCalculatorFactory` through its constructor.

### Layer Responsibilities

| Layer | What Belongs | What Does NOT Belong |
|-------|-------------|---------------------|
| **Model** | Relationships (`hasMany`, `belongsTo`), scopes (`activeInPeriod`), accessors/mutators, casts, simple computed attributes (`netAmount()`), status constants | Business logic that spans multiple models, HTTP concerns, validation rules |
| **Controller** | Orchestration: validate input → call service/action → return response/redirect. Route parameter binding. | Business logic, validation rules (use Form Requests), direct DB queries |
| **Service** | Complex business logic that coordinates multiple models. Example: `BonusOrchestratorService` orchestrates bonus calculations across schemes. | HTTP concerns (don't access `request()` directly), rendering logic |
| **Action** | Single-purpose, discrete operations. Naming convention: `VerbNounAction` (e.g., `CalculateBonusAction`, `DeactivateAgentAction`). | Multi-step workflows (use a Service for that) |
| **Form Request** | Validation rules, authorization, custom messages. | Business logic beyond simple rule definitions |

### Form Request Classes (MANDATORY)
All validation logic **must** live in dedicated Form Request classes under `app/Http/Requests/`. The current codebase has inline validation in controllers (`$request->validate(...)`). **Refactor these into Form Requests** as you touch each controller.

Example structure:
```php
// app/Http/Requests/StorePolicyRequest.php
class StorePolicyRequest extends FormRequest
{
    public function rules(): array { ... }
    public function messages(): array { ... }     // Spanish
    public function attributes(): array { ... }  // Spanish
}
```

### Route Organization (MANDATORY for new routes)
Organize routes by module inside `routes/web/` (create this directory). Load them in `routes/web.php`:

```php
require __DIR__.'/web/auth.php';
require __DIR__.'/web/promoters.php';
require __DIR__.'/web/agents.php';
require __DIR__.'/web/policies.php';
require __DIR__.'/web/schemes.php';
require __DIR__.'/web/dashboard.php';
require __DIR__.'/web/settings.php';
```

### Other Best Practices
- **Dependency injection** over `app()` helper or `new` in constructors where possible.
- **Small, single-purpose methods.** If a method exceeds ~40 lines, extract.
- **No fat classes.** Controllers >300 lines are a red flag. The current `DashboardController` (~500 lines) and `SchemeController` (~300 lines) are candidates for refactoring.
- **Consistent error handling.** Use `try/catch` with `Log::warning()` or `Log::error()`, never silently swallow exceptions without at least logging.
- **DRY with restraint.** Don't create abstractions for one-off code, but DO extract repeated patterns (like the bonus progress calculation in `DashboardController` and `PromoterController`).
- **Meaningful naming.** Avoid abbreviations. `$p` is not acceptable; `$promoter` is.

---

## Conventions & Code Style

### PHP (Laravel Pint — PSR-12)
- Run `./vendor/bin/pint` before committing.
- PSR-12 standard with Laravel defaults.
- No custom Pint config present; use the defaults.

### TypeScript / Vue (ESLint + Prettier)
- **Prettier config** (`.prettierrc`):
  - Semicolons: yes
  - Single quotes: yes
  - Print width: 150
  - Tab width: 4
  - Plugins: `organize-imports`, `tailwindcss`
- **ESLint** (flat config):
  - Vue essential + TypeScript recommended
  - `vue/multi-word-component-names`: off
  - `@typescript-eslint/no-explicit-any`: off
- Run `npm run format` and `npm run lint` before committing.

### Naming Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| PHP classes | PascalCase | `BonusOrchestratorService` |
| PHP methods | camelCase | `calculateAll()` |
| PHP properties/variables | camelCase | `$bonusSchemes` |
| Eloquent models | PascalCase, singular | `Agent`, `Policy` |
| Database tables | snake_case, plural | `agents`, `scheme_tiers` |
| Database columns | snake_case | `premium_amount`, `deactivated_at` |
| Migrations | timestamp + snake_case description | `2026_05_20_194148_create_policies_table.php` |
| Vue components | PascalCase | `PolicyForm.vue` |
| Vue composables | camelCase, `use` prefix | `useInitials` |
| TypeScript types | PascalCase | `Policy`, `BonusResult` |
| Named routes | kebab-case, dot-separated hierarchy | `esquemas.bonos.crear` |
| Route files | camelCase, module name | `routes/web/policies.php` |
| Service classes | PascalCase, `Service` suffix | `BonusOrchestratorService` |
| Action classes | PascalCase, `Action` suffix | `CalculateBonusAction` |
| Calculator classes | PascalCase, `Calculator` suffix | `ConnectionBonusCalculator` |
| Interface | PascalCase, `Interface` suffix | `BonusCalculatorInterface` |

### File Organization
- One class per file (PSR-4).
- Pages grouped by domain in `resources/js/Pages/{Domain}/`.
- Reusable UI components in `resources/js/Components/ui/` (shadcn-vue pattern).
- Domain-specific components alongside their pages.

---

## Key Files & Entry Points

| File | Purpose |
|------|---------|
| `public/index.php` | Web entry point |
| `bootstrap/app.php` | App bootstrap — registers middleware (`HandleInertiaRequests`, `AddLinkHeadersForPreloadedAssets`) and routes |
| `bootstrap/providers.php` | Service provider registration |
| `routes/web.php` | Main route file — loads `auth.php`, `settings.php`, and defines all domain routes |
| `routes/auth.php` | Authentication routes (guest + auth) |
| `routes/settings.php` | Profile and password routes |
| `config/app.php` | App config (name, env, timezone: `America/Mexico_City`) |
| `config/auth.php` | Auth guards (`web`/session) and providers (`users`/eloquent) |
| `config/database.php` | DB config (default: sqlite, supports MySQL) |
| `config/session.php` | Session config (driver: database via `SESSION_DRIVER`) |
| `resources/js/app.ts` | Frontend entry — boots Inertia, Vue, ElementPlus (Spanish locale), Ziggy, theme |
| `resources/css/app.css` | Tailwind entry + shadcn-vue CSS variables for theming |
| `vite.config.ts` | Vite config — `@` alias → `resources/js`, ElementPlus plugin |
| `tsconfig.json` | TypeScript config — path aliases for `@/*` and `ziggy-js` |
| `composer.json` | PHP dependencies + dev scripts |
| `package.json` | JS dependencies + dev scripts |
| `.prettierrc` | Prettier formatting rules |
| `eslint.config.js` | ESLint flat config |
| `phpunit.xml` | PHPUnit config — SQLite in-memory for testing, RefreshDatabase trait |
| `artisan` | Laravel CLI entry point |

---

## Database / Data Layer

### Database Engine
- **Development:** MySQL (`DB_CONNECTION=mysql` in `.env`).
- **Testing:** SQLite in-memory (`:memory:` in `phpunit.xml`).
- **Default fallback:** SQLite.

### ORM
- Laravel Eloquent ORM. No raw SQL in application code.

### Models (7 total)

| Model | Table | Relationships |
|-------|-------|---------------|
| `User` | `users` | None (independent from promoter/agent hierarchy) |
| `Promoter` | `promoters` | `hasMany → Agent` |
| `Agent` | `agents` | `belongsTo → Promoter`, `hasMany → Policy` |
| `Policy` | `policies` | `belongsTo → Agent` |
| `Scheme` | `schemes` | `hasMany → SchemeVersion`, `hasManyThrough → SchemeTier` |
| `SchemeVersion` | `scheme_versions` | `belongsTo → Scheme`, `hasMany → SchemeTier` |
| `SchemeTier` | `scheme_tiers` | `belongsTo → SchemeVersion` |

### Key Model Features
- **Agent::activeInPeriod($periodEnd):** Custom scope that includes inactive agents whose `deactivated_at` month is ≥ the period end month.
- **Policy:** Has status constants (`Activa`, `No tomada`, `Pagada`), `netAmount()` computed accessor, and status transition methods.
- **Schemes:** JSON columns for flexible configuration (`requires_product`, `conditions`, `pna_equivalences`, `quarterly_recruits`).
- For full schema details, see `docs/context/01-database-schema.md`.

---

## Testing

### Framework
- **PHPUnit 11.5** with **Pest** wrapper.
- Feature tests automatically use `RefreshDatabase` trait (configured in `tests/Pest.php`).

### Running Tests
```bash
php artisan test                # Run all tests
php artisan test --filter=...   # Filter by name
```

### Testing Standards
- Write tests for new features. Aim for coverage on business logic (services and calculators).
- Feature tests for HTTP endpoints; Unit tests for services/calculators.
- Use model factories (`database/factories/`) rather than manual model creation.
- Test environment uses SQLite in-memory — fast and isolated.

---

## Environment & Setup

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+
- MySQL (or use SQLite for simpler setup)

### Quick Start
```bash
# Clone & install
git clone <repo-url>
cd promotoria-seguros
composer install
npm install

# Configure environment
cp .env.example .env
php artisan key:generate

# Set up database (edit .env with DB credentials, then:)
php artisan migrate
php artisan db:seed    # if seeders exist

# Link storage (for photo uploads)
php artisan storage:link

# Run dev servers (all three at once)
composer dev
# → starts Laravel server (:8000), Queue listener, and Vite dev server

# Or run individually:
php artisan serve
npm run dev
php artisan queue:listen --tries=1
```

### Key Environment Variables
| Variable | Purpose | Typical Value |
|----------|---------|---------------|
| `APP_NAME` | App name | `"Promotoría Seguros"` |
| `APP_ENV` | Environment | `local` / `production` |
| `APP_URL` | Base URL | `http://localhost:8000` |
| `APP_LOCALE` | Locale | `es` |
| `DB_CONNECTION` | Database driver | `mysql` / `sqlite` |
| `DB_DATABASE` | Database name | `promotoria-seguros` |
| `SESSION_DRIVER` | Session storage | `database` |
| `QUEUE_CONNECTION` | Queue driver | `database` |
| `CACHE_STORE` | Cache driver | `database` |

---

## Things to Avoid

### ❌ NEVER Do These:

1. **Inline validation in controllers.** Always use dedicated Form Request classes in `app/Http/Requests/`.
2. **Business logic in controllers.** Controllers orchestrate; they do not calculate or decide.
3. **Spanish in code.** Variable names, method names, classes, comments — all English. Spanish is ONLY for user-facing strings.
4. **Title Case or ALL CAPS in user-facing text.** Always sentence case in Spanish: `"Póliza creada exitosamente."`, never `"PÓLIZA CREADA EXITOSAMENTE"`.
5. **Direct `new` instantiation of services in controllers.** Use dependency injection via constructor or method injection.
6. **Hardcoded product types.** Currently `METALIFE`, `PERFECTLIFE`, `PRIMORDIAL` appear as string literals across many files. When adding product-related code, prefer extracting constants or a config/table.
7. **Silently swallowing exceptions.** Always log errors: `Log::warning(...)` or `Log::error(...)`.
8. **Overriding existing behavior in `BonusCalculatorFactory` without adding tests.** The Strategy pattern depends on correct factory resolution.
9. **Creating routes in a single massive file.** New routes go in `routes/web/{module}.php` and are loaded from `routes/web.php`.
10. **Adding columns via raw SQL.** Always use migrations.
11. **Removing migrations.** Only add new ones. Existing migrations define the schema history.

### ⚠️ Code That Needs Refactoring (Technical Debt)
When you touch these areas, clean them up:

- **`DashboardController`** (~500 lines): Extract bonus calculation loops into a dedicated Service or Action.
- **`SchemeController@store` / `@update`**: Inline validation with very long rule arrays → Form Requests.
- **`PromoterController@show`**: Manual commission calculation duplicates logic from `PolicyController::getProductCommissionMap()`.
- **`CommissionService`** (`app/Services/CommissionService.php`): Legacy service that references schema fields that no longer exist (`code`). Audit before removing.
- **Duplicate bonus evaluation logic** between `DashboardController` and `PromoterController@show` / `AgentController@show`. Extract to a shared service.
- **Hardcoded product type arrays** in `DashboardController` (METALIFE, PERFECTLIFE, PRIMORDIAL). Move to config or a constant.

---

## Additional Notes

### How the Bonus System Works
The compensation engine is the most complex part of the app. See `docs/context/07-module-bonus-engine.md` for full details. Key points:

- **Strategy Pattern:** Each bonus type has its own calculator implementing `BonusCalculatorInterface`.
- **Factory:** `BonusCalculatorFactory` maps `template_key` strings to calculator classes. Both English keys and Spanish display names are mapped.
- **Orchestrator:** `BonusOrchestratorService` handles dependency resolution (bonus A must be achieved before bonus B), period expansion (monthly → full month, trimestral → full quarter), and result consolidation.
- **Product Aliases:** The `ProductAliasResolver` trait maps the alias `"Vida"` → `["METALIFE", "PERFECTLIFE"]`. When a scheme requires "Vida", both products count.

### Metrics Glossary
| Abbreviation | Spanish | Definition |
|-------------|---------|------------|
| PNA | Prima Neta Acumulada | Total gross premium (sum of `premium_amount`) |
| PCA | Prima Computable Acumulada | PNA minus $1,350 per policy |
| PP | Producción Primaria | Primary production (sum of premiums in period) |
| IRP | Índice de Retención de Pólizas | Policy retention index (active / total × 100) |
| ISR | Impuesto Sobre la Renta | Income tax retention (default 10%) |

### Translation Patterns
When adding user-facing strings in Spanish:
- ✅ `"agente"` (lowercase for general terms)
- ✅ `"El campo :attribute es obligatorio."` (sentence case)
- ❌ `"El Campo :Attribute Es Obligatorio."` (Title Case)
- ❌ `"EL CAMPO :ATTRIBUTE ES OBLIGATORIO."` (ALL CAPS)

### Context Documentation
More detailed architectural docs are in `docs/context/`. Share these with other AI assistants when they need deep context:

| File | Content |
|------|---------|
| `00-overview.md` | Tech stack, architecture, module index |
| `01-database-schema.md` | Full schema, ER diagram, migrations |
| `02-module-auth.md` | Auth module details |
| `03-module-dashboard.md` | Dashboard KPIs and alerts |
| `04-module-directory.md` | Promoters + Agents directory |
| `05-module-policies.md` | Policy management |
| `06-module-schemes.md` | Scheme configuration |
| `07-module-bonus-engine.md` | Bonus calculation engine |
| `08-module-settings.md` | User settings |
