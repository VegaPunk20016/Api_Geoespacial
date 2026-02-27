<?php

namespace Modules\Users\Interfaces;

use Modules\Users\DTOs\RegisterRequest;
use Modules\Users\DTOs\LoginRequest;
use Modules\Users\DTOs\UpdateRequest;
use Modules\Users\Entities\User;

interface AuthServiceInterface
{
    public function registerUser(RegisterRequest $req): User;
    public function login(LoginRequest $req): string;
    public function updateUser(string $email, UpdateRequest $req): User;
    public function deleteUser(string $email): bool;
    public function setRole(string $email, int $roleId): bool;
}