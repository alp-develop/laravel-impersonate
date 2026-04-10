# Laravel Impersonate

<p align="center">
<a href="https://packagist.org/packages/alp-develop/laravel-impersonate"><img src="https://img.shields.io/github/v/tag/alp-develop/laravel-impersonate?label=version&style=flat-square&sort=semver" alt="Version"></a>
<a href="https://github.com/alp-develop/laravel-impersonate/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/alp-develop/laravel-impersonate/tests.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
<a href="https://github.com/alp-develop/laravel-impersonate/blob/main/LICENSE"><img src="https://img.shields.io/github/license/alp-develop/laravel-impersonate?style=flat-square" alt="License"></a>
</p>

Secure user impersonation for Laravel applications. Built with a session-based architecture, versioned context, automatic validation middleware, and a full event system.

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x , 12.x or 13.x

## Installation

```bash
composer require alp-develop/laravel-impersonate
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=impersonate-config
```

## Quick Start

### 1. Implement the contract on your User model

```php
use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;

class User extends Authenticatable implements Impersonatable
{
    use HasImpersonation;
}
```

### 2. Define a Gate policy

The package registers an `impersonate` gate that returns `false` by default. Override it in your `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('impersonate', function ($user, $target) {
    return $user->is_admin;
});
```

### 3. Start and stop impersonation

```php
use AlpDevelop\LaravelImpersonate\Facades\Impersonate;

Impersonate::start($targetUser);
Impersonate::start($targetUser, ttl: 30);
Impersonate::stop();
```

## Documentation

| Section | Description |
|---------|-------------|
| [Configuration](docs/configuration.md) | All config options with types, defaults and examples |
| [Authorization](docs/authorization.md) | Three-layer authorization model, validation order, role-based examples |
| [Middleware](docs/middleware.md) | HandleImpersonation and ForbidDuringImpersonation setup and behavior |
| [Events](docs/events.md) | All 5 events with payloads, listener examples, and flow diagram |
| [API Reference](docs/api-reference.md) | Full reference: Facade, Contract, Trait, Value Object, Enum, Macros, Blade directives |
| [Security](docs/security.md) | Session regeneration, versioned context, automatic validation, IP tracking, recommendations |

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## License

MIT License. See [LICENSE](LICENSE) for details.
