# Events

All events are dispatched using Laravel's `event()` helper. They can be intercepted with `Event::listen()`, in your `EventServiceProvider`, or via listener classes.

All event classes are in the `AlpDevelop\LaravelImpersonate\Events` namespace.

## ImpersonationStarted

Dispatched after impersonation begins successfully.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationStarted;

class ImpersonationStarted
{
    public function __construct(
        public readonly ImpersonationContext $context,
        public readonly Impersonatable $impersonator,
        public readonly Impersonatable $target,
    ) {}
}
```

**Example listener:**

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationStarted;

Event::listen(ImpersonationStarted::class, function (ImpersonationStarted $event) {
    AuditLog::create([
        'action' => 'impersonation.started',
        'impersonator_id' => $event->context->impersonatorId,
        'target_id' => $event->context->impersonatedId,
        'ip_address' => $event->context->ipAddress,
        'expires_at' => $event->context->expiresAt,
    ]);
});
```

## ImpersonationEnded

Dispatched after impersonation is stopped normally via `Impersonate::stop()`.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationEnded;

class ImpersonationEnded
{
    public function __construct(
        public readonly ImpersonationContext $context,
        public readonly ?Impersonatable $restoredUser,
    ) {}
}
```

`$restoredUser` is `null` if the original impersonator could not be found in the database.

## ImpersonationDenied

Dispatched when an impersonation attempt fails an authorization check.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationDenied;

class ImpersonationDenied
{
    public function __construct(
        public readonly Impersonatable $impersonator,
        public readonly Impersonatable $target,
        public readonly string $reason,
    ) {}
}
```

Possible `$reason` values:

| Reason | Authorization layer |
|--------|-------------------|
| `"Target cannot be impersonated."` | `canBeImpersonated()` returned `false` |
| `"Impersonator lacks permission."` | `canImpersonate()` returned `false` |
| `"Gate authorization failed."` | Gate `impersonate` denied |

**Example listener:**

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationDenied;

Event::listen(ImpersonationDenied::class, function (ImpersonationDenied $event) {
    Log::warning('Impersonation denied', [
        'impersonator' => $event->impersonator->getAuthIdentifier(),
        'target' => $event->target->getAuthIdentifier(),
        'reason' => $event->reason,
    ]);
});
```

## ImpersonationPurged

Dispatched after a session is force-cleared via `Impersonate::purge()` or by the middleware on invalid state.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationPurged;

class ImpersonationPurged
{
    public function __construct(
        public readonly ?ImpersonationContext $context,
        public readonly ?Impersonatable $originalUser,
    ) {}
}
```

Both `$context` and `$originalUser` may be `null` if the session was corrupted before the purge.

## ImpersonationExpired

Dispatched when `HandleImpersonation` middleware detects an expired TTL, immediately before purging the session.

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationExpired;

class ImpersonationExpired
{
    public function __construct(
        public readonly ImpersonationContext $context,
    ) {}
}
```

**Example listener:**

```php
use AlpDevelop\LaravelImpersonate\Events\ImpersonationExpired;

Event::listen(ImpersonationExpired::class, function (ImpersonationExpired $event) {
    Log::info('Impersonation expired', [
        'impersonator' => $event->context->impersonatorId,
        'target' => $event->context->impersonatedId,
        'started_at' => $event->context->startedAt,
        'expired_at' => $event->context->expiresAt,
    ]);
});
```

## Event Flow Diagram

```
start() → [auth checks] → ImpersonationStarted
start() → [auth fails]  → ImpersonationDenied
stop()                   → ImpersonationEnded
purge()                  → ImpersonationPurged
middleware (expired)     → ImpersonationExpired → ImpersonationPurged
middleware (missing)     → ImpersonationPurged
```
