<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface Impersonatable extends Authenticatable
{
    public function canBeImpersonated(): bool;

    public function canImpersonate(Impersonatable $target): bool;
}
