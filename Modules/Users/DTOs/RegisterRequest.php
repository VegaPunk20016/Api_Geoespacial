<?php

namespace Modules\Users\DTOs;

class RegisterRequest
{
    public string $username;
    public string $email;
    public string $password;

    public function __construct(array $data)
    {
        $this->username = trim($data['username'] ?? '');
        $this->email    = strtolower(trim($data['email'] ?? ''));
        $this->password = $data['password'] ?? '';
    }
}