<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Unit\Traits;

use AlpDevelop\LaravelImpersonate\Tests\TestCase;
use AlpDevelop\LaravelImpersonate\Tests\User;

class HasImpersonationTest extends TestCase
{
    public function test_can_be_impersonated_returns_true_by_default(): void
    {
        $user = new User(['id' => 1]);

        $this->assertTrue($user->canBeImpersonated());
    }

    public function test_can_impersonate_different_user(): void
    {
        $admin = new User(['id' => 1]);
        $target = new User(['id' => 2]);

        $this->assertTrue($admin->canImpersonate($target));
    }

    public function test_cannot_impersonate_self(): void
    {
        $user = new User(['id' => 1]);
        $same = new User(['id' => 1]);

        $this->assertFalse($user->canImpersonate($same));
    }
}
