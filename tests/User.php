<?php

declare(strict_types=1);

namespace AlpDevelop\LaravelImpersonate\Tests;

use AlpDevelop\LaravelImpersonate\Contracts\Impersonatable;
use AlpDevelop\LaravelImpersonate\Traits\HasImpersonation;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements Impersonatable
{
    use HasImpersonation;

    protected $guarded = [];

    protected $table = 'users';
}
