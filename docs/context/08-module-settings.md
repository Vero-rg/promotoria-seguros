# 08 — Module: User Settings

## Business Purpose
Allows authenticated users to manage their profile information, change their password, and toggle the application appearance (light/dark mode).

## Key Files

| File | Role |
|------|------|
| `routes/settings.php` | Settings route definitions |
| `app/Http/Controllers/Settings/ProfileController.php` | Profile update & account deletion |
| `app/Http/Controllers/Settings/PasswordController.php` | Password change |
| `resources/js/Pages/settings/Profile.vue` | Profile edit form |
| `resources/js/Pages/settings/Password.vue` | Password change form |
| `resources/js/Pages/settings/Appearance.vue` | Theme toggle (inline render) |
| `resources/js/composables/useAppearance.ts` | Theme persistence logic |

---

## Routes

| Method | URI | Handler | Name |
|--------|-----|---------|------|
| GET | `/settings` | Redirect → `/settings/profile` | — |
| GET | `/settings/profile` | `ProfileController@edit` | `profile.edit` |
| PATCH | `/settings/profile` | `ProfileController@update` | `profile.update` |
| DELETE | `/settings/profile` | `ProfileController@destroy` | `profile.destroy` |
| GET | `/settings/password` | `PasswordController@edit` | `password.edit` |
| PUT | `/settings/password` | `PasswordController@update` | `password.update` |
| GET | `/settings/appearance` | Inline `Inertia::render('settings/Appearance')` | `appearance` |

All routes are protected by `auth` middleware.

---

## Profile Management

### Update Profile
- **Fields:** `name`, `email`
- **Validation:** Standard Laravel validation (unique email except for current user)
- **Email verification:** If email is changed, `email_verified_at` is reset to `null` and user must re-verify
- **Success flash:** Redirects back with success message

### Delete Account
- Requires current password confirmation
- Deletes the authenticated user record
- Invalidates session and redirects to login

---

## Password Management

### Change Password
- **Requires:** Current password confirmation
- **New password:** Must meet Laravel defaults (minimum 8 characters, confirmed)
- Uses `Hash::check()` for current password verification
- After successful change, the user remains logged in (no forced re-login)

---

## Appearance (Theme)

### Implementation
- **Storage:** `localStorage` via `useAppearance` composable
- **Options:** Light, Dark, System
- **Initialization:** `initializeTheme()` runs on app bootstrap, reads stored preference or system preference
- **No server-side persistence:** Theme preference is entirely client-side

---

## Dependencies
- **None.** This module is self-contained.

## Known Limitations / Tech Debt
1. **No profile photo:** The User model lacks a `photo` field, unlike Promoter and Agent. Users cannot set an avatar.
2. **Account deletion is permanent:** No soft delete. All user data is hard-deleted.
3. **No email change verification flow:** When email changes, `email_verified_at` is simply set to null. There's no automatic re-verification email sent. The user must manually request a new verification email.
4. **Theme toggle is basic:** Three options (light/dark/system). No custom theme colors or branding.
