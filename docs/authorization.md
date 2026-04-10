# Authorization

Impersonation is protected by three layers of authorization, evaluated in order. All three must pass for impersonation to proceed.

## Layer 1: Target Contract

The target user must allow being impersonated via `canBeImpersonated()`:

```php
use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;

class User extends Authenticatable implements Impersonatable
{
    use HasImpersonation;

    public function canBeImpersonated(): bool
    {
        return ! $this->is_super_admin;
    }
}
```

The default implementation in `HasImpersonation` returns `true`.

If this check fails, a `CannotImpersonateException` is thrown and an `ImpersonationDenied` event is dispatched with reason `"Target cannot be impersonated."`.

## Layer 2: Impersonator Contract

The impersonator must be allowed to impersonate the specific target via `canImpersonate()`:

```php
public function canImpersonate(Impersonatable $target): bool
{
    return $this->is_admin && $this->getAuthIdentifier() !== $target->getAuthIdentifier();
}
```

The default implementation in `HasImpersonation` only prevents self-impersonation (comparing `getAuthIdentifier()`).

If this check fails, an `UnauthorizedImpersonationException` is thrown and an `ImpersonationDenied` event is dispatched with reason `"Impersonator lacks permission."`.

## Layer 3: Gate

The Laravel Gate `impersonate` is the final check:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('impersonate', function ($user, $target) {
    return $user->hasRole('admin');
});
```

The package defines a default Gate that returns `false`. You **must** override it to enable impersonation.

If this check fails, an `UnauthorizedImpersonationException` is thrown and an `ImpersonationDenied` event is dispatched with reason `"Gate authorization failed."`.

## Pre-Authorization Checks

Before the three authorization layers are evaluated, two hard checks run:

1. **Active session**: If an impersonation session is already active, a `CannotImpersonateException` is thrown immediately.
2. **Self-impersonation**: If the impersonator and target share the same `getAuthIdentifier()` and class, a `CannotImpersonateException` is thrown.

## Validation Order

```
1. Already active?        → CannotImpersonateException
2. Self-impersonation?    → CannotImpersonateException
3. canBeImpersonated()?   → CannotImpersonateException + ImpersonationDenied event
4. canImpersonate()?      → UnauthorizedImpersonationException + ImpersonationDenied event
5. Gate::inspect()?       → UnauthorizedImpersonationException + ImpersonationDenied event
```

## Example: Role-Based Authorization

```php
use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;

class User extends Authenticatable implements Impersonatable
{
    use HasImpersonation;

    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('super-admin');
    }

    public function canImpersonate(Impersonatable $target): bool
    {
        if ($this->getAuthIdentifier() === $target->getAuthIdentifier()) {
            return false;
        }

        return $this->hasRole('admin') || $this->hasRole('support');
    }
}
```

Gate in `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('impersonate', function ($user, $target) {
    if ($user->hasRole('super-admin')) {
        return true;
    }

    if ($user->hasRole('admin') && ! $target->hasRole('admin')) {
        return true;
    }

    return false;
});
```
