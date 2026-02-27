<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class TestConnection extends BaseCommand
{

    protected $group       = 'Tests';
    protected $name        = 'test:connection';
    protected $description = 'Prueba la conexión a la base de datos.';

    public function run(array $params)
    {
        // Conectar a la base de datos
        $db = Database::connect();

        try {
            // Intentar ejecutar una consulta simple
            $db->query('SELECT 1');
            CLI::write('Conexión a la base de datos exitosa!', 'green');
        } catch (\Exception $e) {
            // Mostrar el error si la conexión falla
            CLI::error('Error de conexión: ' . $e->getMessage());
        }
    }
}
