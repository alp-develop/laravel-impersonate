# Security

## Architecture

The package uses session-based impersonation with multiple security layers.

## Session Security

### Session Regeneration

The session ID is regenerated on every impersonation lifecycle event:

- `start()` — Prevents the target from reusing the impersonator's session ID
- `stop()` — Prevents the impersonated session from persisting after restoration
- `purge()` — Ensures clean session state after forced cleanup

This prevents session fixation attacks where an attacker could hijack a session ID across user contexts.

### Versioned Context

The `ImpersonationContext` includes a version number (`v: 1`). When the internal format changes in a future release, sessions with outdated versions are automatically invalidated and purged. This prevents deserialization issues and potential data corruption.

### Session Key Isolation

The impersonation context is stored under a configurable session key (`impersonation_context` by default), separate from Laravel's authentication state. This prevents conflicts with other session data.

## Authorization

### Three-Layer Authorization

Every impersonation attempt passes through three authorization checks:

1. **Target contract** (`canBeImpersonated()`) — Can this user be impersonated?
2. **Impersonator contract** (`canImpersonate()`) — Can this user impersonate the target?
3. **Laravel Gate** (`impersonate`) — Application-level authorization policy

All three must pass. The Gate defaults to `false`, so impersonation is denied until explicitly enabled.

### Self-Impersonation Prevention

The package compares both `getAuthIdentifier()` and `get_class()` to prevent self-impersonation, even across different model types that might share the same ID value.

## Automatic Validation

The `HandleImpersonation` middleware validates the session on every request:

- **Expired sessions** are purged with an `ImpersonationExpired` event
- **Missing impersonator** (deleted user) triggers automatic purge
- **Missing target** (deleted user) triggers automatic purge
- **Corrupted context** (invalid serialization) is silently cleared

This ensures that impersonation sessions cannot persist in invalid states.

## IP Address Tracking

The impersonator's IP address is recorded in `ImpersonationContext::ipAddress` at the start of impersonation. This provides an audit trail for security teams.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationStarted;

Event::listen(ImpersonationStarted::class, function (ImpersonationStarted $event) {
    SecurityLog::record($event->context->ipAddress);
});
```

## Event-Based Auditing

All impersonation lifecycle events are dispatchable, enabling comprehensive audit logging:

| Event | When |
|-------|------|
| `ImpersonationStarted` | Successful impersonation |
| `ImpersonationEnded` | Normal stop |
| `ImpersonationDenied` | Authorization failed (with reason) |
| `ImpersonationPurged` | Forced cleanup |
| `ImpersonationExpired` | TTL expired |

## Route Protection

Use `ForbidDuringImpersonation` middleware to protect sensitive routes:

```php
Route::middleware('forbid-impersonation')->group(function () {
    Route::delete('/account', [AccountController::class, 'destroy']);
    Route::put('/account/password', [PasswordController::class, 'update']);
    Route::post('/billing', [BillingController::class, 'store']);
});
```

## Recommendations

1. **Always define a Gate policy** — The default Gate denies all impersonation. Define specific rules.
2. **Set a TTL** — Use `default_ttl` or per-call TTL to prevent forgotten impersonation sessions.
3. **Listen to denied events** — Monitor `ImpersonationDenied` for potential abuse.
4. **Protect destructive routes** — Apply `forbid-impersonation` to routes that modify user data, billing, or security settings.
5. **Audit all events** — Log `ImpersonationStarted`, `ImpersonationEnded`, and `ImpersonationDenied` for compliance.
6. **Restrict by role** — Use `canImpersonate()` and the Gate to limit who can impersonate and who can be impersonated.
