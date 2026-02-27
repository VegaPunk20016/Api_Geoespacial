<?php

namespace Modules\Users\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'VARCHAR', 'constraint' => 36],
            'role_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 3], // 3 = User normal
            'username'   => ['type' => 'VARCHAR', 'constraint' => 50, 'unique' => true],
            'email'      => ['type' => 'VARCHAR', 'constraint' => 50, 'unique' => true],
            'password'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'RESTRICT', 'RESTRICT');
        $this->forge->createTable('users');
    }

    public function down()
    {
        $this->forge->dropTable('users');
    }
}
