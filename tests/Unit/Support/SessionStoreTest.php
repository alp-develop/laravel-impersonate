<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Unit\Support;

use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use AlpDevelop\LaravelImpersonate\Tests\TestCase;
use Carbon\CarbonImmutable;

class SessionStoreTest extends TestCase
{
    private SessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SessionStore::class);
    }

    public function test_is_active_returns_false_when_empty(): void
    {
        $this->assertFalse($this->store->isActive());
    }

    public function test_start_stores_context(): void
    {
        $context = $this->makeContext();

        $this->store->start($context);

        $this->assertTrue($this->store->isActive());
    }

    public function test_current_returns_stored_context(): void
    {
        $context = $this->makeContext();

        $this->store->start($context);

        $current = $this->store->current();

        $this->assertNotNull($current);
        $this->assertSame($context->impersonatorId, $current->impersonatorId);
        $this->assertSame($context->impersonatedId, $current->impersonatedId);
    }

    public function test_stop_returns_context_and_clears(): void
    {
        $this->store->start($this->makeContext());

        $stopped = $this->store->stop();

        $this->assertNotNull($stopped);
        $this->assertFalse($this->store->isActive());
    }

    public function test_stop_returns_null_when_empty(): void
    {
        $this->assertNull($this->store->stop());
    }

    public function test_clear_removes_context(): void
    {
        $this->store->start($this->makeContext());

        $this->store->clear();

        $this->assertFalse($this->store->isActive());
        $this->assertNull($this->store->current());
    }

    public function test_current_clears_corrupted_data(): void
    {
        session()->put(config('impersonate.session_key'), 'not-an-array');

        $this->assertNull($this->store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_current_clears_invalid_version(): void
    {
        session()->put(config('impersonate.session_key'), ['v' => 999, 'data' => []]);

        $this->assertNull($this->store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    private function makeContext(): ImpersonationContext
    {
        return new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::now(),
        );
    }
}
