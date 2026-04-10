# Middleware

## HandleImpersonation

Validates the impersonation session on every request and handles expired or invalid sessions automatically.

### Registration

```php
// Laravel 11+ (bootstrap/app.php)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \AlpDevelop\LaravelImpersonate\Middleware\HandleImpersonation::class,
    ]);
})
```

```php
// Laravel 10 (app/Http/Kernel.php)
protected $middlewareGroups = [
    'web' => [
        // ...
        \AlpDevelop\LaravelImpersonate\Middleware\HandleImpersonation::class,
    ],
];
```

### Behavior

On each request, the middleware runs `ValidateImpersonation` which returns an `ImpersonationStatus` enum:

| Status | Action |
|--------|--------|
| `Valid` | Injects `ImpersonationContext` into `$request->attributes` under keys `impersonation` and `impersonator_id` |
| `Expired` | Dispatches `ImpersonationExpired` event, then purges the session |
| `ImpersonatorMissing` | Purges the session (original user was deleted) |
| `TargetMissing` | Purges the session (impersonated user was deleted) |
| `NoActiveImpersonation` | No action |

When a session is purged, the original user is restored if possible. If the original user no longer exists, the session is logged out entirely.

### Accessing Context in Controllers

After the middleware runs on a valid session:

```php
public function index(Request $request)
{
    if ($request->isImpersonating()) {
        $impersonatorId = $request->impersonator();
        $context = $request->impersonation();
    }
}
```

## ForbidDuringImpersonation

Blocks access to specific routes while impersonation is active. Useful for protecting sensitive areas like user management, billing, or security settings.

### Registration

Registered automatically as `forbid-impersonation` middleware alias.

### Usage

```php
// Returns 403 Forbidden
Route::middleware('forbid-impersonation')->group(function () {
    Route::get('/admin/security', SecurityController::class);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});
```

```php
// Redirect to a specific URL instead of 403
Route::middleware('forbid-impersonation:/dashboard')->group(function () {
    Route::get('/billing', BillingController::class);
});
```

### Behavior

The middleware checks `$request->attributes->has('impersonation')`. This attribute is set by `HandleImpersonation`, so `ForbidDuringImpersonation` must run **after** `HandleImpersonation` in the middleware stack.

| Condition | With redirect | Without redirect |
|-----------|--------------|-----------------|
| Impersonation active | Redirect to given path | Abort 403 |
| No impersonation | Pass through | Pass through |
