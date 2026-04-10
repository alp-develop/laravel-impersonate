<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Support;

use AlpDevelop\LaravelImpersonate\Exceptions\InvalidImpersonationContextException;
use Carbon\CarbonImmutable;

class ImpersonationContext
{
    private const VERSION = 1;

    public function __construct(
        public readonly int|string $impersonatorId,
        public readonly int|string $impersonatedId,
        public readonly string $guard,
        public readonly CarbonImmutable $startedAt,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $ipAddress = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt?->isPast() ?? false;
    }

    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'data' => [
                'impersonator_id' => $this->impersonatorId,
                'impersonated_id' => $this->impersonatedId,
                'guard' => $this->guard,
                'started_at' => $this->startedAt->toIso8601String(),
                'expires_at' => $this->expiresAt?->toIso8601String(),
                'ip_address' => $this->ipAddress,
            ],
        ];
    }

    public static function fromArray(array $data): self
    {
        $version = $data['v'] ?? 0;

        if ($version !== self::VERSION) {
            throw new InvalidImpersonationContextException(
                'Invalid impersonation context version: expected '.self::VERSION.", got {$version}"
            );
        }

        if (! isset($data['data']) || ! is_array($data['data'])) {
            throw new InvalidImpersonationContextException(
                'Invalid impersonation context structure'
            );
        }

        $d = $data['data'];

        $required = ['impersonator_id', 'impersonated_id', 'guard', 'started_at'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $d)) {
                throw new InvalidImpersonationContextException(
                    "Missing required field: {$key}"
                );
            }
        }

        if (! is_int($d['impersonator_id']) && ! is_string($d['impersonator_id'])) {
            throw new InvalidImpersonationContextException(
                'Field impersonator_id must be int or string.'
            );
        }

        if (! is_int($d['impersonated_id']) && ! is_string($d['impersonated_id'])) {
            throw new InvalidImpersonationContextException(
                'Field impersonated_id must be int or string.'
            );
        }

        if (! is_string($d['guard']) || $d['guard'] === '') {
            throw new InvalidImpersonationContextException(
                'Field guard must be a non-empty string.'
            );
        }

        if (isset($d['ip_address']) && ! is_string($d['ip_address'])) {
            throw new InvalidImpersonationContextException(
                'Field ip_address must be a string when present.'
            );
        }

        try {
            $startedAt = CarbonImmutable::parse($d['started_at']);
            $expiresAt = isset($d['expires_at']) ? CarbonImmutable::parse($d['expires_at']) : null;
        } catch (\Throwable $e) {
            throw new InvalidImpersonationContextException(
                'Invalid date format in impersonation context: '.$e->getMessage()
            );
        }

        return new self(
            impersonatorId: $d['impersonator_id'],
            impersonatedId: $d['impersonated_id'],
            guard: $d['guard'],
            startedAt: $startedAt,
            expiresAt: $expiresAt,
            ipAddress: $d['ip_address'] ?? null,
        );
    }
}
