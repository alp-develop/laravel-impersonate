<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Events;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;

class ImpersonationDenied
{
    public function __construct(
        public readonly Impersonatable $impersonator,
        public readonly Impersonatable $target,
        public readonly string $reason,
    ) {}
}
