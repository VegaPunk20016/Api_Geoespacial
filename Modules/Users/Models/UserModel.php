<?php

namespace Modules\Users\Models;

use CodeIgniter\Model;
use Modules\Users\Entities\User;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; 
    protected $returnType       = User::class;
    protected $useSoftDeletes   = true;
    protected $allowedFields    = ['id', 'role_id', 'username', 'email', 'password'];
    protected $useTimestamps    = true;
}