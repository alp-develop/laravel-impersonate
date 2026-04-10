<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Unit\Support;

use AlpDevelop\LaravelImpersonate\Exceptions\InvalidImpersonationContextException;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ImpersonationContextTest extends TestCase
{
    public function test_to_array_includes_version(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2024-01-01 12:00:00'),
        );

        $array = $context->toArray();

        $this->assertSame(1, $array['v']);
        $this->assertArrayHasKey('data', $array);
        $this->assertSame(1, $array['data']['impersonator_id']);
        $this->assertSame(2, $array['data']['impersonated_id']);
        $this->assertSame('web', $array['data']['guard']);
    }

    public function test_from_array_roundtrip(): void
    {
        $original = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2024-01-01 12:00:00'),
            expiresAt: CarbonImmutable::parse('2024-01-01 12:30:00'),
            ipAddress: '127.0.0.1',
        );

        $restored = ImpersonationContext::fromArray($original->toArray());

        $this->assertSame($original->impersonatorId, $restored->impersonatorId);
        $this->assertSame($original->impersonatedId, $restored->impersonatedId);
        $this->assertSame($original->guard, $restored->guard);
        $this->assertSame($original->ipAddress, $restored->ipAddress);
        $this->assertTrue($original->startedAt->eq($restored->startedAt));
        $this->assertTrue($original->expiresAt->eq($restored->expiresAt));
    }

    public function test_from_array_throws_on_version_mismatch(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);

        ImpersonationContext::fromArray(['v' => 999, 'data' => []]);
    }

    public function test_from_array_throws_on_missing_data(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);

        ImpersonationContext::fromArray(['v' => 1]);
    }

    public function test_from_array_throws_on_missing_required_fields(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);

        ImpersonationContext::fromArray(['v' => 1, 'data' => ['impersonator_id' => 1]]);
    }

    public function test_is_expired_returns_false_when_no_expiration(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::now(),
        );

        $this->assertFalse($context->isExpired());
    }

    public function test_is_expired_returns_true_when_past(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::now()->subHour(),
            expiresAt: CarbonImmutable::now()->subMinute(),
        );

        $this->assertTrue($context->isExpired());
    }

    public function test_is_expired_returns_false_when_future(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::now(),
            expiresAt: CarbonImmutable::now()->addHour(),
        );

        $this->assertFalse($context->isExpired());
    }
}
