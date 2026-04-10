<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate;

use AlpDevelop\LaravelImpersonate\Actions\PurgeImpersonation;
use AlpDevelop\LaravelImpersonate\Actions\StartImpersonation;
use AlpDevelop\LaravelImpersonate\Actions\StopImpersonation;
use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Exceptions\UnauthorizedImpersonationException;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Illuminate\Support\Facades\Auth;

class ImpersonateManager
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly StartImpersonation $startAction,
        private readonly StopImpersonation $stopAction,
        private readonly PurgeImpersonation $purgeAction,
    ) {}

    public function start(Impersonatable $target, ?int $ttlMinutes = null): void
    {
        $impersonator = Auth::guard(config('impersonate.guard', 'web'))->user();

        if (! $impersonator instanceof Impersonatable) {
            throw new UnauthorizedImpersonationException(
                'Authenticated user must implement Impersonatable.'
            );
        }

        $this->startAction->execute($impersonator, $target, $ttlMinutes);
    }

    public function stop(): void
    {
        $this->stopAction->execute();
    }

    public function purge(): void
    {
        $this->purgeAction->execute();
    }

    public function isActive(): bool
    {
        return $this->store->isActive();
    }

    public function context(): ?ImpersonationContext
    {
        return $this->store->current();
    }

    public function impersonator(): ?Impersonatable
    {
        $context = $this->store->current();

        if ($context === null) {
            return null;
        }

        $configuredGuards = array_keys(config('auth.guards', []));

        if (! in_array($context->guard, $configuredGuards, true)) {
            return null;
        }

        $provider = Auth::guard($context->guard)->getProvider();
        $user = $provider->retrieveById($context->impersonatorId);

        return $user instanceof Impersonatable ? $user : null;
    }
}
