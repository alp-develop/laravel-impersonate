<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Actions;

use AlpDevelop\LaravelImpersonate\Enums\ImpersonationStatus;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use Illuminate\Support\Facades\Auth;

class ValidateImpersonation
{
    public function __construct(
        private readonly SessionStore $store,
    ) {}

    public function execute(): ImpersonationStatus
    {
        $context = $this->store->current();

        if ($context === null) {
            return ImpersonationStatus::NoActiveImpersonation;
        }

        if ($context->isExpired()) {
            return ImpersonationStatus::Expired;
        }

        $guardName = $context->guard;
        $configuredGuards = array_keys(config('auth.guards', []));

        if (! in_array($guardName, $configuredGuards, true)) {
            $this->store->clear();

            return ImpersonationStatus::ImpersonatorMissing;
        }

        $provider = Auth::guard($guardName)->getProvider();

        if ($provider->retrieveById($context->impersonatorId) === null) {
            return ImpersonationStatus::ImpersonatorMissing;
        }

        if ($provider->retrieveById($context->impersonatedId) === null) {
            return ImpersonationStatus::TargetMissing;
        }

        return ImpersonationStatus::Valid;
    }
}
