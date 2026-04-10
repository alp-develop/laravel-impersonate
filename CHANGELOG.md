# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-10

### Added
- `ImpersonateManager` with start, stop, purge, isActive, context, and impersonator methods
- `Impersonate` facade for convenient access
- `Impersonatable` contract extending `Authenticatable`
- `HasImpersonation` trait with default `canBeImpersonated()` and `canImpersonate()` implementations
- Actions: `StartImpersonation`, `StopImpersonation`, `PurgeImpersonation`, `ValidateImpersonation`
- `ImpersonationContext` with versioned serialization, type validation, and expiration support
- `SessionStore` for session-based impersonation state with error-safe reads
- `HandleImpersonation` middleware for request-level validation and context injection
- `ForbidDuringImpersonation` middleware to block access during impersonation (403 or redirect)
- `ImpersonationStatus` enum for validation results
- Events: `ImpersonationStarted`, `ImpersonationEnded`, `ImpersonationPurged`, `ImpersonationExpired`, `ImpersonationDenied`
- Exceptions: `CannotImpersonateException`, `UnauthorizedImpersonationException`, `InvalidImpersonationContextException`
- Gate-based authorization (`impersonate` gate, default deny)
- Privilege escalation prevention via `getImpersonationPrivilegeLevel()`
- Optional TTL with minimum 1-minute validation
- Blade directives: `@impersonating`, `@canImpersonate`
- Request macros: `isImpersonating()`, `impersonator()`, `impersonation()`
- Middleware alias `forbid-impersonation`
- Publishable configuration file
- IP address tracking in impersonation context
- Session regeneration on start, stop, and purge
- Self-impersonation prevention
- Corrupted session auto-recovery
- Support for PHP 8.1 - 8.5 and Laravel 10 - 13
