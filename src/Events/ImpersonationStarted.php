<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Events;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;

class ImpersonationStarted
{
    public function __construct(
        public readonly ImpersonationContext $context,
        public readonly Impersonatable $impersonator,
        public readonly Impersonatable $target,
    ) {}
}
