<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Enums;

enum ImpersonationStatus
{
    case Valid;
    case Expired;
    case ImpersonatorMissing;
    case TargetMissing;
    case NoActiveImpersonation;
}
