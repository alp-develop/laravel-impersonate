<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Actions;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationPurged;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

class PurgeImpersonation
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly Store $session,
    ) {}

    public function execute(): void
    {
        $context = $this->store->current();

        $originalUser = null;
        $guard = $context !== null ? $context->guard : config('impersonate.guard', 'web');
        $configuredGuards = array_keys(config('auth.guards', []));

        if (! in_array($guard, $configuredGuards, true)) {
            $guard = config('impersonate.guard', 'web');
        }

        if ($context !== null) {
            $provider = Auth::guard($guard)->getProvider();
            $resolved = $provider->retrieveById($context->impersonatorId);

            if ($resolved instanceof Impersonatable) {
                $originalUser = $resolved;
                Auth::guard($guard)->login($originalUser);
            } else {
                Auth::guard($guard)->logout();
            }
        } else {
            Auth::guard($guard)->logout();
        }

        $this->session->regenerate();

        $this->store->clear();

        event(new ImpersonationPurged($context, $originalUser));
    }
}
