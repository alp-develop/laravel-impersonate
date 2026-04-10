<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Actions;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationEnded;
use AlpDevelop\LaravelImpersonate\Exceptions\CannotImpersonateException;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;

class StopImpersonation
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly Store $session,
    ) {}

    public function execute(): void
    {
        $context = $this->store->current();

        if ($context === null) {
            throw new CannotImpersonateException('No active impersonation to stop.');
        }

        $guard = $context->guard;
        $configuredGuards = array_keys(config('auth.guards', []));

        if (! in_array($guard, $configuredGuards, true)) {
            $this->store->clear();
            $this->session->regenerate();
            Auth::guard(config('impersonate.guard', 'web'))->logout();

            throw new CannotImpersonateException('Invalid guard in impersonation context.');
        }

        $provider = Auth::guard($guard)->getProvider();
        $originalUser = $provider->retrieveById($context->impersonatorId);

        if ($originalUser instanceof Impersonatable) {
            Auth::guard($guard)->login($originalUser);
        } else {
            Auth::guard($guard)->logout();
        }

        $this->session->regenerate();

        $this->store->clear();

        event(new ImpersonationEnded(
            $context,
            $originalUser instanceof Impersonatable ? $originalUser : null,
        ));
    }
}
