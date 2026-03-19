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

    /**
     * Campos canónicos para detectar la fila de headers.
     * Cuántos de estos aparezcan en una fila → más probable que sea el header.
     * Incluye variantes electorales, DENUE, padrón social, resultados.
     */
    private array $camposCanonicos = [
        // Identificadores
        'clee', 'curp', 'rfc', 'folio', 'clave', 'matricula', 'expediente',
        'id', 'id_unico', 'id_beneficiario', 'id_municipio', 'num_folio',
        // Nombre
        'nombre', 'nombre_completo', 'nombres', 'beneficiario', 'nom_estab',
        'razon_social', 'titular', 'propietario',
        // Apellidos
        'paterno', 'materno', 'apellido', 'apellido_paterno', 'apellido_materno',
        // Ubicación
        'municipio', 'nom_mun', 'delegacion', 'alcaldia', 'entidad', 'estado',
        'calle', 'colonia', 'direccion',
        // Postal / sección
        'cp', 'c_p', 'codigo_postal', 'cod_post', 'zip', 'zip_code', 'postal_code',
        'seccion', 'seccion_electoral', 'casilla', 'distrito',
        // Geo
        'latitud', 'longitud', 'lat', 'lon', 'lng',
        // Electoral (resultados)
        'pan', 'pri', 'prd', 'morena', 'pvem', 'pt', 'mc', 'naem', 'pes', 'rsp', 'fxm',
        'votos', 'lista_nominal', 'total_votos', 'votos_validos', 'participacion',
        'candidato', 'siglas', 'votacion', 'porcentaje', 'porcentual', 'margen',
        'coalicion', 'candidatura', 'cand-ind1',
        // Varios
        'telefono', 'email', 'fecha_alta', 'fecha_nacimiento', 'sexo', 'genero',
        'id_distrito_local',
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
        $resumen = ['procesados' => 0, 'errores' => 0, 'omitidos' => 0];
        $batch   = [];

        $this->optimizarMysql();

        if (!file_exists($rutaArchivo)) {
            throw new RuntimeException("Archivo CSV no encontrado: {$rutaArchivo}");
        }

        $handle = fopen($rutaArchivo, 'r');
        if (!$handle) {
            throw new RuntimeException("No se pudo abrir el archivo CSV.");
        }

        $headers = $this->detectarHeaders($handle);

        if (!$headers) {
            fclose($handle);
            throw new RuntimeException("CSV sin encabezados válidos o archivo vacío.");
        }

        $numHeaders = count($headers);

        try {
            while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {

                // Saltar filas completamente vacías
                if (empty(array_filter($fila, fn($v) => trim((string)$v) !== ''))) {
                    $resumen['omitidos']++;
                    continue;
                }

                // Alinear fila con headers (rellenar o truncar)
                $filaAlineada = $this->alinearFila($fila, $numHeaders);
                $data         = array_combine($headers, $filaAlineada);

                $registro = $this->mapper->mapear($data, $uuidCatalogo);

                // ── Filtro de calidad ──────────────────────────────────────────
                // Calcular "puntos de verdad" — campos estructurados con valor real
                $puntosDeVerdad = 0;
                if (!empty($registro['nombre_completo']) && $registro['nombre_completo'] !== 'SIN NOMBRE') $puntosDeVerdad += 2;
                if (!empty($registro['municipio']))    $puntosDeVerdad += 2;
                if (!empty($registro['clave_unica']) && $registro['estatus_duplicidad'] !== 'generado_por_sistema') $puntosDeVerdad += 2;
                if (!empty($registro['seccion']))      $puntosDeVerdad++;
                if (!empty($registro['codigo_postal'])) $puntosDeVerdad++;

                // Contar campos en datos_generales con valor real
                $numExtras = 0;
                if ($registro['datos_generales']) {
                    $extras    = json_decode($registro['datos_generales'], true) ?? [];
                    $numExtras = count(array_filter($extras, fn($v) => trim((string)$v) !== ''));
                }

                // Descartar si: 0 puntos de verdad Y menos de 3 campos extras con valor
                // Esto captura filas de totales, separadores, notas al pie, etc.
                if ($puntosDeVerdad === 0 && $numExtras < 3) {
                    $resumen['omitidos']++;
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
            throw new RuntimeException(
                "Error cerca del registro #{$resumen['procesados']}: " . $e->getMessage()
            );
        }

        fclose($handle);
        $this->restaurarConfiguracionMysql();

        return $resumen;
    }

    // =========================================================
    // DETECCIÓN DE HEADERS
    // =========================================================

    /**
     * Busca la fila con más coincidencias de campos canónicos.
     * Ventana de inspección: primeras 60 filas.
     *
     * Mejoras respecto a la versión anterior:
     * - Normalización más agresiva (tildes, guiones, espacios múltiples)
     * - Score parcial: coincidencia de subcadena también cuenta (peso 0.5)
     * - Fallback mejorado: usa la primera fila con ≥2 celdas no vacías
     */
    private function detectarHeaders($handle): ?array
    {
        rewind($handle);

        $mejorFila      = null;
        $mejorScore     = -1;
        $posTrasMejor   = 0;

        $primeraFilaNoVacia = null;
        $posTrasPrimera     = 0;

        $filasEscaneadas = 0;
        $maxEscaneo      = 60;

        while ($filasEscaneadas < $maxEscaneo
            && ($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {

            $posActual = ftell($handle);
            $filaNorm  = $this->normalizarHeaders($fila);
            $noVacias  = array_filter($filaNorm, fn($v) => trim((string)$v) !== '');

            if (empty($noVacias)) {
                $filasEscaneadas++;
                continue;
            }

            if ($primeraFilaNoVacia === null) {
                $primeraFilaNoVacia = $filaNorm;
                $posTrasPrimera     = $posActual;
            }

            $score = 0.0;
            foreach ($filaNorm as $celda) {
                $celda = trim($celda);
                if ($celda === '') continue;

                // Coincidencia exacta → peso 1
                if (in_array($celda, $this->camposCanonicos, true)) {
                    $score += 1.0;
                    continue;
                }

                // Coincidencia parcial → peso 0.4
                foreach ($this->camposCanonicos as $canon) {
                    if (str_contains($celda, $canon) || str_contains($canon, $celda)) {
                        $score += 0.4;
                        break;
                    }
                }
            }

            if ($score > $mejorScore && $score >= 1.5) {
                $mejorScore   = $score;
                $mejorFila    = $filaNorm;
                $posTrasMejor = $posActual;
            }

            $filasEscaneadas++;
        }

        if ($mejorFila !== null) {
            fseek($handle, $posTrasMejor);
            return $this->hacerHeadersUnicos($mejorFila);
        }

        if ($primeraFilaNoVacia !== null) {
            fseek($handle, $posTrasPrimera);
            return $this->hacerHeadersUnicos($primeraFilaNoVacia);
        }

        return null;
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Alinea la fila de datos con el número de headers.
     * - Si hay más columnas que headers: truncar (datos extra se pierden)
     * - Si hay menos columnas que headers: rellenar con vacíos
     *
     * Esto soluciona el "desface de celdas" cuando hay columnas combinadas.
     */
    private function alinearFila(array $fila, int $numHeaders): array
    {
        $len = count($fila);
        if ($len === $numHeaders) return $fila;
        if ($len > $numHeaders)  return array_slice($fila, 0, $numHeaders);

        // Rellenar con vacíos
        return array_merge($fila, array_fill(0, $numHeaders - $len, ''));
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
            $h = (trim($h) === '' || is_numeric($h)) ? 'columna' : trim($h);
            $h = preg_replace('/\s+/', ' ', $h); // Normalizar espacios múltiples
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
            $h = mb_convert_encoding(
                (string)($header ?? ''),
                'UTF-8',
                'UTF-8, ISO-8859-1, Windows-1252'
            );
            $h = preg_replace('/[\x00-\x1F\x7F]/u', '', $h);
            $h = str_replace("\xEF\xBB\xBF", '', $h);     // BOM
            $h = mb_strtolower(trim($h));
            $h = preg_replace('/\s+/', '_', $h);            // espacios → guión bajo
            // Quitar tildes para matching
            $h = strtr($h, [
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
                'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
                'ñ'=>'n','ç'=>'c',
            ]);
            $resultado[] = $h;
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