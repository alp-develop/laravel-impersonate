<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Events;

use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;

class ImpersonationExpired
{
    public function __construct(
        public readonly ImpersonationContext $context,
    ) {}
}
