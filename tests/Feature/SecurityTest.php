<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Feature;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationDenied;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationExpired;
use AlpDevelop\LaravelImpersonate\Events\ImpersonationPurged;
use AlpDevelop\LaravelImpersonate\Exceptions\CannotImpersonateException;
use AlpDevelop\LaravelImpersonate\Exceptions\InvalidImpersonationContextException;
use AlpDevelop\LaravelImpersonate\Exceptions\UnauthorizedImpersonationException;
use AlpDevelop\LaravelImpersonate\ImpersonateManager;
use AlpDevelop\LaravelImpersonate\Middleware\ForbidDuringImpersonation;
use AlpDevelop\LaravelImpersonate\Middleware\HandleImpersonation;
use AlpDevelop\LaravelImpersonate\Support\ImpersonationContext;
use AlpDevelop\LaravelImpersonate\Support\SessionStore;
use AlpDevelop\LaravelImpersonate\Tests\TestCase;
use AlpDevelop\LaravelImpersonate\Tests\User;
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->connection('testing')->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('privilege_level')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        $this->app['config']->set('auth.providers.users.model', PrivilegedUser::class);
    }

    public function test_self_impersonation_blocked_same_user(): void
    {
        Gate::define('impersonate', fn () => true);

        $user = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($user);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('Cannot impersonate yourself.');

        app(ImpersonateManager::class)->start($user);
    }

    public function test_privilege_escalation_blocked_higher_target(): void
    {
        Event::fake();
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 50,
        ]);
        $superAdmin = PrivilegedUser::create([
            'name' => 'Super', 'email' => 'super@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $this->expectException(UnauthorizedImpersonationException::class);
        $this->expectExceptionMessage('Cannot impersonate a user with equal or higher privilege level.');

        app(ImpersonateManager::class)->start($superAdmin);
    }

    public function test_privilege_escalation_blocked_equal_level(): void
    {
        Event::fake();
        Gate::define('impersonate', fn () => true);

        $admin1 = PrivilegedUser::create([
            'name' => 'Admin1', 'email' => 'admin1@sec.test',
            'password' => 'secret', 'privilege_level' => 50,
        ]);
        $admin2 = PrivilegedUser::create([
            'name' => 'Admin2', 'email' => 'admin2@sec.test',
            'password' => 'secret', 'privilege_level' => 50,
        ]);

        $this->actingAs($admin1);

        $this->expectException(UnauthorizedImpersonationException::class);
        $this->expectExceptionMessage('Cannot impersonate a user with equal or higher privilege level.');

        app(ImpersonateManager::class)->start($admin2);
    }

    public function test_privilege_escalation_allowed_lower_target(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user);

        $this->assertTrue($manager->isActive());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_privilege_escalation_denied_event_dispatched(): void
    {
        Event::fake();
        Gate::define('impersonate', fn () => true);

        $low = PrivilegedUser::create([
            'name' => 'Low', 'email' => 'low@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);
        $high = PrivilegedUser::create([
            'name' => 'High', 'email' => 'high@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($low);

        try {
            app(ImpersonateManager::class)->start($high);
        } catch (UnauthorizedImpersonationException) {
        }

        Event::assertDispatched(ImpersonationDenied::class, function ($event) {
            return $event->reason === 'Privilege escalation blocked.';
        });
    }

    public function test_privilege_escalation_disabled_by_config(): void
    {
        $this->app['config']->set('impersonate.prevent_privilege_escalation', false);
        Gate::define('impersonate', fn () => true);

        $low = PrivilegedUser::create([
            'name' => 'Low', 'email' => 'low@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);
        $high = PrivilegedUser::create([
            'name' => 'High', 'email' => 'high@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($low);

        $manager = app(ImpersonateManager::class);
        $manager->start($high);

        $this->assertTrue($manager->isActive());
    }

    public function test_gate_default_denies_all(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $this->expectException(UnauthorizedImpersonationException::class);

        app(ImpersonateManager::class)->start($user);
    }

    public function test_non_impersonatable_user_throws_exception(): void
    {
        $this->app['config']->set('auth.providers.users.model', NonImpersonatableUser::class);

        $this->app['db']->connection('testing')->getSchemaBuilder()->create('plain_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Gate::define('impersonate', fn () => true);

        $user = NonImpersonatableUser::create([
            'name' => 'Plain', 'email' => 'plain@sec.test', 'password' => 'secret',
        ]);
        $target = PrivilegedUser::create([
            'name' => 'Target', 'email' => 'target@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        Auth::login($user);

        $this->expectException(UnauthorizedImpersonationException::class);
        $this->expectExceptionMessage('Authenticated user must implement Impersonatable.');

        app(ImpersonateManager::class)->start($target);
    }

    public function test_cannot_start_double_impersonation(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user1 = PrivilegedUser::create([
            'name' => 'User1', 'email' => 'user1@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);
        $user2 = PrivilegedUser::create([
            'name' => 'User2', 'email' => 'user2@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user1);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('An impersonation session is already active.');

        $manager->start($user2);
    }

    public function test_expired_context_triggers_purge_via_middleware(): void
    {
        Event::fake();

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: $user->id,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-01 10:00:00'),
            expiresAt: CarbonImmutable::parse('2026-04-01 10:30:00'),
        ));

        Auth::login($user);

        $middleware = app(HandleImpersonation::class);
        $request = Request::create('/test');
        $request->setLaravelSession(app('session.store'));

        $middleware->handle($request, fn ($r) => new Response('ok'));

        $this->assertFalse($store->isActive());
        Event::assertDispatched(ImpersonationExpired::class);
        Event::assertDispatched(ImpersonationPurged::class);
    }

    public function test_missing_impersonator_triggers_purge(): void
    {
        Event::fake();

        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($user);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: 99999,
            impersonatedId: $user->id,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $middleware = app(HandleImpersonation::class);
        $request = Request::create('/test');
        $request->setLaravelSession(app('session.store'));

        $middleware->handle($request, fn ($r) => new Response('ok'));

        $this->assertFalse($store->isActive());
        Event::assertDispatched(ImpersonationPurged::class);
    }

    public function test_missing_target_triggers_purge(): void
    {
        Event::fake();

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: 99999,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $middleware = app(HandleImpersonation::class);
        $request = Request::create('/test');
        $request->setLaravelSession(app('session.store'));

        $middleware->handle($request, fn ($r) => new Response('ok'));

        $this->assertFalse($store->isActive());
        Event::assertDispatched(ImpersonationPurged::class);
    }

    public function test_forbid_middleware_blocks_during_impersonation(): void
    {
        $request = Request::create('/admin/settings');
        $request->attributes->set('impersonation', new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $middleware = new ForbidDuringImpersonation;

        $this->expectException(HttpException::class);

        $middleware->handle($request, fn ($r) => new Response('ok'));
    }

    public function test_forbid_middleware_allows_when_not_impersonating(): void
    {
        $request = Request::create('/admin/settings');

        $middleware = new ForbidDuringImpersonation;
        $response = $middleware->handle($request, fn ($r) => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_forbid_middleware_redirects_with_parameter(): void
    {
        $request = Request::create('/admin/settings');
        $request->attributes->set('impersonation', new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $middleware = new ForbidDuringImpersonation;
        $response = $middleware->handle($request, fn ($r) => new Response('ok'), '/dashboard');

        $this->assertTrue($response->isRedirection());
        $this->assertStringEndsWith('/dashboard', $response->headers->get('Location'));
    }

    public function test_session_regenerated_on_start(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $sessionIdBefore = session()->getId();

        app(ImpersonateManager::class)->start($user);

        $this->assertNotSame($sessionIdBefore, session()->getId());
    }

    public function test_session_regenerated_on_stop(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user);

        $sessionIdBefore = session()->getId();
        $manager->stop();

        $this->assertNotSame($sessionIdBefore, session()->getId());
    }

    public function test_session_regenerated_on_purge(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user);

        $sessionIdBefore = session()->getId();
        $manager->purge();

        $this->assertNotSame($sessionIdBefore, session()->getId());
    }

    public function test_corrupted_session_data_safely_handled(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), 'corrupted-string-data');

        $this->assertNull($store->current());
        $this->assertFalse($store->isActive());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_tampered_version_in_session_safely_handled(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 999,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_tampered_session_missing_required_fields(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_context_preserves_ip_address(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        app(ImpersonateManager::class)->start($user);

        $context = app(SessionStore::class)->current();

        $this->assertNotNull($context);
        $this->assertNotNull($context->ipAddress);
    }

    public function test_context_with_ttl_creates_expiration(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-09 15:00:00'));

        app(ImpersonateManager::class)->start($user, 30);

        $context = app(SessionStore::class)->current();

        $this->assertNotNull($context->expiresAt);
        $this->assertTrue($context->expiresAt->eq(CarbonImmutable::parse('2026-04-09 15:30:00')));

        CarbonImmutable::setTestNow();
    }

    public function test_stop_restores_original_user_identity(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user);

        $this->assertSame($user->id, Auth::id());
        $this->assertSame('User', Auth::user()->name);

        $manager->stop();

        $this->assertSame($admin->id, Auth::id());
        $this->assertSame('Admin', Auth::user()->name);
    }

    public function test_valid_context_injects_request_attributes(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($user);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: $user->id,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $middleware = app(HandleImpersonation::class);
        $request = Request::create('/test');
        $request->setLaravelSession(app('session.store'));

        $middleware->handle($request, function ($r) use ($admin) {
            $this->assertTrue($r->attributes->has('impersonation'));
            $this->assertSame($admin->id, $r->attributes->get('impersonator_id'));

            return new Response('ok');
        });
    }

    public function test_can_be_impersonated_returns_false_blocks_start(): void
    {
        Event::fake();
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $protected = ProtectedUser::create([
            'name' => 'Protected', 'email' => 'protected@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('Target user cannot be impersonated.');

        app(ImpersonateManager::class)->start($protected);
    }

    public function test_context_serialization_roundtrip_integrity(): void
    {
        $original = new ImpersonationContext(
            impersonatorId: 42,
            impersonatedId: 99,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09T12:00:00+00:00'),
            expiresAt: CarbonImmutable::parse('2026-04-09T12:30:00+00:00'),
            ipAddress: '192.168.1.100',
        );

        $serialized = $original->toArray();
        $restored = ImpersonationContext::fromArray($serialized);

        $this->assertSame($original->impersonatorId, $restored->impersonatorId);
        $this->assertSame($original->impersonatedId, $restored->impersonatedId);
        $this->assertSame($original->guard, $restored->guard);
        $this->assertTrue($original->startedAt->eq($restored->startedAt));
        $this->assertTrue($original->expiresAt->eq($restored->expiresAt));
        $this->assertSame($original->ipAddress, $restored->ipAddress);
    }

    public function test_start_stores_context_after_session_regeneration(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $oldSessionId = session()->getId();

        app(ImpersonateManager::class)->start($user);

        $newSessionId = session()->getId();
        $this->assertNotSame($oldSessionId, $newSessionId);

        $store = app(SessionStore::class);
        $this->assertTrue($store->isActive());
        $context = $store->current();
        $this->assertSame($admin->id, $context->impersonatorId);
    }

    public function test_stop_clears_context_after_session_regeneration(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $manager = app(ImpersonateManager::class);
        $manager->start($user);
        $manager->stop();

        $store = app(SessionStore::class);
        $this->assertFalse($store->isActive());
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_tampered_guard_in_session_handled_by_validate(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => $admin->id,
                'impersonated_id' => $user->id,
                'guard' => 'nonexistent_guard',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);

        $middleware = app(HandleImpersonation::class);
        $request = Request::create('/test');
        $request->setLaravelSession(app('session.store'));

        $response = $middleware->handle($request, fn ($r) => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());

        $store = app(SessionStore::class);
        $this->assertFalse($store->isActive());
    }

    public function test_tampered_guard_in_session_handled_by_stop(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: 2,
            guard: 'fake_guard',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('Invalid guard in impersonation context.');

        app(ImpersonateManager::class)->stop();
    }

    public function test_purge_with_invalid_guard_falls_back_to_default(): void
    {
        Event::fake();

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: 2,
            guard: 'fake_guard',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        app(ImpersonateManager::class)->purge();

        $this->assertFalse($store->isActive());
        Event::assertDispatched(ImpersonationPurged::class);
    }

    public function test_stop_without_active_impersonation_throws(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('No active impersonation to stop.');

        app(ImpersonateManager::class)->stop();
    }

    public function test_context_not_expired_with_null_expiration(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-01-01 00:00:00'),
            expiresAt: null,
        );

        $this->assertFalse($context->isExpired());
    }

    public function test_context_expired_with_past_date(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-01 10:00:00'),
            expiresAt: CarbonImmutable::parse('2026-04-01 10:30:00'),
        );

        $this->assertTrue($context->isExpired());
    }

    public function test_context_not_expired_with_future_date(): void
    {
        $context = new ImpersonationContext(
            impersonatorId: 1,
            impersonatedId: 2,
            guard: 'web',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
            expiresAt: CarbonImmutable::parse('2099-12-31 23:59:59'),
        );

        $this->assertFalse($context->isExpired());
    }

    public function test_impersonation_denied_event_includes_target_info(): void
    {
        Event::fake();
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $protected = ProtectedUser::create([
            'name' => 'Protected', 'email' => 'protected@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        try {
            app(ImpersonateManager::class)->start($protected);
        } catch (CannotImpersonateException) {
        }

        Event::assertDispatched(ImpersonationDenied::class, function ($event) use ($admin, $protected) {
            return $event->impersonator->getAuthIdentifier() === $admin->id
                && $event->target->getAuthIdentifier() === $protected->id
                && $event->reason === 'Target cannot be impersonated.';
        });
    }

    public function test_no_authenticated_user_throws_on_start(): void
    {
        Gate::define('impersonate', fn () => true);

        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->expectException(UnauthorizedImpersonationException::class);
        $this->expectExceptionMessage('Authenticated user must implement Impersonatable.');

        app(ImpersonateManager::class)->start($user);
    }

    public function test_impersonator_returns_null_with_invalid_guard_in_session(): void
    {
        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $store = app(SessionStore::class);
        $store->start(new ImpersonationContext(
            impersonatorId: $admin->id,
            impersonatedId: 2,
            guard: 'hacked_guard',
            startedAt: CarbonImmutable::parse('2026-04-09 10:00:00'),
        ));

        $result = app(ImpersonateManager::class)->impersonator();

        $this->assertNull($result);
    }

    public function test_negative_ttl_throws_exception(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('TTL must be at least 1 minute.');

        app(ImpersonateManager::class)->start($user, -10);
    }

    public function test_zero_ttl_throws_exception(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        $this->expectException(CannotImpersonateException::class);
        $this->expectExceptionMessage('TTL must be at least 1 minute.');

        app(ImpersonateManager::class)->start($user, 0);
    }

    public function test_valid_ttl_creates_expiration(): void
    {
        Gate::define('impersonate', fn () => true);

        $admin = PrivilegedUser::create([
            'name' => 'Admin', 'email' => 'admin@sec.test',
            'password' => 'secret', 'privilege_level' => 100,
        ]);
        $user = PrivilegedUser::create([
            'name' => 'User', 'email' => 'user@sec.test',
            'password' => 'secret', 'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        app(ImpersonateManager::class)->start($user, 5);

        $context = app(SessionStore::class)->current();

        $this->assertNotNull($context->expiresAt);
        $this->assertFalse($context->isExpired());
    }

    public function test_from_array_rejects_array_as_impersonator_id(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);
        $this->expectExceptionMessage('Field impersonator_id must be int or string.');

        ImpersonationContext::fromArray([
            'v' => 1,
            'data' => [
                'impersonator_id' => ['injected' => 'payload'],
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);
    }

    public function test_from_array_rejects_array_as_impersonated_id(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);
        $this->expectExceptionMessage('Field impersonated_id must be int or string.');

        ImpersonationContext::fromArray([
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => ['injected' => true],
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);
    }

    public function test_from_array_rejects_empty_guard(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);
        $this->expectExceptionMessage('Field guard must be a non-empty string.');

        ImpersonationContext::fromArray([
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => '',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);
    }

    public function test_from_array_rejects_malformed_date(): void
    {
        $this->expectException(InvalidImpersonationContextException::class);
        $this->expectExceptionMessage('Invalid date format in impersonation context');

        ImpersonationContext::fromArray([
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => 'not-a-date-!@#$%',
            ],
        ]);
    }

    public function test_tampered_session_with_array_ids_safely_cleared(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => ['sql' => 'injection'],
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_tampered_session_with_malformed_dates_safely_cleared(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '"><script>alert(1)</script>',
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_tampered_session_with_array_ip_address_safely_cleared(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
                'ip_address' => ['127.0.0.1', 'injected'],
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_tampered_session_with_integer_ip_address_safely_cleared(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
                'ip_address' => 12345,
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_session_store_catches_unexpected_throwable_and_clears(): void
    {
        $store = app(SessionStore::class);

        session()->put(config('impersonate.session_key'), [
            'v' => 1,
            'data' => [
                'impersonator_id' => 1,
                'impersonated_id' => 2,
                'guard' => 'web',
                'started_at' => '2026-04-09T10:00:00+00:00',
                'ip_address' => true,
            ],
        ]);

        $this->assertNull($store->current());
        $this->assertFalse(session()->has(config('impersonate.session_key')));
    }

    public function test_can_impersonate_blade_directive_returns_false_for_non_impersonatable(): void
    {
        $user = User::forceCreate([
            'name' => 'regular',
            'email' => 'regular-directive@test.com',
            'password' => bcrypt('password'),
        ]);

        $nonImpersonatable = new NonImpersonatableUser;
        $nonImpersonatable->id = $user->id;
        $nonImpersonatable->name = $user->name;
        $nonImpersonatable->email = $user->email;

        Auth::login($nonImpersonatable);

        $result = Blade::check('canImpersonate');

        $this->assertFalse($result);
    }

    public function test_can_impersonate_blade_directive_returns_false_during_active_impersonation(): void
    {
        $admin = PrivilegedUser::forceCreate([
            'name' => 'admin',
            'email' => 'admin-directive@test.com',
            'password' => bcrypt('password'),
            'privilege_level' => 100,
        ]);

        $target = PrivilegedUser::forceCreate([
            'name' => 'target',
            'email' => 'target-directive@test.com',
            'password' => bcrypt('password'),
            'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        Gate::define('impersonate', fn () => true);

        app(ImpersonateManager::class)->start($target);

        $result = Blade::check('canImpersonate');

        $this->assertFalse($result);
    }

    public function test_can_impersonate_blade_directive_returns_true_for_authorized_user(): void
    {
        $admin = PrivilegedUser::forceCreate([
            'name' => 'admin',
            'email' => 'admin-blade@test.com',
            'password' => bcrypt('password'),
            'privilege_level' => 100,
        ]);

        $this->actingAs($admin);

        $result = Blade::check('canImpersonate');

        $this->assertTrue($result);
    }

    public function test_can_impersonate_blade_directive_with_target_checks_gate(): void
    {
        $admin = PrivilegedUser::forceCreate([
            'name' => 'admin',
            'email' => 'admin-gate-blade@test.com',
            'password' => bcrypt('password'),
            'privilege_level' => 100,
        ]);

        $target = PrivilegedUser::forceCreate([
            'name' => 'target',
            'email' => 'target-gate-blade@test.com',
            'password' => bcrypt('password'),
            'privilege_level' => 10,
        ]);

        $this->actingAs($admin);

        Gate::define('impersonate', fn () => false);

        $result = Blade::check('canImpersonate', $target);

        $this->assertFalse($result);
    }
}

class PrivilegedUser extends Authenticatable implements Impersonatable
{
    use HasImpersonation;

    protected $guarded = [];

    protected $table = 'users';

    public function getImpersonationPrivilegeLevel(): int
    {
        return (int) $this->privilege_level;
    }
}

class NonImpersonatableUser extends Authenticatable
{
    protected $guarded = [];

    protected $table = 'plain_users';
}

class ProtectedUser extends Authenticatable implements Impersonatable
{
    use HasImpersonation;

    protected $guarded = [];

    protected $table = 'users';

    public function canBeImpersonated(): bool
    {
        return false;
    }

    public function getImpersonationPrivilegeLevel(): int
    {
        return (int) $this->privilege_level;
    }
}
