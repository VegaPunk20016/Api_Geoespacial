<?php

namespace Modules\Users\Database\Seeds;

use CodeIgniter\Database\Seeder;

class SecuritySeeder extends Seeder
{
    public function run()
    {
        // 1. Roles
        $this->db->table('roles')->insertBatch([
            ['id' => 1, 'name' => 'super_admin', 'description' => 'Control total'],
            ['id' => 2, 'name' => 'admin', 'description' => 'Control de contenido, no borra usuarios'],
            ['id' => 3, 'name' => 'user', 'description' => 'Solo lectura'],
        ]);

        // 2. Permisos
        $this->db->table('permissions')->insertBatch([
            ['id' => 1, 'name' => 'read', 'description' => 'Consultar datos'],
            ['id' => 2, 'name' => 'write', 'description' => 'Insertar/Modificar datos'],
            ['id' => 3, 'name' => 'delete', 'description' => 'Eliminar datos permanentemente'],
            ['id' => 4, 'name' => 'manage_users', 'description' => 'Gestionar roles/usuarios'],
        ]);

        // 3. Matriz (Role_Permissions)
        $this->db->table('role_permissions')->insertBatch([
            // Super Admin
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
            ['role_id' => 1, 'permission_id' => 3],
            ['role_id' => 1, 'permission_id' => 4],
            // Admin
            ['role_id' => 2, 'permission_id' => 1],
            ['role_id' => 2, 'permission_id' => 2],
            ['role_id' => 2, 'permission_id' => 3],
            // User
            ['role_id' => 3, 'permission_id' => 1],
        ]);
    }
}
