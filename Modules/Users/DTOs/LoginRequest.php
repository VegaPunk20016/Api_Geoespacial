<?php

namespace Modules\Users\DTOs;

class LoginRequest
{
    public string $email;
    public string $password;

    public function __construct(array $data)
    {
        $this->email    = $data['email'] ?? '';
        $this->password = $data['password'] ?? '';
    }
}