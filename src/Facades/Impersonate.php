<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Facades;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\ImpersonateManager;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void start(Impersonatable $target, ?int $ttlMinutes = null)
 * @method static void stop()
 * @method static void purge()
 * @method static bool isActive()
 * @method static ?ImpersonationContext context()
 * @method static ?Impersonatable impersonator()
 */
class Impersonate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ImpersonateManager::class;
    }
}
