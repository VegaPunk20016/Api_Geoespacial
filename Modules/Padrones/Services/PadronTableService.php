<?php

namespace Modules\Padrones\Services;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Forge;

class PadronTableService
{
    private Forge $forge;
    private ConnectionInterface $db;

    public function __construct(Forge $forge, ConnectionInterface $db)
    {
        $this->forge = $forge;
        $this->db    = $db;
    }

    public function crearTabla(string $tabla, string $uuid): void
    {
        if ($this->db->tableExists($tabla)) {
            return;
        }

        $campos = [
            'id'                 => ['type' => 'CHAR',    'constraint' => 36],
            'catalogo_padron_id' => ['type' => 'CHAR',    'constraint' => 36],
            'clave_unica'        => ['type' => 'VARCHAR', 'constraint' => 100,    'null' => true],
            'nombre_completo'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'municipio'          => ['type' => 'VARCHAR', 'constraint' => 255,    'null' => true],
            'seccion'            => ['type' => 'VARCHAR', 'constraint' => 255,    'null' => true],
            'latitud'            => ['type' => 'DECIMAL', 'constraint' => '10,8', 'null' => true],
            'longitud'           => ['type' => 'DECIMAL', 'constraint' => '11,8', 'null' => true],
            'datos_generales'    => ['type' => 'JSON',    'null' => true],
            'estatus_duplicidad' => [
                'type'       => 'ENUM',
                // ✅ FIX: agregado 'generado_por_sistema' para padrones sin clave natural
                'constraint' => ['limpio', 'repetido', 'generado_por_sistema'],
                'default'    => 'limpio',
            ],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
        ];

        $this->forge->addField($campos);
        $this->forge->addKey('id', true);
        $this->forge->addKey('clave_unica');
        $this->forge->addKey('nombre_completo');
        $this->forge->addKey('municipio');
        $this->forge->addKey(['latitud', 'longitud']);

        $nombreFk = 'fk_cp_' . substr($uuid, 0, 8);
        $this->forge->addForeignKey('catalogo_padron_id', 'catalogo_padrones', 'id', 'CASCADE', 'CASCADE', $nombreFk);

        $this->forge->createTable($tabla);
    }

    public function eliminarTabla(string $tabla): void
    {
        if ($this->db->tableExists($tabla)) {
            // FK checks son deshabilitados por PadronService antes de llamar aquí
            $this->forge->dropTable($tabla, true);
        }
    }
}