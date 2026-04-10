<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Traits;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;

trait HasImpersonation
{
    public function canBeImpersonated(): bool
    {
        return true;
    }

    public function canImpersonate(Impersonatable $target): bool
    {
        return $this->getAuthIdentifier() !== $target->getAuthIdentifier();
    }
}
