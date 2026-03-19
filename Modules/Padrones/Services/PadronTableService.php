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
            'codigo_postal'      => ['type' => 'VARCHAR', 'constraint' => 10,     'null' => true], 
            'seccion'            => ['type' => 'VARCHAR', 'constraint' => 255,    'null' => true],
            'latitud'            => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true],
            'longitud'           => ['type' => 'DECIMAL', 'constraint' => '11,7', 'null' => true],
            'datos_generales'    => ['type' => 'JSON',    'null' => true],
            'estatus_duplicidad' => [
                'type'       => 'ENUM',
                'constraint' => ['limpio', 'repetido', 'generado_por_sistema'],
                'default'    => 'limpio',
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ];

        $this->forge->addField($campos);

        // --- LLAVE PRIMARIA ---
        $this->forge->addKey('id', true);

        // --- ÍNDICES INDIVIDUALES ---
        $this->forge->addKey('clave_unica');
        $this->forge->addKey('nombre_completo');
        $this->forge->addKey('municipio');
        $this->forge->addKey('codigo_postal'); // <-- ÍNDICE PARA BÚSQUEDAS POR CP
        $this->forge->addKey('seccion');
        $this->forge->addKey(['latitud', 'longitud']);
        $this->forge->addKey(['municipio', 'latitud', 'longitud']);

        $nombreFk = 'fk_cp_' . substr($uuid, 0, 8);
        $this->forge->addForeignKey(
            'catalogo_padron_id',
            'catalogo_padrones',
            'id',
            'CASCADE',
            'CASCADE',
            $nombreFk
        );

        $this->forge->createTable($tabla);
    }

    public function eliminarTabla(string $tabla): void
    {
        if ($this->db->tableExists($tabla)) {
            $this->forge->dropTable($tabla, true);
        }
    }

    public function optimizarIndices(string $tabla): void
    {
        if (!$this->db->tableExists($tabla)) {
            return;
        }

        $tablaSegura = $this->db->escapeIdentifier($tabla);

        // Obtenemos los índices actuales para evitar duplicados
        $indicesExistentes = array_column(
            $this->db->query("SHOW INDEX FROM {$tablaSegura}")->getResultArray(),
            'Key_name'
        );

        $indicesNecesarios = [
            'idx_mun'     => "ALTER TABLE {$tablaSegura} ADD INDEX idx_mun (municipio)",
            'idx_cp'      => "ALTER TABLE {$tablaSegura} ADD INDEX idx_cp (codigo_postal)", // <-- NUEVO ÍNDICE
            'idx_seccion' => "ALTER TABLE {$tablaSegura} ADD INDEX idx_seccion (seccion)",
            'idx_clave'   => "ALTER TABLE {$tablaSegura} ADD INDEX idx_clave (clave_unica)",
            'idx_geo'     => "ALTER TABLE {$tablaSegura} ADD INDEX idx_geo (latitud, longitud)",
            'idx_mun_geo' => "ALTER TABLE {$tablaSegura} ADD INDEX idx_mun_geo (municipio, latitud, longitud)",
        ];

        foreach ($indicesNecesarios as $nombre => $sql) {
            if (!in_array($nombre, $indicesExistentes, true)) {
                $this->db->query($sql);
            }
        }
    }
}