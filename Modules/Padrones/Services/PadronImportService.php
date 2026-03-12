<?php

namespace Modules\Padrones\Services;

use CodeIgniter\Database\ConnectionInterface;
use Modules\Padrones\Services\PadronMapperService;
use RuntimeException;
use Exception;

class PadronImportService
{
    private ConnectionInterface $db;
    private PadronMapperService $mapper;
    private int $batchSize = 3000;

    // Campos canónicos — si una fila tiene ≥3 de estos, ES el header
    private array $camposCanonicos = [
        'clee','curp','rfc','folio','clave','matricula','expediente',
        'nom_estab','nombre_completo','razon_social','beneficiario',
        'municipio','nom_mun','delegacion','alcaldia',
        'seccion','casilla','casillas','distrito',
        'entidad','estado','nom_ent','nombre_estado','id_estado',
        'latitud','longitud','lat','lon','lng',
        'pan','pri','prd','morena','pvem','pt','mc',
        'nombre','paterno','materno','apellido',
        'calle','colonia','cp','telefono','email','fecha_alta',
        'id_municipio','id_distrito_local','lista_nominal',
    ];

    public function __construct(ConnectionInterface $db, PadronMapperService $mapper)
    {
        $this->db     = $db;
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
        $batch   = [];

        $this->optimizarMysql();

        if (!file_exists($rutaArchivo)) {
            throw new RuntimeException("Archivo CSV no encontrado");
        }

        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) {
            throw new RuntimeException("No se pudo abrir el archivo CSV");
        }

        $headers = $this->detectarHeaders($handle);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException("CSV sin encabezados o archivo vacío.");
        }

        $numHeaders = count($headers);

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {

                if (empty(array_filter($fila, fn($v) => trim((string)$v) !== ''))) continue;

                if (count($fila) !== $numHeaders) {
                    $fila = array_pad(array_slice($fila, 0, $numHeaders), $numHeaders, '');
                }

                $data     = array_combine($headers, $fila);
                $registro = $this->mapper->mapear($data, $uuidCatalogo);

                if ($registro['nombre_completo'] === 'SIN NOMBRE'
                    && empty($registro['municipio'])
                    && empty($registro['seccion'])
                    && empty($registro['clave_unica'])
                ) {
                    continue;
                }

                $batch[] = $registro;
                $resumen['procesados']++;

                if (count($batch) >= $this->batchSize) {
                    $this->insertBatchSeguro($builder, $batch);
                    $batch = [];
                    gc_collect_cycles();
                }
            }

            if (!empty($batch)) {
                $this->insertBatchSeguro($builder, $batch);
            }

            $this->db->query("COMMIT");

        } catch (Exception $e) {
            $this->db->query("ROLLBACK");
            fclose($handle);
            $this->restaurarConfiguracionMysql();
            throw new RuntimeException("Error cerca del registro #{$resumen['procesados']}: " . $e->getMessage());
        }
log_message('debug', '[HEADERS] ' . json_encode($headers));
        fclose($handle);
        $this->restaurarConfiguracionMysql();

        return $resumen;
    }

    /**
     * Detecta la fila real de headers en el CSV.
     *
     * Estrategia:
     * 1. Lee fila por fila contando coincidencias con campos canónicos.
     * 2. La primera fila con ≥3 coincidencias ES el header — para ahí.
     * 3. Si ninguna fila tiene ≥3 coincidencias, usa la primera no vacía
     *    (fallback para CSVs simples sin campos conocidos).
     *
     * El puntero queda exactamente DESPUÉS de la fila de headers,
     * listo para leer datos.
     */
    private function detectarHeaders($handle): ?array
    {
        rewind($handle);

        $primeraFila = null;
        $primeraPos  = null;

        while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $posTrasFila = ftell($handle);
            $filaNorm    = $this->normalizarHeaders($fila);
            $noVacias    = array_filter($filaNorm, fn($v) => $v !== '');

            if (empty($noVacias)) continue;

            // Guardar primera fila no vacía como fallback
            if ($primeraFila === null) {
                $primeraFila = $filaNorm;
                $primeraPos  = $posTrasFila;
            }

            // Contar coincidencias con campos canónicos
            $score = 0;
            foreach ($filaNorm as $celda) {
                if (in_array($celda, $this->camposCanonicos, true)) {
                    $score++;
                }
            }

            // ≥3 coincidencias = esta fila ES el header con certeza
            if ($score >= 3) {
                // El puntero ya está después de esta fila — perfecto
                return $this->hacerHeadersUnicos($filaNorm);
            }
        }

        // Fallback: usar primera fila (CSVs simples sin campos conocidos)
        if ($primeraFila !== null) {
            fseek($handle, $primeraPos);
            return $this->hacerHeadersUnicos($primeraFila);
        }

        return null;
    }

    private function insertBatchSeguro($builder, array $datos): void
    {
        $this->db->transStart();
        $builder->insertBatch($datos);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            $error = $this->db->error();
            $msg   = !empty($error['message']) ? $error['message'] : 'Error desconocido en batch.';
            throw new RuntimeException("SQL Error {$error['code']}: {$msg}");
        }
    }

    private function hacerHeadersUnicos(array $headers): array
    {
        $conteo = [];
        $unicos = [];
        foreach ($headers as $h) {
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
            $h = mb_convert_encoding((string)($header ?? ''), 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $h = preg_replace('/[\x00-\x1F\x7F]/u', '', $h);
            $h = str_replace("\xEF\xBB\xBF", '', $h);
            $resultado[] = mb_strtolower(trim((string)$h));
        }
        return $resultado;
    }

    private function optimizarMysql(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        $this->db->query("SET UNIQUE_CHECKS=0");
        $this->db->query("SET sql_log_bin=0");
        $this->db->query("SET AUTOCOMMIT=0");
    }

    private function restaurarConfiguracionMysql(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS=1");
        $this->db->query("SET UNIQUE_CHECKS=1");
        $this->db->query("SET sql_log_bin=1");
        $this->db->query("SET AUTOCOMMIT=1");
    }
}