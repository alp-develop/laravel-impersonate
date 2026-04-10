<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Support;

use Illuminate\Session\Store;

class SessionStore
{
    public function __construct(
        private readonly Store $session,
    ) {}

    public function start(ImpersonationContext $context): void
    {
        $this->session->put($this->key(), $context->toArray());
    }

    public function stop(): ?ImpersonationContext
    {
        $context = $this->current();

        $this->clear();

        return $context;
    }

    public function current(): ?ImpersonationContext
    {
        $raw = $this->session->get($this->key());

        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            $this->clear();

            return null;
        }

        try {
            return ImpersonationContext::fromArray($raw);
        } catch (\Throwable) {
            $this->clear();

            return null;
        }
    }

    public function clear(): void
    {
        $this->session->forget($this->key());
    }

    public function isActive(): bool
    {
        return $this->current() !== null;
    }

    private function key(): string
    {
        return config('impersonate.session_key', 'impersonation_context');
    }
}
