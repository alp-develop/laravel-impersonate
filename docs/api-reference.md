# API Reference

## Facade: `Impersonate`

```php
use AlpDevelop\LaravelImpersonate\Facades\Impersonate;
```

Proxies to `ImpersonateManager`. All methods are available statically.

### `start(Impersonatable $target, ?int $ttlMinutes = null): void`

Starts an impersonation session. Logs the authenticated user out and logs in as `$target`.

**Parameters:**

| Name | Type | Default | Description |
|------|------|---------|-------------|
| `$target` | `Impersonatable` | — | The user to impersonate |
| `$ttlMinutes` | `?int` | `null` | TTL in minutes. Falls back to `config('impersonate.default_ttl')` |

**Throws:**

- `CannotImpersonateException` — Session already active, self-impersonation, or target not impersonatable
- `UnauthorizedImpersonationException` — Impersonator lacks permission or Gate denied

```php
use AlpDevelop\LaravelImpersonate\Facades\Impersonate;

Impersonate::start($user);
Impersonate::start($user, ttl: 30);
```

### `stop(): void`

Stops the current impersonation session and restores the original user.

**Throws:**

- `CannotImpersonateException` — No active impersonation session

```php
Impersonate::stop();
```

### `purge(): void`

Force-clears the impersonation session. Does not throw if no session exists. Useful for recovering from corrupted state.

```php
Impersonate::purge();
```

### `isActive(): bool`

Returns `true` if an impersonation session is currently active.

```php
if (Impersonate::isActive()) {
    // ...
}
```

### `context(): ?ImpersonationContext`

Returns the current `ImpersonationContext` or `null`.

```php
$context = Impersonate::context();
$context->impersonatorId;   // int|string
$context->impersonatedId;   // int|string
$context->guard;            // string
$context->startedAt;        // CarbonImmutable
$context->expiresAt;        // ?CarbonImmutable
$context->ipAddress;        // ?string
```

### `impersonator(): ?Impersonatable`

Returns the original impersonator user model or `null`.

```php
$admin = Impersonate::impersonator();
```

---

## Contract: `Impersonatable`

```php
use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
```

### `canBeImpersonated(): bool`

Whether this user can be impersonated.

### `canImpersonate(Impersonatable $target): bool`

Whether this user can impersonate the given target.

### `getAuthIdentifier(): int|string`

Returns the unique identifier for the user. Already implemented by Laravel's `Authenticatable`.

---

## Trait: `HasImpersonation`

```php
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;
```

Default implementation of `Impersonatable`:

- `canBeImpersonated()` returns `true`
- `canImpersonate()` returns `true` unless target has the same ID

---

## Value Object: `ImpersonationContext`

```php
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
```

Immutable value object stored in the session.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `impersonatorId` | `int\|string` | Original user ID |
| `impersonatedId` | `int\|string` | Target user ID |
| `guard` | `string` | Auth guard name |
| `startedAt` | `CarbonImmutable` | When impersonation started |
| `expiresAt` | `?CarbonImmutable` | When the session expires (`null` = no expiration) |
| `ipAddress` | `?string` | IP address of the impersonator at start |

### `isExpired(): bool`

Returns `true` if `expiresAt` is in the past. Returns `false` if `expiresAt` is `null`.

### `toArray(): array`

Serializes to a versioned array for session storage.

### `static fromArray(array $data): self`

Deserializes from a versioned array.

**Throws:**

- `InvalidImpersonationContextException` — Version mismatch, missing structure, or missing required fields

---

## Enum: `ImpersonationStatus`

```php
use AlpDevelop\LaravelImpersonate\Enums\ImpersonationStatus;
```

Returned by `ValidateImpersonation::execute()`.

| Case | Meaning |
|------|---------|
| `Valid` | Session is active and all conditions met |
| `Expired` | TTL has passed |
| `ImpersonatorMissing` | Original user no longer exists |
| `TargetMissing` | Impersonated user no longer exists |
| `NoActiveImpersonation` | No session data found |

---

## Request Macros

Available after `HandleImpersonation` middleware runs:

```php
$request->isImpersonating(): bool
$request->impersonator(): int|string|null
$request->impersonation(): ?ImpersonationContext
```

---

## Blade Directives

```blade
@impersonating
    {{-- Shown during impersonation --}}
@else
    {{-- Shown normally --}}
@endimpersonating
```

---

## Middleware Aliases

| Alias | Class | Description |
|-------|-------|-------------|
| `forbid-impersonation` | `ForbidDuringImpersonation` | Block routes during impersonation |
