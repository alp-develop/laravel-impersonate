<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Feature;

use AlpDevelop\LaravelImpersonate\Events\ImpersonationDenied;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationEnded;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationPurged;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationStarted;
use AlpDevelop\LaravelImpersonate\Exceptions\CannotImpersonateException;
use AlpDevelop\LaravelImpersonate\Exceptions\UnauthorizedImpersonationException;
use AlpDevelop\LaravelImpersonate\ImpersonateManager;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Tests\TestCase;
use AlpDevelop\LaravelImpersonate\Tests\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class ImpersonateManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection('testing')->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function test_start_impersonation(): void
    {
        Event::fake();

        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);

        $this->assertTrue($manager->isActive());
        $this->assertSame($target->id, Auth::id());

        Event::assertDispatched(ImpersonationStarted::class);
    }

    public function test_stop_impersonation(): void
    {
        Event::fake();

        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);
        $manager->stop();

        $this->assertFalse($manager->isActive());
        $this->assertSame($admin->id, Auth::id());

        Event::assertDispatched(ImpersonationEnded::class);
    }

    public function test_start_fails_when_already_active(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);

        $this->expectException(CannotImpersonateException::class);

        $manager->start($target);
    }

    public function test_start_fails_when_gate_denies(): void
    {
        Event::fake();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $this->expectException(UnauthorizedImpersonationException::class);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);
    }

    public function test_purge_clears_state(): void
    {
        Event::fake();

        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);
        $manager->purge();

        $this->assertFalse($manager->isActive());

        Event::assertDispatched(ImpersonationPurged::class);
    }

    public function test_context_returns_active_context(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);

        $context = $manager->context();

        $this->assertInstanceOf(ImpersonationContext::class, $context);
        $this->assertSame($admin->id, $context->impersonatorId);
        $this->assertSame($target->id, $context->impersonatedId);
    }

    public function test_impersonator_returns_original_user(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($target);

        $impersonator = $manager->impersonator();

        $this->assertNotNull($impersonator);
        $this->assertSame($admin->id, $impersonator->getAuthIdentifier());
    }

    public function test_denied_event_dispatched_on_gate_failure(): void
    {
        Event::fake();

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'secret']);
        $target = User::create(['name' => 'User', 'email' => 'user@test.com', 'password' => 'secret']);

        $this->actingAs($admin);

        try {
            app(ImpersonateManager::class)->start($target);
        } catch (UnauthorizedImpersonationException) {
        }

        Event::assertDispatched(ImpersonationDenied::class, function ($event) {
            return $event->reason === 'Gate authorization failed.';
        });
    }
}
