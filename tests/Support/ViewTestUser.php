<?php

namespace D3Creative\Sentinel\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Minimal CP user for tests: Authenticatable plus the isSuper() and email
 * the controllers and views read.
 */
class ViewTestUser implements Authenticatable
{
    public string $email = 'super@example.test';

    public function __construct(protected bool $super)
    {
    }

    public function isSuper(): bool
    {
        return $this->super;
    }

    public function can($ability, $arguments = []): bool
    {
        return $this->super;
    }

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return 1; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getAuthPassword(): string { return ''; }
    public function getRememberToken(): string { return ''; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
}
