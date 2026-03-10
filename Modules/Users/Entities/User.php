<?php

namespace Modules\Users\Entities;

use CodeIgniter\Entity\Entity;

class User extends Entity
{
    protected $attributes = [
        'id'         => null,
        'role_id'    => null,
        'username'   => null,
        'email'      => null,
        'password'   => null,
        'reset_token'      => null, 
        'reset_expires_at' => null,
        'created_at' => null,
        'updated_at' => null,
        'deleted_at' => null,
    ];

    protected $datamap = ['role' => 'role_name'];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];

    protected function setPassword(string $password)
    {
        $this->attributes['password'] = password_hash($password, PASSWORD_BCRYPT);
        return $this;
    }
}