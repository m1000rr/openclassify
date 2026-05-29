# OpenClassify 3.1 — Bug & Security Audit

**Stack:** Laravel 12 · Filament 5 · Livewire 4 · nwidart/laravel-modules · Reverb/Echo · Spatie (permission, media, settings, model-states) · PostgreSQL
**Scope:** All 13 modules, app core, config, migrations, routes, service providers.
**Method:** Source-level review (findings below were confirmed by reading the actual files, not inferred).

---

## Severity Summary

| Severity | Count | Theme |
|----------|-------|-------|
| Critical | 6 | Account takeover, moderation bypass, public data exposure, mass assignment, demo/queue cross-schema corruption, shared storage collision |
| High | 8 | Banned users can log in, duplicate provider/migration registration, demo DoS, hardcoded secrets, state-machine bypass |
| Medium | 18 | N+1 queries, missing validation, inactive-data leaks, broken filters/links, missing migrations |
| Low | 7 | Mass-assignment footguns, stale columns, UX data loss |

---

## CRITICAL

### C1 — OAuth account takeover via email auto-link
**File:** `Modules/User/App/Http/Controllers/Auth/SocialAuthController.php:74-88`

When no `socialite_users` row exists, the controller does `User::firstOrCreate(['email' => $email], ...)` and immediately logs the user in. A victim who already registered with email/password can be impersonated by an attacker who completes OAuth with the same email (many providers return an email without proving ownership of a pre-existing local account).

```74:88:Modules/User/App/Http/Controllers/Auth/SocialAuthController.php
        if (! $user) {
            $email = filled($oauthUser->getEmail())
                ? strtolower(trim((string) $oauthUser->getEmail()))
                : sprintf('%s_%s@social.local', $provider, $oauthUser->getId());

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => trim((string) ($oauthUser->getName() ?: $oauthUser->getNickname() ?: ucfirst($provider).' User')),
                    'password' => Hash::make(Str::random(40)),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ],
            );
        }
```

**Fix:** Never auto-merge an OAuth identity to an existing user by email alone. Require an authenticated session (or signed link) to link providers, or create a fresh user and force email verification before merge. Only set `email_verified_at` when the provider asserts a verified email.

---

### C2 — Non-active listings are publicly viewable
**File:** `Modules/Listing/Http/Controllers/ListingController.php:160-176`

`show()` renders any listing resolved by route-model binding with **no status check**. Pending (un-moderated), sold, and expired listings — including seller contact details — are reachable at `/listings/{id}` by anyone who guesses the ID.

```160:168:Modules/Listing/Http/Controllers/ListingController.php
    public function show(Listing $listing)
    {
        if (
            Schema::hasColumn('listings', 'view_count')
            && (! auth()->check() || (int) auth()->id() !== (int) $listing->user_id)
        ) {
            $listing->increment('view_count');
            $listing->refresh();
        }
```

**Fix:**
```php
abort_unless(
    $listing->statusValue() === 'active'
    || (auth()->check() && (int) auth()->id() === (int) $listing->user_id),
    404
);
```

---

### C3 — Sellers can self-approve listings (moderation bypass)
**Files:** `Modules/Panel/App/Http/Requests/UpdateListingRequest.php:22`, `Modules/Listing/Models/Listing.php:286-293,530-552`, `Modules/Panel/resources/views/edit-listing.blade.php:51-57`

Quick-create correctly sets new listings to `pending`, but the panel edit form exposes a free-form status dropdown that includes `active`, and `updateFromPanel()` persists whatever status is submitted. Validation only checks the value is a valid status — not whether the transition is allowed for a seller.

```22:22:Modules/Panel/App/Http/Requests/UpdateListingRequest.php
            'status' => ['required', Rule::in(array_keys(Listing::panelStatusOptions()))],
```

A seller can create a listing then immediately edit it to `active`, bypassing moderation entirely.

**Fix:** Remove `active` from seller-editable options (keep `pending`/`sold`/`expired`), or restrict `active` to admin/Filament. Prefer dedicated actions (`markAsSold()`, `republish()`) over a free status dropdown.

---

### C4 — `createFromFrontend()` mass-assigns unfiltered input
**File:** `Modules/Listing/Models/Listing.php:35-40,555-569`

`$fillable` includes privileged fields (`status`, `is_featured`, `view_count`, `slug`, `user_id`), and the create method passes the entire `$data` array through to `create()`.

```555:569:Modules/Listing/Models/Listing.php
    public static function createFromFrontend(array $data, null|int|string $userId): self
    {
        // slug generation ...
        $payload = $data;
        $payload['user_id'] = $userId;
        $payload['currency'] = ListingPanelHelper::normalizeCurrency($data['currency'] ?? null);
        $payload['slug'] = $slug;

        return static::query()->create($payload);
    }
```

Any caller passing `is_featured`, `view_count`, or `status` gets them persisted.

**Fix:** Replace `$payload = $data` with an explicit `Arr::only($data, [...])` allowlist (same pattern as `updateFromPanel()`), and remove `view_count`/`status`/`is_featured` from `$fillable`.

---

### C5 — Queued `ProcessVideo` jobs carry no demo schema context
**Files:** `Modules/Video/Models/Video.php:430-432`, `Modules/Video/Jobs/ProcessVideo.php:24-40`

Demo DB isolation is per-request via PostgreSQL `search_path`, but the queued job only stores a numeric `videoId` and the worker runs against the default connection (`search_path=public`). Overlapping IDs between demo schemas and production mean a worker can transcode/delete the **wrong tenant's** video (worst case: a production video while a demo user uploaded the same ID).

```430:432:Modules/Video/Models/Video.php
        ProcessVideo::dispatch($this->getKey())
            ->onQueue((string) config('video.queue', 'videos'))
            ->afterCommit();
```

**Fix:** Pass the schema/UUID in the job payload and re-activate it at the start of `handle()`, or force the `sync` queue in demo mode.

---

### C6 — Shared S3/local paths are not demo-scoped (storage collision)
**Files:** `Modules/Video/Models/Video.php:337-344,98-102`, `Modules/Admin/Support/HomeSlideFormSchema.php:27`

Demo isolation is DB-only; file paths use numeric IDs (`videos/mobile/1/1-*.mp4`, `settings/`, `home-slides/`). Each demo schema re-seeds `listing_id=1`/`video_id=1`, so on shared storage demo uploads overwrite or read **production** files at the same keys.

**Fix:** Prefix all managed media paths with the demo UUID when a demo schema is active, and delete those prefixed objects during cleanup.

---

## HIGH

### H1 — Banned/suspended users can still authenticate
**Files:** `Modules/User/App/Http/Requests/LoginRequest.php:27-39`, `SocialAuthController.php:55,79-87`

`authenticate()` calls `Auth::attempt()` with no check on `User::$status`. `BannedUserStatus`/`SuspendedUserStatus` block panel access conceptually but never block login. Social login always creates/logs in users as `active`.
**Fix:** After successful auth (password or OAuth), reject non-active states and log out with a clear error. Consider a global `EnsureUserIsActive` middleware on `auth` routes.

### H2 — Duplicate `AdminPanelProvider` registration
**Files:** `bootstrap/providers.php:5`, `Modules/Admin/Providers/AdminServiceProvider.php:16`

The Filament panel provider is registered in both `bootstrap/providers.php` and via the module's `AdminServiceProvider` (loaded through `module.json`). This can double-register routes/middleware and cause boot errors.
**Fix:** Register it in exactly one place.

### H3 — Duplicate module migration loading
**Files:** `config/modules.php:92-95` (`auto-discover.migrations => true`) + every module provider's `loadMigrationsFrom()`

Both nwidart auto-discovery **and** manual `loadMigrationsFrom()` register the same paths, so a migration can run twice (“table already exists”).
**Fix:** Use auto-discovery **or** the manual calls — not both.

### H4 — `expires_at` is never enforced
**File:** `Modules/Listing/Models/Listing.php:98-101`

`scopeActive()` checks only the status column. No job/observer/scope ever transitions listings to `expired` when `expires_at` passes, so they stay in the public feed forever.
**Fix:** Add `->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))` to `scopeActive()` plus a scheduled command to flip status.

### H5 — State machine bypassed by raw `forceFill()` writes
**File:** `Modules/Listing/Models/Listing.php:515-527`

`ListingStatus` uses Spatie model-states, but `markAsSold()`/`republish()` write raw strings via `forceFill()`, sidestepping any transition guards (and combined with C3, the state machine provides no protection).
**Fix:** Use `$this->status->transitionTo(...)` and define explicit allowed transitions instead of `allowAllTransitions()`.

### H6 — Demo provisioning is an unauthenticated DoS vector
**Files:** `Modules/Demo/routes/web.php:7-9`, `Modules/Demo/App/Support/TurnstileVerifier.php:27-28`

`POST /demo/prepare` runs a full `migrate` + `DatabaseSeeder` into a new PostgreSQL schema, protected only by `throttle:8,1`. When `DEMO_TURNSTILE_ENABLED=0`, `verify()` always returns `true`, so an attacker can spawn many schemas/minute → CPU/DB exhaustion.
**Fix:** Require Turnstile in non-local environments, add a global concurrency cap, or pre-provision a schema pool.

### H7 — Hardcoded demo credentials committed to source
**File:** `Modules/User/App/Support/DemoUserCatalog.php:10-16`

Admin user `a@a.com` ships with plaintext password `236330`, seeded by `AuthUserSeeder` into every install/demo schema.
**Fix:** Pull demo passwords from env/config or generate them randomly at seed time.

### H8 — Social-only users cannot delete their account
**Files:** `Modules/User/App/Http/Controllers/ProfileController.php:45-47`, `delete-user-form.blade.php:58-66`

Account deletion requires `current_password`, but OAuth users only ever have a random password they never learn, so deletion always fails validation.
**Fix:** For users without a usable password, allow deletion via OAuth re-auth or an email-confirmation / “type DELETE” flow.

---

## MEDIUM

| # | File | Issue | Fix |
|---|------|-------|-----|
| M1 | `.env.example:59-60,92-93` | Real-looking AWS keys & Turnstile keys committed | Use empty/obviously-fake placeholders; rotate if ever real |
| M2 | `config/theme.php:3-7` | Stale orphan config from another project (`/Users/alp/...` macOS path, `active => minimal`) pollutes merged `theme` config | Delete the file or re-export the module config |
| M3 | `config/filesystems.php:4` | Default disk is `s3` but `.env.example` leaves `AWS_BUCKET` empty → fresh clone breaks uploads | Default to `public`/`local` in dev |
| M4 | `Listing.php:520-528` (`republish()`) | No status guard — any owner can extend `expires_at` on an already-active listing | Guard with `statusValue() === 'expired'` |
| M5 | `AdminListingResourceSchema.php:42` | Title `afterStateUpdated` regenerates random slug on every blur, breaking existing URLs on edit | Only auto-generate slug on create |
| M6 | `Listing/resources/views/themes/default/show.blade.php:55-56` | Uses undefined `$existingConversationId` (controller passes `$detailConversation`) — “open chat” always falsy | Use `$detailConversation?->id` |
| M7 | `Category.php:276-298` (`descendantAndSelfIds`) | Includes inactive subcategories in DB-path filters / related suggestions | Add `->where('is_active', true)` |
| M8 | `Location/Models/City.php:73-84` | `districtPayloads()` returns inactive districts | Add `->where('is_active', true)` |
| M9 | `Location/Models/Country.php:91-112` | `resolveLookup()` ignores `is_active`, serving deactivated countries | Filter active or add `$onlyActive=true` |
| M10 | `Category.php:303-314` (`breadcrumbTrail`) | Lazy `$current->parent` loop → N+1 beyond the 2-level preload | Eager-load full ancestor chain |
| M11 | `ListingController.php:87-89`, `HomeController.php:14-16` | Listing index & home page don't eager-load media → N+1 on `primaryImageData()` (~1 query/card) | Add `->with('media')` |
| M12 | `Admin/Filament/Resources/UserResource.php:45`, `Video/.../VideoTableSchema.php:27-39` | Filament tables show relation columns (`roles.name`, `listing.title`, `user.email`) without eager loading → N+1 | `modifyQueryUsing(fn ($q) => $q->with([...]))` |
| M13 | `ListingCustomField.php:91-106` + migration unique on `name` only | Seeding same field name for a 2nd category overwrites the first row's `category_id` | Composite unique `['name','category_id']` |
| M14 | `PanelController.php:35-37` | Status whitelist drops `active`/`pending` even though `forPanelStatus()` supports them and cards count them | Add `active`/`pending` to whitelist + tabs |
| M15 | `Conversation/.../ConversationController.php:120-133` (`start`) | No max-length/validation on the first message (unlike `send`'s `max:2000`) | Apply same validation rules |
| M16 | `Conversation.php:60-68` | “Important” inbox filter is identical to “unread” (no important flag exists) | Add `is_important` flag or remove the tab |
| M17 | `Conversation/routes/web.php:12-16` | No throttle on message send/start → spam/DoS | Add `throttle:30,1` |
| M18 | `User.php:197-203` + missing `notifications` migration | `unreadNotificationCount()` relies on a `notifications` table that has no migration; errors silently swallowed | Publish/run Laravel notifications migration |
| M19 | `VideoTranscoder.php:88-126` | `ffprobe` exit status never checked — a failed probe still uploads and marks the video `Ready` with null metadata | Throw if probe fails / JSON invalid |
| M20 | `Admin/Filament/Resources/CategoryResource.php:35` | A root category can select itself as parent (no `whereKeyNot`) | Exclude current record from options |
| M21 | `config/reverb.php:49` | `allowed_origins: ['*']` accepts WebSocket connections from any origin | Restrict to app domain(s) in prod |
| M22 | migrations: `2022_12_14_...settings`, `2026_03_03_...media` | Missing `down()` methods → can't cleanly roll back | Add `Schema::dropIfExists(...)` |

---

## LOW

| # | File | Issue |
|---|------|-------|
| L1 | `Listing.php:39` | `view_count` is mass-assignable → inflation; mutate only via `increment()` |
| L2 | `User.php:38` | `status` in `$fillable` is a privilege-escalation footgun for any future `update($request->all())` |
| L3 | `SocialAuthController.php:55` | Social login always uses “remember me” (`login($user, true)`) |
| L4 | `RegisterRequest.php:23`, `ResetPasswordController.php:24-28` | No `confirmed` rule on password → typos persisted |
| L5 | `Video.php:329` | `updateFromPanel()` defaults `is_active` to `false` when omitted (should preserve current) |
| L6 | `Favorite/.../FavoriteController.php:59-61` | Favorites older than 1 year silently hidden (`wherePivot('created_at', '>=', now()->subYear())`) |
| L7 | `ConversationController.php:128` | Starting a conversation auto-favorites the listing (surprising side effect) |

---

## Verified Clean (no issue found)

- **Broadcast channel auth** — `users.{id}.inbox` correctly checks `(int) $user->getKey() === (int) $id` in `ConversationServiceProvider`. `routes/channels.php` is intentionally empty.
- **Conversation/message IDOR** — participant checks return 403 on `send`/`read`; `FavoriteSearch` destroy enforces `user_id` ownership; panel mutations use `assertOwnedBy()`.
- **SQL injection** — search/filter scopes use Eloquent/bindings; demo schema names validated/quoted.
- **Command injection** — `VideoTranscoder` uses Symfony `Process` argv arrays (no shell interpolation of user paths).
- **`env()` outside config** — only used inside `config/` and module `config/` files (correct).
- **XSS** — Blade `{{ }}` escaping throughout; description uses `{!! nl2br(e(...)) !!}` (safe).
- **Migration FK ordering** — users → categories → listings → conversations/favorites/videos is correct.

---

## Recommended Fix Order

1. **C1, C3, C4** — auth/moderation/mass-assignment (direct security impact).
2. **C2** — gate `show()` to active listings (data exposure).
3. **H1, H7** — block non-active logins; remove hardcoded admin password.
4. **H2, H3** — deduplicate provider & migration registration (breaks fresh installs).
5. **C5, C6, H6** — required only if running a public demo with async queues + shared S3.
6. Medium N+1s (M10–M12) and the remaining items as cleanup.
