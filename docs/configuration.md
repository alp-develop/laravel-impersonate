# Configuration

## Publishing

```bash
php artisan vendor:publish --tag=impersonate-config
```

This creates `config/impersonate.php`:

```php
return [
    'guard' => 'web',
    'session_key' => 'impersonation_context',
    'default_ttl' => null,
    'prevent_privilege_escalation' => true,
    'redirect_after' => [
        'start' => '/',
        'stop' => '/',
    ],
];
```

## Options

### `guard`

- **Type**: `string`
- **Default**: `'web'`

The authentication guard used for login/logout operations during impersonation. Must match the guard your application uses for web authentication.

```php
'guard' => 'admin',
```

### `session_key`

- **Type**: `string`
- **Default**: `'impersonation_context'`

The key under which the serialized `ImpersonationContext` is stored in the session. Change this only if it conflicts with another package.

```php
'session_key' => 'my_impersonation_key',
```

### `default_ttl`

- **Type**: `?int`
- **Default**: `null`

Default time-to-live in minutes for impersonation sessions. When set, all impersonation sessions expire after this duration unless overridden per-call. A value of `null` means sessions do not expire automatically.

```php
// All sessions expire after 60 minutes by default
'default_ttl' => 60,
```

Per-call override:

```php
use AlpDevelop\LaravelImpersonate\Facades\Impersonate;

// Override: 15-minute TTL
Impersonate::start($user, ttl: 15);

// Override: no expiration (even if default_ttl is set)
// Not possible — pass null or omit the parameter, which uses default_ttl
```

### `prevent_privilege_escalation`

- **Type**: `bool`
- **Default**: `true`

When enabled, the authorization layer should prevent impersonating users with higher privilege levels than the impersonator. Implement the logic in your Gate or `canImpersonate()` method.

### `redirect_after.start`

- **Type**: `string`
- **Default**: `'/'`

The path to redirect to after starting impersonation after impersonation.

```php
'redirect_after' => [
    'start' => '/dashboard',
],
```

### `redirect_after.stop`

- **Type**: `string`
- **Default**: `'/'`

The path to redirect to after stopping impersonation after impersonation.

```php
'redirect_after' => [
    'stop' => '/admin/users',
],
```

## Publishing Views

```bash
php artisan vendor:publish --tag=impersonate-views
```

Published views are placed in `resources/views/vendor/impersonate/`. You can customize the banner and button components from there.
