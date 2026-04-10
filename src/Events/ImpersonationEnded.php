<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Events;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;

class ImpersonationEnded
{
    public function __construct(
        public readonly ImpersonationContext $context,
        public readonly ?Impersonatable $restoredUser,
    ) {}
}
