<?php

namespace Modules\Users\Models;

use CodeIgniter\Model;
use Modules\Users\Entities\Role;

class RoleModel extends Model
{
    protected $table            = 'roles';
    protected $primaryKey       = 'id';
    protected $returnType       = Role::class;
    protected $allowedFields    = ['name', 'description'];
}