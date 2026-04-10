<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Middleware;

use AlpDevelop\LaravelImpersonate\Actions\PurgeImpersonation;
use AlpDevelop\LaravelImpersonate\Actions\ValidateImpersonation;
use AlpDevelop\LaravelImpersonate\Enums\ImpersonationStatus;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationExpired;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleImpersonation
{
    public function __construct(
        private readonly ValidateImpersonation $validator,
        private readonly PurgeImpersonation $purge,
        private readonly SessionStore $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->validator->execute();

        match ($status) {
            ImpersonationStatus::Valid => $this->injectContext($request),
            ImpersonationStatus::Expired => $this->handleExpired(),
            ImpersonationStatus::ImpersonatorMissing,
            ImpersonationStatus::TargetMissing => $this->purge->execute(),
            ImpersonationStatus::NoActiveImpersonation => null,
        };

        return $next($request);
    }

    private function injectContext(Request $request): void
    {
        $context = $this->store->current();

        if ($context === null) {
            return;
        }

        $request->attributes->set('impersonation', $context);
        $request->attributes->set('impersonator_id', $context->impersonatorId);
    }

    private function handleExpired(): void
    {
        $context = $this->store->current();

        if ($context !== null) {
            event(new ImpersonationExpired($context));
        }

        $this->purge->execute();
    }
}
