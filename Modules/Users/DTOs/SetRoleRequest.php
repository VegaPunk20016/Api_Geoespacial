<?php

namespace Modules\Users\DTOs;

class SetRoleRequest
{
    public int $role_id;

    public function __construct(array $data)
    {
        $this->role_id = (int)($data['role_id'] ?? 0);
    }
}