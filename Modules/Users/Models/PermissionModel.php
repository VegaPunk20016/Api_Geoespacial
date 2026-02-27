<?php

namespace Modules\Users\Models;

use CodeIgniter\Model;
use Modules\Users\Entities\Permission;

class PermissionModel extends Model
{
    protected $table            = 'permissions';
    protected $primaryKey       = 'id';
    protected $returnType       = Permission::class;
    protected $allowedFields    = ['name', 'description'];
}