<?php

namespace Modules\Padrones\Services;

use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;
use Exception;

class PadronImportService
{
    private ConnectionInterface $db;
    private PadronMapperService $mapper;
    private int $batchSize = 3000;

    public function __construct(ConnectionInterface $db, PadronMapperService $mapper)
    {
        $this->db = $db;
        $this->mapper = $mapper;
    }

    public function importar(string $tabla, string $uuidCatalogo, string $rutaArchivo): array
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        if (property_exists($this->db, 'saveQueries')) {
            $this->db->saveQueries = false;
        }

        $builder = $this->db->table($tabla);
        $resumen = ['procesados' => 0, 'errores' => 0];
        $batch = [];

        // 🚨 Optimización de MySQL (Sentencias separadas)
        $this->optimizarMysql();

        if (!file_exists($rutaArchivo)) {
            throw new RuntimeException("Archivo CSV no encontrado");
        }

        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) {
            throw new RuntimeException("No se pudo abrir el archivo CSV");
        }

        // 1. BÚSQUEDA DE ANCLA: Reconoce Secciones, Casillas y Partidos Políticos
        $headers = $this->buscarFilaAncla($handle);
        $numHeaders = count($headers);

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                
                // Ignorar filas totalmente vacías
                if (empty(array_filter($fila, fn($v) => trim((string)$v) !== ''))) continue;

                // 2. ESTABILIZADOR: Evita el error "Undefined array key"
                $numFila = count($fila);
                if ($numFila !== $numHeaders) {
                    $fila = array_pad(array_slice($fila, 0, $numHeaders), $numHeaders, '');
                }

                $data = array_combine($headers, $fila);
                
                // 3. MAPEO DINÁMICO
                $registro = $this->mapper->mapear($data, $uuidCatalogo);

                // --- 🚨 FILTRO RELAJADO PARA RESULTADOS ELECTORALES ---
                // Si no tiene nombre, pero tiene SECCIÓN o MUNICIPIO, lo dejamos pasar.
                if ($registro['nombre_completo'] === 'SIN NOMBRE' && empty($registro['municipio']) && empty($registro['seccion'])) {
                    continue; 
                }

                $batch[] = $registro;
                $resumen['procesados']++;

                if (count($batch) >= $this->batchSize) {
                    $builder->insertBatch($batch);
                    $batch = [];
                    gc_collect_cycles(); 
                }
            }

            if (!empty($batch)) {
                $builder->insertBatch($batch);
            }

            $this->db->query("COMMIT");

        } catch (Exception $e) {
            $this->db->query("ROLLBACK");
            fclose($handle);
            $this->restaurarConfiguracionMysql();
            throw new RuntimeException("Error cerca del registro {$resumen['procesados']}: " . $e->getMessage());
        }

        fclose($handle);
        $this->restaurarConfiguracionMysql();

        return $resumen;
    }

    private function buscarFilaAncla($handle): array
    {
        // Palabras clave extendidas para archivos de resultados electorales
        $palabrasClave = [
            'municipio', 'seccion', 'casilla', 'nominal', 'pan', 'pri', 'prd', 'pt', 
            'verde', 'morena', 'mc', 'naem', 'pes', 'rsp', 'fxm', 'total'
        ];
        
        rewind($handle);
        
        while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $filaNorm = $this->normalizarHeaders($fila);
            $coincidencias = 0;
            
            foreach ($filaNorm as $celda) {
                foreach ($palabrasClave as $p) {
                    if (strpos((string)$celda, $p) !== false) {
                        $coincidencias++;
                        break;
                    }
                }
            }
            
            // Si hay 3 o más coincidencias, estamos en la fila de encabezados
            if ($coincidencias >= 3) {
                return $this->hacerHeadersUnicos($filaNorm);
            }
        }
        throw new RuntimeException("No se detectó la cabecera electoral (Secciones/Partidos) en el archivo.");
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $conteo = [];
        $unicos = [];
        foreach ($headers as $h) {
            // Manejar columnas vacías o numéricas en los headers (logos)
            $h = ($h === '' || is_numeric($h)) ? 'columna' : $h;
            if (isset($conteo[$h])) {
                $conteo[$h]++;
                $unicos[] = $h . '_' . $conteo[$h];
            } else {
                $conteo[$h] = 1;
                $unicos[] = $h;
            }
        }
        return $unicos;
    }

    private function normalizarHeaders(array $headers): array
    {
        $resultado = [];
        foreach ($headers as $header) {
            $header = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$header));
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            $resultado[] = mb_strtolower($header);
        }
        return $resultado;
    }

    private function optimizarMysql() 
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        $this->db->query("SET UNIQUE_CHECKS=0");
        $this->db->query("SET sql_log_bin=0");
        $this->db->query("SET AUTOCOMMIT=0");
    }

    private function restaurarConfiguracionMysql()
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=1");
        $this->db->query("SET UNIQUE_CHECKS=1");
        $this->db->query("SET sql_log_bin=1");
        $this->db->query("SET AUTOCOMMIT=1");
    }
}