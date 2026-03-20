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

    // =========================================================
    // IMPORTACIÓN AUTOMÁTICA (IA-like)
    // =========================================================
    public function importar(string $tabla, string $uuidCatalogo, string $rutaArchivo): array
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $this->optimizarMysql();

        if (!file_exists($rutaArchivo)) {
            throw new RuntimeException("Archivo no encontrado");
        }

        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) throw new RuntimeException("No se pudo abrir archivo");

        $headers = $this->detectarHeaders($handle);
        if (!$headers) throw new RuntimeException("Sin headers válidos");

        $numHeaders = count($headers);
        $builder = $this->db->table($tabla);

        $batch = [];
        $resumen = ['procesados'=>0,'omitidos'=>0,'errores'=>0];

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {

                if ($this->filaVacia($fila)) {
                    $resumen['omitidos']++;
                    continue;
                }

                $fila = $this->alinearFila($fila, $numHeaders);
                $data = array_combine($headers, $fila);

                $registro = $this->mapper->mapear($data, $uuidCatalogo);

                if ($this->registroEsBasura($registro)) {
                    $resumen['omitidos']++;
                    continue;
                }

                $batch[] = $registro;
                $resumen['procesados']++;

                if (count($batch) >= $this->batchSize) {
                    $this->insertBatchSeguro($builder, $batch);
                    $batch = [];
                }
            }

            if ($batch) $this->insertBatchSeguro($builder, $batch);

            $this->db->query("COMMIT");

        } catch (Exception $e) {
            $this->db->query("ROLLBACK");
            fclose($handle);
            $this->restaurarMysql();
            throw new RuntimeException($e->getMessage());
        }

        fclose($handle);
        $this->restaurarMysql();

        return $resumen;
    }

    // =========================================================
    // IMPORTACIÓN MANUAL (UI Mapping)
    // =========================================================
    public function importarConMapeo(string $tabla, string $uuid, string $ruta, array $mapeo, array $filasIgnoradas = []): array
    {
        set_time_limit(0);
        $this->optimizarMysql();

        $handle = fopen($ruta, 'r');
        if (!$handle) throw new RuntimeException("No se pudo abrir archivo");

        // detectarHeaders ya hace un rewind y salta la fila de encabezados
        $headers = $this->detectarHeaders($handle);
        if (!$headers) throw new RuntimeException("Sin headers");

        $numHeaders = count($headers);
        $builder = $this->db->table($tabla);

        $batch = [];
        $resumen = ['procesados' => 0, 'omitidos' => 0];
        
        // Este índice debe coincidir exactamente con el 'ri' (row index) de tu v-for en Vue
        $indiceFilaFisica = 0; 

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                

                if (in_array($indiceFilaFisica, $filasIgnoradas)) {
                    $resumen['omitidos']++;
                    $indiceFilaFisica++; 
                    continue;           
                }

                // 2. ¿La fila está vacía en el CSV?
                if ($this->filaVacia($fila)) {
                    $resumen['omitidos']++;
                    $indiceFilaFisica++; 
                    continue;
                }

                // Si llegamos aquí, la fila es válida para procesar
                $indiceFilaFisica++;

                $fila = $this->alinearFila($fila, $numHeaders);
                $data = array_combine($headers, $fila);

                if (method_exists($this->mapper, 'mapearManual')) {
                    $registro = $this->mapper->mapearManual($data, $uuid, $mapeo);
                } else {
                    $registro = $this->mapper->mapear($data, $uuid);
                }

                if (empty($registro)) {
                    $resumen['omitidos']++;
                    continue;
                }

                $batch[] = $registro;
                $resumen['procesados']++;

                if (count($batch) >= $this->batchSize) {
                    $this->insertBatchSeguro($builder, $batch);
                    $batch = [];
                }
            }

            if ($batch) $this->insertBatchSeguro($builder, $batch);
            $this->db->query("COMMIT");

        } catch (Exception $e) {
            $this->db->query("ROLLBACK");
            fclose($handle);
            $this->restaurarMysql();
            throw new RuntimeException($e->getMessage());
        }

        fclose($handle);
        $this->restaurarMysql();

        return $resumen;
    }

    // =========================================================
    // HEADERS (UNIFICADO)
    // =========================================================
    public function extraerHeadersPublico($handle): ?array
    {
        return $this->detectarHeaders($handle);
    }

    private function detectarHeaders($handle): ?array
    {
        rewind($handle);

        for ($i = 0; $i < 60; $i++) {
            $fila = fgetcsv($handle, 0, ',', '"', '\\');
            if (!$fila) break;

            $norm = $this->normalizarHeaders($fila);

            if (!$this->filaVacia($norm)) {
                return $this->hacerHeadersUnicos($norm);
            }
        }

        return null;
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function filaVacia(array $fila): bool
    {
        foreach ($fila as $v) {
            if (trim((string)$v) !== '') return false;
        }
        return true;
    }

    private function registroEsBasura(array $r): bool
    {
        return empty($r['nombre_completo']) && empty($r['municipio']);
    }

    private function alinearFila(array $fila, int $numHeaders): array
    {
        $len = count($fila);
        if ($len === $numHeaders) return $fila;
        if ($len > $numHeaders) return array_slice($fila, 0, $numHeaders);
        return array_merge($fila, array_fill(0, $numHeaders - $len, ''));
    }

    private function insertBatchSeguro($builder, array $datos): void
    {
        $this->db->transStart();
        $builder->insertBatch($datos);
        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new RuntimeException("Error en insertBatch");
        }
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $out = [];
        $count = [];

        foreach ($headers as $h) {
            $h = trim($h) ?: 'columna';
            if (isset($count[$h])) {
                $count[$h]++;
                $out[] = "{$h}_{$count[$h]}";
            } else {
                $count[$h] = 1;
                $out[] = $h;
            }
        }

        return $out;
    }

    private function normalizarHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $h = mb_strtolower(trim((string)$h));
            $h = preg_replace('/\s+/', '_', $h);
            return $h;
        }, $headers);
    }

    private function optimizarMysql(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        $this->db->query("SET UNIQUE_CHECKS=0");
        $this->db->query("SET AUTOCOMMIT=0");
    }

    private function restaurarMysql(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=1");
        $this->db->query("SET UNIQUE_CHECKS=1");
        $this->db->query("SET AUTOCOMMIT=1");
    }
}