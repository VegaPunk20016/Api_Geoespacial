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
            // Si la tabla ya existe, solo nos aseguramos de que tenga sus índices optimizados
            $this->optimizarIndices($tabla);
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

        // 🚀 OPTIMIZACIÓN: Quitamos los addKey() redundantes de aquí.
        // Toda la creación de índices se delega a optimizarIndices() para evitar duplicados.

        // --- LLAVE FORÁNEA ---
        $nombreFk = 'fk_cp_' . substr($uuid, 0, 8);
        $this->forge->addForeignKey(
            'catalogo_padron_id',
            'catalogo_padrones',
            'id',
            'CASCADE',
            'CASCADE',
            $nombreFk
        );

        // Creamos la tabla físicamente
        $this->forge->createTable($tabla);
        
        // Disparamos la magia de los índices
        $this->optimizarIndices($tabla);
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
        
        // CI4 estándar para proteger nombres de tablas (escapeIdentifiers en singular o plural dependiendo versión)
        $tablaSegura = $this->db->escapeIdentifiers($tabla); 

        // 1. Obtenemos los índices actuales de la tabla (Nombres exactos)
        $indicesExistentes = array_column(
            $this->db->query("SHOW INDEX FROM {$tablaSegura}")->getResultArray(),
            'Key_name'
        );

        // 2. MAGIA ESPACIAL: Crear columna tipo POINT que se genera sola
        $columnas = $this->db->getFieldNames($tabla);
        if (!in_array('geo_point', $columnas)) {
            $sqlPoint = "ALTER TABLE {$tablaSegura} 
                         ADD COLUMN geo_point POINT 
                         GENERATED ALWAYS AS (
                             CASE 
                                 WHEN latitud IS NOT NULL AND longitud IS NOT NULL 
                                 THEN ST_PointFromText(CONCAT('POINT(', longitud, ' ', latitud, ')')) 
                                 ELSE ST_PointFromText('POINT(0 0)') 
                             END
                         ) STORED NOT NULL";
            $this->db->query($sqlPoint);
        }

        // 3. Diccionario maestro de índices (Normales y Espaciales)
        // Agregué límites de caracteres (ej. municipio(100)) para que MySQL sea aún más rápido al indexar textos largos
        $indicesNecesarios = [
            'idx_municipio' => "ALTER TABLE {$tablaSegura} ADD INDEX idx_municipio (municipio(100))",
            'idx_cp'        => "ALTER TABLE {$tablaSegura} ADD INDEX idx_cp (codigo_postal)",
            'idx_seccion'   => "ALTER TABLE {$tablaSegura} ADD INDEX idx_seccion (seccion(50))",
            'idx_clave'     => "ALTER TABLE {$tablaSegura} ADD INDEX idx_clave (clave_unica)",
            'idx_geo'       => "ALTER TABLE {$tablaSegura} ADD INDEX idx_geo (latitud, longitud)",
            'idx_mun_geo'   => "ALTER TABLE {$tablaSegura} ADD INDEX idx_mun_geo (municipio(50), latitud, longitud)",
            'idx_spatial'   => "ALTER TABLE {$tablaSegura} ADD SPATIAL INDEX idx_spatial (geo_point)"
        ];

        // 4. Ejecutamos solo los que falten
        foreach ($indicesNecesarios as $nombre => $sql) {
            if (!in_array($nombre, $indicesExistentes, true)) {
                $this->db->query($sql);
            }
        }
    }
}