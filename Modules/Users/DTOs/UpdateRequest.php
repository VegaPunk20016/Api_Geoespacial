<?php

namespace Modules\Users\DTOs;

class UpdateRequest
{
    public ?string $username;
    public ?string $email;
    public ?string $password;

    public function __construct(array $data)
    {
        $this->username = $data['username'] ?? null;
        $this->email    = $data['email'] ?? null;
        $this->password = $data['password'] ?? null;
    }
}