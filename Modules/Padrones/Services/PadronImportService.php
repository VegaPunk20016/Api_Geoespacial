<?php

namespace Modules\Padrones\Services;

use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

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

        if (!file_exists($rutaArchivo)) {
            throw new RuntimeException("Archivo no encontrado");
        }

        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) throw new RuntimeException("No se pudo abrir archivo");

        $headers = $this->detectarHeaders($handle);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException("Sin headers válidos");
        }

        $numHeaders = count($headers);
        $builder = $this->db->table($tabla);

        $batch = [];
        $resumen = ['procesados' => 0, 'omitidos' => 0, 'errores' => 0];

        // 🛡️ BLOQUEO DE SEGURIDAD: Preparamos la BD para inserción masiva
        $this->optimizarMysql();
        $this->db->query("START TRANSACTION");

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

            // Insertamos el remanente
            if (!empty($batch)) {
                $this->insertBatchSeguro($builder, $batch);
            }

            $this->db->query("COMMIT");

        } catch (Throwable $e) {
            $this->db->query("ROLLBACK");
            throw new RuntimeException("Error durante la importación: " . $e->getMessage());
        } finally {
            // 🛡️ FINALLY: Pase lo que pase (éxito o error fatal), SIEMPRE restauramos la BD y cerramos el archivo
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->restaurarMysql();
        }

        return $resumen;
    }

    // =========================================================
    // IMPORTACIÓN MANUAL (UI Mapping)
    // =========================================================
    public function importarConMapeo(string $tabla, string $uuid, string $ruta, array $mapeo, array $filasIgnoradas = []): array
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $handle = fopen($ruta, 'r');
        if (!$handle) throw new RuntimeException("No se pudo abrir archivo");

        $headers = $this->detectarHeaders($handle);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException("Sin headers");
        }

        $numHeaders = count($headers);
        $builder = $this->db->table($tabla);

        $batch = [];
        $resumen = ['procesados' => 0, 'omitidos' => 0];
        $indiceFilaFisica = 0; 

        // 🛡️ BLOQUEO DE SEGURIDAD
        $this->optimizarMysql();
        $this->db->query("START TRANSACTION");

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                
                if (in_array($indiceFilaFisica, $filasIgnoradas)) {
                    $resumen['omitidos']++;
                    $indiceFilaFisica++; 
                    continue;           
                }

                if ($this->filaVacia($fila)) {
                    $resumen['omitidos']++;
                    $indiceFilaFisica++; 
                    continue;
                }

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

            if (!empty($batch)) {
                $this->insertBatchSeguro($builder, $batch);
            }
            
            $this->db->query("COMMIT");

        } catch (Throwable $e) {
            $this->db->query("ROLLBACK");
            throw new RuntimeException("Error durante la importación manual: " . $e->getMessage());
        } finally {
            // 🛡️ FINALLY
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->restaurarMysql();
        }

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
        // 🚀 Eliminamos transStart/transComplete de aquí.
        // Al usar START TRANSACTION arriba, delegamos el control al bloque Try/Catch principal.
        // Esto evita "Commits" fantasma causados por transacciones anidadas.
        $builder->insertBatch($datos);
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $out = [];
        $count = [];

        foreach ($headers as $h) {
            $h = trim((string)$h) ?: 'columna';
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