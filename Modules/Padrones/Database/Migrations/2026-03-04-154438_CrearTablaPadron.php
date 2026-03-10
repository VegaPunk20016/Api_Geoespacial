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
            'categoria' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'General',
                'after'      => 'entidad_federativa', // Esto la posiciona visualmente donde pediste
            ],
            'clave_interna' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'null'       => true,
            ],
            'entidad_federativa' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
            ],
            'nombre_tabla_destino' => [
                'type'       => 'VARCHAR',
                'constraint' => '100',
                'unique'     => true,
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
