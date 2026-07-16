# 02 — Module: Authentication & Sessions

## Business Purpose
Manages system access. Only authenticated and verified users can use the application. This is a standard Laravel Breeze authentication setup adapted for Inertia.js.

## Key Files

| File | Role |
|------|------|
| `routes/auth.php` | All auth route definitions |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Login/logout |
| `app/Http/Controllers/Auth/RegisteredUserController.php` | Registration |
| `app/Http/Controllers/Auth/PasswordResetLinkController.php` | Forgot password |
| `app/Http/Controllers/Auth/NewPasswordController.php` | Reset password |
| `app/Http/Controllers/Auth/EmailVerificationPromptController.php` | Verification notice |
| `app/Http/Controllers/Auth/EmailVerificationNotificationController.php` | Resend verification |
| `app/Http/Controllers/Auth/VerifyEmailController.php` | Verify email via signed URL |
| `app/Http/Controllers/Auth/ConfirmablePasswordController.php` | Password confirmation |
| `config/auth.php` | Auth guard/provider configuration |
| `app/Models/User.php` | User model (standard Laravel) |

## Routes (from `routes/auth.php`)

### Guest Routes (unauthenticated)
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/register` | `RegisteredUserController@create` | `register` |
| POST | `/register` | `RegisteredUserController@store` | — |
| GET | `/login` | `AuthenticatedSessionController@create` | `login` |
| POST | `/login` | `AuthenticatedSessionController@store` | — |
| GET | `/forgot-password` | `PasswordResetLinkController@create` | `password.request` |
| POST | `/forgot-password` | `PasswordResetLinkController@store` | `password.email` |
| GET | `/reset-password/{token}` | `NewPasswordController@create` | `password.reset` |
| POST | `/reset-password` | `NewPasswordController@store` | `password.store` |

### Authenticated Routes
| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/verify-email` | `EmailVerificationPromptController` | `verification.notice` |
| GET | `/verify-email/{id}/{hash}` | `VerifyEmailController` | `verification.verify` |
| POST | `/email/verification-notification` | `EmailVerificationNotificationController@store` | `verification.send` |
| GET | `/confirm-password` | `ConfirmablePasswordController@show` | `password.confirm` |
| POST | `/confirm-password` | `ConfirmablePasswordController@store` | — |

## Auth Flow
1. Unauthenticated users are redirected to `/login` (also `/` redirects to `/login`).
2. After login, users land on `/dashboard`.
3. Email verification is enforced: `verified` middleware on all main routes.
4. Password reset uses Laravel's standard token-based flow with 60-minute expiry and 60-second throttle.
5. Session guard using `users` provider (Eloquent). No multi-tenant auth; single `users` table.

## Dependencies
- **None.** This module is foundational and depends on no other business modules.
- Used by: All other modules (via `auth` and `verified` middleware).

## Known Limitations / Tech Debt
1. **No role-based access control (RBAC):** All authenticated users have full access. No admin/operator/viewer distinction yet. If the system needs restricted views, RBAC must be added.
2. **No user-to-promoter/agent linkage:** Users (system operators) are completely separate from the promoter/agent hierarchy. A user cannot be "a promoter who logs in." The system is for back-office administrators only.
3. **Registration is open:** The `/register` route exists and is accessible to guests. If this is meant to be an internal tool, registration should be disabled or gated.
4. **No 2FA:** Only email+password. No two-factor authentication.
5. **Profile photo:** The User model has no `photo` field. Only Promoters and Agents have photos.
