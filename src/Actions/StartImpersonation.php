<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Actions;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationDenied;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationStarted;
use AlpDevelop\LaravelImpersonate\Exceptions\CannotImpersonateException;
use AlpDevelop\LaravelImpersonate\Exceptions\UnauthorizedImpersonationException;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class StartImpersonation
{
    public function __construct(
        private readonly SessionStore $store,
        private readonly Store $session,
    ) {}

    public function execute(Impersonatable $impersonator, Impersonatable $target, ?int $ttlMinutes = null): void
    {
        if ($this->store->isActive()) {
            throw new CannotImpersonateException('An impersonation session is already active.');
        }

        if ($impersonator->getAuthIdentifier() === $target->getAuthIdentifier()
            && get_class($impersonator) === get_class($target)) {
            throw new CannotImpersonateException('Cannot impersonate yourself.');
        }

        if (! $target->canBeImpersonated()) {
            event(new ImpersonationDenied($impersonator, $target, 'Target cannot be impersonated.'));

            throw new CannotImpersonateException('Target user cannot be impersonated.');
        }

        if (! $impersonator->canImpersonate($target)) {
            event(new ImpersonationDenied($impersonator, $target, 'Impersonator lacks permission.'));

            throw new UnauthorizedImpersonationException('You are not authorized to impersonate this user.');
        }

        $response = Gate::inspect('impersonate', $target);

        if ($response->denied()) {
            event(new ImpersonationDenied($impersonator, $target, 'Gate authorization failed.'));

            throw new UnauthorizedImpersonationException(
                $response->message() ?? 'Gate authorization failed.'
            );
        }

        if (config('impersonate.prevent_privilege_escalation', true)) {
            $impersonatorHasLevel = method_exists($impersonator, 'getImpersonationPrivilegeLevel');
            $targetHasLevel = method_exists($target, 'getImpersonationPrivilegeLevel');

            if ($impersonatorHasLevel || $targetHasLevel) {
                $impersonatorLevel = $impersonatorHasLevel ? (int) $impersonator->getImpersonationPrivilegeLevel() : 0;
                $targetLevel = $targetHasLevel ? (int) $target->getImpersonationPrivilegeLevel() : 0;

                if ($targetLevel >= $impersonatorLevel) {
                    event(new ImpersonationDenied($impersonator, $target, 'Privilege escalation blocked.'));

                    throw new UnauthorizedImpersonationException(
                        'Cannot impersonate a user with equal or higher privilege level.'
                    );
                }
            }
        }

        $guard = config('impersonate.guard', 'web');
        $ttl = $ttlMinutes ?? config('impersonate.default_ttl');

        if ($ttl !== null && $ttl < 1) {
            throw new CannotImpersonateException('TTL must be at least 1 minute.');
        }

        $context = new ImpersonationContext(
            impersonatorId: $impersonator->getAuthIdentifier(),
            impersonatedId: $target->getAuthIdentifier(),
            guard: $guard,
            startedAt: CarbonImmutable::now(),
            expiresAt: $ttl !== null ? CarbonImmutable::now()->addMinutes($ttl) : null,
            ipAddress: request()->ip(),
        );

        Auth::guard($guard)->login($target);

        $this->session->regenerate();

        $this->store->start($context);

        event(new ImpersonationStarted($context, $impersonator, $target));
    }
}
