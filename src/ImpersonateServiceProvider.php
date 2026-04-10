<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Middleware\ForbidDuringImpersonation;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ImpersonateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/impersonate.php', 'impersonate');

        $this->app->singleton(SessionStore::class, function ($app) {
            return new SessionStore($app['session.store']);
        });

        $this->app->singleton(ImpersonateManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/impersonate.php' => config_path('impersonate.php'),
        ], 'impersonate-config');

        $this->registerGate();
        $this->registerBladeDirectives();
        $this->registerRequestMacros();
        $this->registerMiddlewareAlias();
    }

    private function registerGate(): void
    {
        Gate::define('impersonate', function () {
            return false;
        });
    }

    private function registerBladeDirectives(): void
    {
        Blade::if('impersonating', function () {
            return app(SessionStore::class)->isActive();
        });

        Blade::if('canImpersonate', function (?Impersonatable $target = null) {
            $guard = config('impersonate.guard', 'web');
            $user = Auth::guard($guard)->user();

            if (! $user instanceof Impersonatable) {
                return false;
            }

            if (app(SessionStore::class)->isActive()) {
                return false;
            }

            if ($target !== null) {
                return $user->canImpersonate($target)
                    && $target->canBeImpersonated()
                    && Gate::allows('impersonate', $target);
            }

            return true;
        });
    }

    private function registerRequestMacros(): void
    {
        Request::macro('isImpersonating', function (): bool {
            return $this->attributes->has('impersonation');
        });

        Request::macro('impersonator', function (): int|string|null {
            return $this->attributes->get('impersonator_id');
        });

        Request::macro('impersonation', function (): ?ImpersonationContext {
            return $this->attributes->get('impersonation');
        });
    }

    private function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('forbid-impersonation', ForbidDuringImpersonation::class);
    }
}
