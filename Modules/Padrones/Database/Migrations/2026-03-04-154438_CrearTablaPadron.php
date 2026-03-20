<?php

namespace Modules\Padrones\Database\Migrations;

use CodeIgniter\Database\Migration;

class CrearTablaCatalogoPadrones extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => [
                'type' => 'VARCHAR', 
                'constraint' => 36
            ],
            'nombre_padron' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
            ],
            'descripcion' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'entidad_federativa' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
            ],
            'categoria' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'General',
            ],
            'clave_interna' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'nombre_tabla_destino' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'unique'     => true,
            ],
            'plantilla_mapeo' => [
                'type' => 'JSON', 
                'null' => true,   
            ],
            'formato_esperado' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'default'    => null,
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->createTable('catalogo_padrones');
    }

    public function down()
    {
        $this->forge->dropTable('catalogo_padrones');
    }
}