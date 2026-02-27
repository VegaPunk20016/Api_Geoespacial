<?php

namespace Modules\Users\Entities;

use CodeIgniter\Entity\Entity;

class Permission extends Entity
{
    protected $attributes = [
        'id'          => null,
        'name'        => null,
        'description' => null,
    ];
}