<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests\Unit\Enums;

use AlpDevelop\LaravelImpersonate\Enums\ImpersonationStatus;
use PHPUnit\Framework\TestCase;

class ImpersonationStatusTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        $cases = ImpersonationStatus::cases();

        $this->assertCount(5, $cases);
        $this->assertContains(ImpersonationStatus::Valid, $cases);
        $this->assertContains(ImpersonationStatus::Expired, $cases);
        $this->assertContains(ImpersonationStatus::ImpersonatorMissing, $cases);
        $this->assertContains(ImpersonationStatus::TargetMissing, $cases);
        $this->assertContains(ImpersonationStatus::NoActiveImpersonation, $cases);
    }
}
