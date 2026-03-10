<?php

namespace Modules\Users\DTOs;

class UpdateRequest
{
    public ?string $username;
    public ?string $email;
    public ?string $password;
    public ?int $role_id;

    public function __construct(array $data)
    {
        $this->username = isset($data['username']) ? trim($data['username']) : null;
        $this->email = isset($data['email']) ? strtolower(trim($data['email'])) : null;   
        $this->password = $data['password'] ?? null;
        $this->role_id = $data['role_id'] ?? null;
    }
}