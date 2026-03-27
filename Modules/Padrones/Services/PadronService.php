<?php

namespace Modules\Padrones\Services;

use Modules\Padrones\Models\CatalogoPadronModel;
use Modules\Padrones\Interfaces\PadronServiceInterface;
use Modules\Padrones\Models\BeneficiarioDinamicoModel;
use Modules\Padrones\DTOs\CreatePadronRequest;
use Modules\Padrones\DTOs\ImportCsvRequest;
use Modules\Padrones\Entities\CatalogoPadron;
use Modules\Padrones\Services\FileConverterService;
use Ramsey\Uuid\Uuid;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Cache\CacheInterface;
use RuntimeException;

class PadronService implements PadronServiceInterface
{
    private CatalogoPadronModel       $catalogoModel;
    private BeneficiarioDinamicoModel $modeloDinamico;
    private ConnectionInterface       $db;
    private PadronTableService        $tableService;
    private PadronImportService       $importService;
    private FileConverterService      $converterService;
    private CacheInterface            $cache;
    private PadronMapperService       $mapper;

    private const BBOX_PRECISION = 2;
    private const TTL_BBOX       = 300;
    private const TTL_LISTA      = 600;
    private const TTL_RESUMEN    = 900;

    private const LIMIT_PUNTOS   = 5000;
    private const LIMIT_DEFAULT  = 1000;
    private const LIMIT_CLUSTERS = 2000;

    public function __construct(
        CatalogoPadronModel       $catalogoModel,
        BeneficiarioDinamicoModel $modeloDinamico,
        ConnectionInterface       $db,
        PadronTableService        $tableService,
        PadronImportService       $importService,
        FileConverterService      $converterService,
        CacheInterface            $cache,
        PadronMapperService       $mapper
    ) {
        $this->catalogoModel    = $catalogoModel;
        $this->modeloDinamico   = $modeloDinamico;
        $this->db               = $db;
        $this->tableService     = $tableService;
        $this->importService    = $importService;
        $this->converterService = $converterService;
        $this->cache            = $cache;
        $this->mapper           = $mapper;
    }

    // =========================================================
    // CREAR PADRÓN
    // =========================================================

    public function crearNuevoPadron(CreatePadronRequest $datos): CatalogoPadron
    {
        $uuid         = Uuid::uuid7()->toString();
        $nombreLimpio = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $datos->nombre_padron));
        $nombreTabla  = substr('padron_' . trim($nombreLimpio, '_'), 0, 60);

        $padron = new CatalogoPadron([
            'id'                   => $uuid,
            'nombre_padron'        => $datos->nombre_padron,
            'descripcion'          => $datos->descripcion,
            'clave_interna'        => $datos->clave_interna,
            'entidad_federativa'   => $datos->entidad_federativa,
            'categoria'            => $datos->categoria ?? 'General',
            'nombre_tabla_destino' => $nombreTabla,
        ]);

        $this->db->transStart();

        if (!$this->catalogoModel->insert($padron)) {
            throw new RuntimeException('Error al registrar el padrón.');
        }

        $this->tableService->crearTabla($nombreTabla, $uuid);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            throw new RuntimeException('Error crítico al crear el Padrón y su tabla física.');
        }

        $this->cache->delete('padrones_todos');

        return $this->catalogoModel->find($uuid);
    }

    // =========================================================
    // IMPORTAR CSV / XLSX  (automático)
    // =========================================================

    public function procesarCargaMasiva(ImportCsvRequest $request): array
    {
        $padron = $this->catalogoModel->find($request->catalogo_padron_id);
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        $rutaOriginal = $request->getTempPath();
        $extension    = $request->getExtensionOriginal();
        $rutaCsvFinal = $this->converterService->prepararCsv($rutaOriginal, $extension);

        $resultado = $this->importService->importar(
            $padron->nombre_tabla_destino,
            $request->catalogo_padron_id,
            $rutaCsvFinal
        );

        if ($rutaCsvFinal !== $rutaOriginal && file_exists($rutaCsvFinal)) {
            unlink($rutaCsvFinal);
        }

        $this->invalidarCachePadron($request->catalogo_padron_id);

        return $resultado;
    }

    // =========================================================
    // PREVIEW — extrae headers + muestra sin importar nada
    // =========================================================

    public function previsualizarArchivo(ImportCsvRequest $request): array
    {
        $rutaOriginal = $request->getTempPath();
        $extension    = strtolower($request->getExtensionOriginal());

        // 1. Convertir a CSV limpio (elimina basura, filas vacías, etc.)
        $rutaCsv = $this->converterService->prepararCsv($rutaOriginal, $extension);

        $handle = fopen($rutaCsv, 'r');
        if (!$handle) {
            throw new RuntimeException("No se pudo abrir el archivo convertido.");
        }

        // 2. Detectar headers reales
        $headers = $this->importService->extraerHeadersPublico($handle);

        if (!$headers) {
            fclose($handle);
            if ($rutaCsv !== $rutaOriginal && file_exists($rutaCsv)) {
                unlink($rutaCsv);
            }
            throw new RuntimeException("No se detectaron encabezados válidos.");
        }

        $muestra    = [];
        $totalFilas = 0;
        $maxMuestra = 10;
        $numHeaders = count($headers);

        // 3. Leer datos reales (ignorando filas vacías)
        while (($fila = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {

            if (empty(array_filter($fila, fn($v) => trim((string)$v) !== ''))) {
                continue;
            }

            $totalFilas++;

            if (count($muestra) < $maxMuestra) {

                // Alinear fila con headers (CRÍTICO)
                $filaAlineada = array_slice(
                    array_merge($fila, array_fill(0, $numHeaders, '')),
                    0,
                    $numHeaders
                );

                $muestra[] = array_combine($headers, $filaAlineada);
            }
        }

        fclose($handle);

        $previewKey = 'preview_' . md5($request->catalogo_padron_id . basename($rutaOriginal) . uniqid());
        $rutaGuardada = sys_get_temp_dir() . '/' . $previewKey . '.csv';

        if ($rutaCsv !== $rutaOriginal) {
            rename($rutaCsv, $rutaGuardada);
        } else {
            copy($rutaCsv, $rutaGuardada);
        }

        return [
            'headers'    => $headers,
            'muestra'    => $muestra,
            'totalFilas' => $totalFilas,
            'previewKey' => $previewKey,
            'extensionOriginal' => $extension,
        ];
    }

    // =========================================================
    // IMPORTAR CON MAPEO MANUAL DEL USUARIO
    // =========================================================

    public function importarConMapeoManual(string $padronId, array $mapeo): array
    {
        // 1. Buscar el padrón en el catálogo
        $padron = $this->catalogoModel->find($padronId);
        if (!$padron) {
            throw new \RuntimeException("El padrón con ID {$padronId} no existe.");
        }

        // 2. Extraer metadatos y señales del frontend
        $previewKey        = $mapeo['__previewKey__'] ?? null;
        $filasIgnoradas    = $mapeo['__filasIgnoradas__'] ?? [];
        $guardarPlantilla  = $mapeo['__guardarPlantilla__'] ?? false;
        // 🔍 Capturamos la extensión original (enviada desde el frontend)
        $extensionOriginal = $mapeo['__extensionOriginal__'] ?? null;

        // 3. Limpiar el arreglo de mapeo para dejar solo las columnas reales de datos
        unset($mapeo['__previewKey__']);
        unset($mapeo['__filasIgnoradas__']);
        unset($mapeo['__guardarPlantilla__']);
        unset($mapeo['__extensionOriginal__']);

        // 4. Localizar el archivo CSV temporal generado en la previsualización
        $rutaCsv = null;
        $esTemporal = false;

        if ($previewKey) {
            $rutaGuardada = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $previewKey . '.csv';
            if (file_exists($rutaGuardada)) {
                $rutaCsv    = $rutaGuardada;
                $esTemporal = true;
            }
        }

        // Si el temporal no existe (por tiempo o limpieza de sistema), obligamos a re-subir
        if (!$rutaCsv) {
            throw new \RuntimeException("El archivo temporal ha expirado o no se encontró. Por favor, cargue el archivo nuevamente.");
        }

        // 5. Ejecutar la importación real a la tabla dinámica
        $resultado = $this->importService->importarConMapeo(
            $padron->nombre_tabla_destino,
            $padronId,
            $rutaCsv,
            $mapeo,
            $filasIgnoradas
        );

        // 6. 🔥 MEMORIA DE MAPEADO Y FORMATO (Acumulativo)
        if ($guardarPlantilla === true || $guardarPlantilla === 'true' || $guardarPlantilla === 1) {

            // A. Recuperamos la memoria actual (gracias al cast JSON en la Entity, esto es un array)
            $plantillaActual = $padron->plantilla_mapeo ?? [];

            // B. Normalizamos las llaves para mejorar la detección futura (columna_1 -> columna1)
            $mapeoNormalizado = [];
            foreach ($mapeo as $key => $value) {
                if (empty($value)) continue;

                // Guardamos la relación literal
                $mapeoNormalizado[$key] = $value;

                // Guardamos la versión normalizada para cruce entre formatos (CSV vs XLSX)
                $keyNorm = preg_replace('/[^a-z0-9]/', '', strtolower($key));
                if (!empty($keyNorm) && $keyNorm !== $key) {
                    $mapeoNormalizado[$keyNorm] = $value;
                }
            }

            // C. Combinamos sin sobrescribir lo que ya sabíamos (Acumulativo)
            $padron->plantilla_mapeo = array_merge($plantillaActual, $mapeoNormalizado);

            // D. Establecemos el formato oficial del padrón si aún no tiene uno
            if (empty($padron->formato_esperado) && $extensionOriginal) {
                // Guardamos la extensión original (xlsx, csv, etc.)
                $padron->formato_esperado = strtolower($extensionOriginal);
            }

            // E. Persistimos los cambios en el catálogo
            $this->catalogoModel->save($padron);
        }

        // 7. Limpieza post-importación del archivo temporal
        if ($esTemporal && file_exists($rutaCsv)) {
            unlink($rutaCsv);
        }

        // Invalidar cualquier caché de este padrón (mapas, totales, listas)
        $this->invalidarCachePadron($padronId);

        return $resultado;
    }
    // =========================================================
    // OBTENER TODOS LOS PADRONES
    // =========================================================

    public function obtenerTodosLosPadrones(): array
    {
        $cached = $this->cache->get('padrones_todos');
        if ($cached !== null) return $cached;

        $padrones = $this->catalogoModel
            ->orderBy('created_at', 'DESC')
            ->findAll();

        $this->cache->save('padrones_todos', $padrones, self::TTL_LISTA);

        return $padrones;
    }

    // =========================================================
    // OBTENER PADRÓN POR ID
    // =========================================================

    public function obtenerPadronPorId(string $id): ?CatalogoPadron
    {
        return $this->catalogoModel->find($id);
    }

    // =========================================================
    // OBTENER BENEFICIARIOS
    // Modo mapa  → BBOX = true  → límite fijo, sin paginación
    // Modo tabla → BBOX = false → paginación con pagina + por_pagina
    // =========================================================

   public function obtenerBeneficiarios(string $id, array $filtros = []): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new \RuntimeException("El padrón solicitado no existe.");

        $minLat = $filtros['min_lat'] ?? null;
        $maxLat = $filtros['max_lat'] ?? null;
        $minLng = $filtros['min_lng'] ?? null;
        $maxLng = $filtros['max_lng'] ?? null;

        $tieneBbox = ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null);

        $municipioFiltro = $filtros['municipio'] ?? $filtros['municipio_cvegeo'] ?? null;
        $municipio = ($municipioFiltro !== null && $municipioFiltro !== 'null' && $municipioFiltro !== '')
            ? trim($municipioFiltro)
            : '';

        $cp = isset($filtros['codigo_postal']) ? trim($filtros['codigo_postal']) : '';

        $paginar   = !$tieneBbox && isset($filtros['pagina']);
        $pagina    = max(1, (int)($filtros['pagina'] ?? 1));
        $porPagina = max(10, min(200, (int)($filtros['por_pagina'] ?? 50)));
        $offset    = ($pagina - 1) * $porPagina;

        // Construcción de llave de caché
        if ($tieneBbox) {
            $p        = self::BBOX_PRECISION;
            $minLatR  = round((float)$minLat, $p);
            $maxLatR  = round((float)$maxLat, $p);
            $minLngR  = round((float)$minLng, $p);
            $maxLngR  = round((float)$maxLng, $p);
            $munSlug  = $municipio ? '_' . md5($municipio) : '';
            $cpSlug   = $cp ? '_cp' . $cp : '';
            $cacheKey = "bbox_{$id}_{$minLatR}_{$maxLatR}_{$minLngR}_{$maxLngR}{$munSlug}{$cpSlug}";
        } else {
            $munSlug    = $municipio ? '_' . md5($municipio) : '';
            $cpSlug     = $cp ? '_cp' . $cp : '';
            $paginaSlug = $paginar ? "_p{$pagina}x{$porPagina}" : '';
            $cacheKey   = "beneficiarios_{$id}_default{$munSlug}{$cpSlug}{$paginaSlug}";
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $builder = $this->modeloDinamico->select(
            'id, clave_unica, nombre_completo, municipio, codigo_postal, seccion, latitud, longitud, datos_generales'
        );

        // 🚀 MAGIA ESPACIAL: Filtrado a la velocidad de la luz usando el polígono de la pantalla
        if ($tieneBbox) {
            $minLatF = (float)$minLat;
            $maxLatF = (float)$maxLat;
            $minLngF = (float)$minLng;
            $maxLngF = (float)$maxLng;
            $envelope = "ST_MakeEnvelope(Point({$minLngF}, {$minLatF}), Point({$maxLngF}, {$maxLatF}))";
            $builder->where("ST_Contains({$envelope}, geo_point)", null, false);
        }

        // 🚀 BÚSQUEDA EXACTA: Usa los índices idx_mun y idx_cp de MySQL en lugar de escanear toda la tabla
        if ($municipio !== '') {
            $builder->where('municipio', $municipio);
        }

        if ($cp !== '') {
            $builder->where('codigo_postal', $cp);
        }

        if ($paginar) {
            $total     = (clone $builder)->countAllResults(false);
            $registros = $builder->findAll($porPagina, $offset);

            $respuesta = [
                'data'       => $registros,
                'paginacion' => [
                    'total'      => (int)$total,
                    'pagina'     => $pagina,
                    'por_pagina' => $porPagina,
                    'paginas'    => (int)ceil($total / $porPagina),
                ],
            ];
        } else {
            $registros = $builder->findAll($tieneBbox ? self::LIMIT_PUNTOS : self::LIMIT_DEFAULT);
            $respuesta = [
                'data'       => $registros,
                'paginacion' => null,
            ];
        }

        $this->cache->save($cacheKey, $respuesta, self::TTL_BBOX);
        $this->registrarClaveCache($id, $cacheKey);

        return $respuesta;
    }

    // =========================================================
    // DETALLE DE UN BENEFICIARIO
    // =========================================================

    public function obtenerDetalleBeneficiario(string $padronId, string $beneficiarioId): ?array
    {
        $padron = $this->catalogoModel->find($padronId);
        if (!$padron) throw new \RuntimeException("El padrón solicitado no existe.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        return $this->modeloDinamico
            ->select('id, clave_unica, nombre_completo, municipio, codigo_postal, seccion, latitud, longitud, datos_generales')
            ->find($beneficiarioId);
    }

    // =========================================================
    // CLUSTERS DEL SERVIDOR (zoom bajo)
    // =========================================================

    public function obtenerClusters(string $id, array $filtros = []): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new \RuntimeException("El padrón no existe.");

        $minLat = $filtros['min_lat'] ?? null;
        $maxLat = $filtros['max_lat'] ?? null;
        $minLng = $filtros['min_lng'] ?? null;
        $maxLng = $filtros['max_lng'] ?? null;

        $tieneBbox = ($minLat !== null && $maxLat !== null && $minLng !== null && $maxLng !== null);

        $zoom = (int)($filtros['zoom'] ?? 10);

        if ($zoom <= 9)      $precision = 1;
        elseif ($zoom <= 11) $precision = 2;
        else                 $precision = 3;

        $municipioFiltro = $filtros['municipio'] ?? $filtros['municipio_cvegeo'] ?? null;
        $municipio = ($municipioFiltro !== null && $municipioFiltro !== 'null' && $municipioFiltro !== '')
            ? trim($municipioFiltro)
            : '';

        $munSlug  = $municipio ? '_' . md5($municipio) : '';
        $cacheKey = "clusters_{$id}_z{$zoom}{$munSlug}";

        if ($tieneBbox) {
            $cacheKey .= '_' . round((float)$minLat, 1)
                . '_' . round((float)$maxLat, 1)
                . '_' . round((float)$minLng, 1)
                . '_' . round((float)$maxLng, 1);
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);

        $builder = $this->db->table($tabla)
            ->select("ROUND(latitud,  {$precision}) AS lat",   false)
            ->select("ROUND(longitud, {$precision}) AS lng",   false)
            ->select('COUNT(id) AS count',                     false)
            ->select('MIN(nombre_completo) AS nombre_muestra', false)
            ->select('MIN(municipio) AS municipio',            false)
            ->where('latitud IS NOT NULL', null, false); // Ignora Null Island de forma rápida

        if ($tieneBbox) {
            $minLatF = (float)$minLat;
            $maxLatF = (float)$maxLat;
            $minLngF = (float)$minLng;
            $maxLngF = (float)$maxLng;
            $envelope = "ST_MakeEnvelope(Point({$minLngF}, {$minLatF}), Point({$maxLngF}, {$maxLatF}))";
            $builder->where("ST_Contains({$envelope}, geo_point)", null, false);
        }

        if ($municipio !== '') {
            $builder->where('municipio', $municipio);
        }

        $resultado = $builder
            ->groupBy("ROUND(latitud, {$precision}), ROUND(longitud, {$precision})", false)
            ->orderBy('count', 'DESC')
            ->limit(self::LIMIT_CLUSTERS)
            ->get()
            ->getResultArray();

        $this->cache->save($cacheKey, $resultado, self::TTL_BBOX);
        $this->registrarClaveCache($id, $cacheKey);

        return $resultado;
    }

    // =========================================================
    // ELIMINAR PADRÓN
    // =========================================================

    public function eliminarPadron(string $idPadron, bool $permanente = false): bool
    {
        $padron = $this->catalogoModel->withDeleted()->find($idPadron);
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        if ($permanente) {
            $nombreFk = 'fk_cp_' . substr($idPadron, 0, 8);
            $tabla    = $this->db->escapeIdentifier($padron->nombre_tabla_destino);

            try {
                $this->db->query("ALTER TABLE {$tabla} DROP FOREIGN KEY `{$nombreFk}`");
            } catch (\Throwable $e) {
                log_message('warning', "[eliminarPadron] No se pudo eliminar FK '{$nombreFk}': " . $e->getMessage());
            }

            $this->tableService->eliminarTabla($padron->nombre_tabla_destino);
            $this->catalogoModel->delete($idPadron, true);
        } else {
            $this->catalogoModel->delete($idPadron);
        }

        $this->invalidarCachePadron($idPadron);
        $this->cache->delete('padrones_todos');

        return true;
    }

    // =========================================================
    // BUSCAR MUNICIPIOS (autocomplete)
    // =========================================================

    public function buscarMunicipios(string $id, string $termino): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new \RuntimeException("El padrón no existe.");

        $termino = trim($termino);
        if ($termino === '') return [];

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);
        
        // Dejamos que MySQL maneje las mayúsculas/minúsculas naturalmente
        $like  = '%' . $termino . '%';

        return $this->db->query(
            "SELECT   municipio AS municipio_estandarizado,
                      COUNT(id) AS total_registros
             FROM     {$tabla}
             WHERE    municipio IS NOT NULL
               AND    municipio != ''
               AND    municipio LIKE ?
             GROUP BY municipio
             ORDER BY total_registros DESC
             LIMIT    10",
            [$like]
        )->getResultArray();
    }

    // =========================================================
    // RESUMEN AGNÓSTICO
    // =========================================================

    public function obtenerResumenAgnostico(string $id, ?string $municipio = null): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado");

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);

        if ($municipio === null) {
            $cacheKey = "coropletas_{$id}";
            $cached   = $this->cache->get($cacheKey);
            if ($cached !== null) return $cached;

            // Este bloque está excelente, es un escaneo necesario para colorear el mapa base
            $resultado = $this->db->table($tabla)
                ->select('municipio, COUNT(id) AS total', false)
                ->select('SUM(latitud IS NOT NULL AND longitud IS NOT NULL) > 0 AS tiene_coordenadas', false)
                ->select("SUM(seccion IS NOT NULL AND seccion != '') > 0 AS tiene_seccion", false)
                ->where('municipio IS NOT NULL', null, false)
                ->groupBy('municipio', false)
                ->get()
                ->getResultArray();

            foreach ($resultado as &$row) {
                $row['tiene_coordenadas'] = (bool)(int)$row['tiene_coordenadas'];
                $row['tiene_seccion']     = (bool)(int)$row['tiene_seccion'];
            }
            unset($row);

            $this->cache->save($cacheKey, $resultado, self::TTL_RESUMEN);
            $this->registrarClaveCache($id, $cacheKey);

            return $resultado;
        }

        // ==========================================
        // OPTIMIZACIÓN PARA MUNICIPIO ESPECÍFICO
        // ==========================================
        $municipio = trim($municipio);
        $cacheKey  = "resumen_{$id}_" . md5($municipio);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        // 🚀 BÚSQUEDA EXACTA: Aprovechamos al máximo el índice idx_mun
        $row = $this->db->table($tabla)
            ->select('datos_generales')
            ->where('municipio', $municipio) // <-- ¡ADIÓS UPPER(TRIM(LIKE))!
            ->where('datos_generales IS NOT NULL', null, false)
            ->limit(1)
            ->get()
            ->getRowArray();

        $camposSumables = [];
        if ($row) {
            $json = json_decode($row['datos_generales'], true);
            if (is_array($json)) {
                foreach ($json as $key => $val) {
                    if (is_numeric($val)) $camposSumables[] = $key;
                }
            }
        }

        $builder = $this->db->table($tabla)
            ->select('COUNT(id) as total_registros', false)
            ->where('municipio', $municipio); // <-- AQUÍ TAMBIÉN, BÚSQUEDA EXACTA

        foreach ($camposSumables as $campo) {
            $builder->selectSum(
                "CAST(datos_generales->>'$.\"{$campo}\"' AS UNSIGNED)",
                "sum_{$campo}"
            );
        }

        $resultado = [
            'municipio' => $municipio,
            'stats'     => $builder->get()->getRowArray(),
            'campos'    => $camposSumables,
        ];

        $this->cache->save($cacheKey, $resultado, self::TTL_RESUMEN);
        $this->registrarClaveCache($id, $cacheKey);

        return $resultado;
    }

    // =========================================================
    // CRUD BENEFICIARIOS
    // =========================================================

   public function guardarBeneficiario(string $padronId, array $datosFijos, array $datosGenerales = []): array
    {
        $padron = $this->catalogoModel->find($padronId);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        // Limpieza de lat/lng para no romper el índice espacial (ST_GeomFromText)
        $lat = isset($datosFijos['latitud']) && $datosFijos['latitud'] !== '' ? (float)$datosFijos['latitud'] : null;
        $lng = isset($datosFijos['longitud']) && $datosFijos['longitud'] !== '' ? (float)$datosFijos['longitud'] : null;

        // Limpieza de CP
        $cp = isset($datosFijos['codigo_postal']) ? preg_replace('/[^0-9A-Za-z]/', '', $datosFijos['codigo_postal']) : null;

        $registro = [
            'id'                 => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'catalogo_padron_id' => $padronId,
            // Truncamos preventivamente para evitar "Data too long for column" en MySQL
            'nombre_completo'    => mb_substr($datosFijos['nombre_completo'] ?? 'SIN NOMBRE', 0, 255),
            'municipio'          => mb_substr($datosFijos['municipio'] ?? null, 0, 255),
            'seccion'            => mb_substr($datosFijos['seccion'] ?? null, 0, 255),
            'codigo_postal'      => mb_substr($cp, 0, 10),
            'latitud'            => $lat,
            'longitud'           => $lng,
            'datos_generales'    => !empty($datosGenerales) ? json_encode($datosGenerales, JSON_UNESCAPED_UNICODE) : null,
            'created_at'         => date('Y-m-d H:i:s'),
        ];

        // Hash más seguro para evitar colisiones entre personas que se llamen igual
        $seed = $padronId . $registro['nombre_completo'] . ($registro['datos_generales'] ?? '');
        $registro['clave_unica'] = mb_substr($datosFijos['clave_unica'] ?? md5($seed), 0, 100);

        if (!$this->modeloDinamico->insert($registro)) {
            throw new \RuntimeException("Error al insertar registro.");
        }

        // Invalidar caché solo si la inserción fue exitosa
        $this->invalidarCachePadron($padronId);
        
        return $registro;
    }

    public function actualizarBeneficiario(string $padronId, string $beneficiarioId, array $datosFijos, array $datosGenerales = []): bool
    {
        $padron = $this->catalogoModel->find($padronId);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $updateData = [];

        // 🚀 Verificamos si la llave existe en el request, permitiendo NULLs intencionales
        if (array_key_exists('nombre_completo', $datosFijos)) {
            $updateData['nombre_completo'] = mb_substr($datosFijos['nombre_completo'], 0, 255);
        }
        if (array_key_exists('municipio', $datosFijos)) {
            $updateData['municipio'] = mb_substr($datosFijos['municipio'], 0, 255);
        }
        if (array_key_exists('seccion', $datosFijos)) {
            $updateData['seccion'] = mb_substr($datosFijos['seccion'], 0, 255);
        }
        if (array_key_exists('codigo_postal', $datosFijos)) {
            $cp = preg_replace('/[^0-9A-Za-z]/', '', $datosFijos['codigo_postal']);
            $updateData['codigo_postal'] = mb_substr($cp, 0, 10);
        }
        if (array_key_exists('latitud', $datosFijos)) {
            $updateData['latitud'] = ($datosFijos['latitud'] === '' || $datosFijos['latitud'] === null) ? null : (float)$datosFijos['latitud'];
        }
        if (array_key_exists('longitud', $datosFijos)) {
            $updateData['longitud'] = ($datosFijos['longitud'] === '' || $datosFijos['longitud'] === null) ? null : (float)$datosFijos['longitud'];
        }

        if (!empty($datosGenerales)) {
            $updateData['datos_generales'] = json_encode($datosGenerales, JSON_UNESCAPED_UNICODE);
        }

        // Ejecutamos el update
        $exito = $this->modeloDinamico->update($beneficiarioId, $updateData);

        // Invalidar caché solo si el update se completó bien
        if ($exito) {
            $this->invalidarCachePadron($padronId);
        }

        return $exito;
    }
    public function eliminarBeneficiario(string $padronId, string $beneficiarioId): bool
    {
        $padron = $this->obtenerPadronPorId($padronId);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        if (!$this->modeloDinamico->delete($beneficiarioId)) {
            throw new \RuntimeException("No se pudo eliminar el registro.");
        }

        $this->invalidarCachePadron($padronId);
        return true;
    }

    // =========================================================
    // BÚSQUEDA POR CÓDIGO POSTAL
    // =========================================================

  public function buscarPorCodigoPostal(string $idPadron, string $cp, int $limit = 1000): array
    {
        $padron = $this->catalogoModel->find($idPadron);
        if (!$padron) throw new \RuntimeException("El padrón con ID {$idPadron} no existe.");

        // Limpiamos el CP al extremo para evitar inyecciones y unificar la caché
        $cpLimpio = preg_replace('/[^0-9A-Za-z]/', '', $cp);
        if ($cpLimpio === '') return [];

        $cacheKey = "search_cp_{$idPadron}_{$cpLimpio}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $resultado = $this->modeloDinamico
            ->select('id, clave_unica, nombre_completo, municipio, codigo_postal, seccion, latitud, longitud, datos_generales')
            ->where('codigo_postal', $cpLimpio) // Búsqueda exacta ultra rápida
            ->orderBy('nombre_completo', 'ASC')
            ->findAll($limit);

        $this->cache->save($cacheKey, $resultado, self::TTL_BBOX);
        $this->registrarClaveCache($idPadron, $cacheKey);

        return $resultado;
    }

    public function obtenerPlantillaCampos(string $id): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado");


        $cacheKey = "plantilla_{$id}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        // Tomamos los últimos 10 usando created_at en lugar de ID (el ID UUID string es más lento para ordenar)
        $muestras = $this->modeloDinamico
            ->where('datos_generales IS NOT NULL')
            ->orderBy('created_at', 'DESC')
            ->findAll(10);

        $plantilla = [];
        
        if (!empty($muestras)) {
            $camposFijos = [
                'id', 'catalogo_padron_id', 'clave_unica', 'nombre_completo', 
                'municipio', 'codigo_postal', 'seccion', 'latitud', 'longitud', 
                'datos_generales', 'estatus_duplicidad', 'created_at', 'geo_point'
            ];

            foreach ($muestras as $m) {
                $json = json_decode($m['datos_generales'], true);
                if (is_array($json)) {
                    // Solo iteramos las llaves, es mucho más rápido y gasta menos RAM que array_merge
                    foreach (array_keys($json) as $llave) {
                        $llaveLower = strtolower($llave);
                        if (!in_array($llaveLower, $camposFijos, true)) {
                            $plantilla[$llave] = '';
                        }
                    }
                }
            }
        }

        // Guardamos la plantilla en caché por mucho tiempo (ej. 24 horas)
        $this->cache->save($cacheKey, $plantilla, 86400);
        $this->registrarClaveCache($id, $cacheKey);

        return $plantilla;
    }

    // =========================================================
    // HELPERS PRIVADOS
    // =========================================================

    private function registrarClaveCache(string $padronId, string $cacheKey): void
    {
        $indexKey = "cache_index_{$padronId}";
        $indice   = $this->cache->get($indexKey) ?? [];

        if (!in_array($cacheKey, $indice, true)) {
            $indice[] = $cacheKey;
            $this->cache->save($indexKey, $indice, self::TTL_BBOX + 60);
        }
    }

    private function invalidarCachePadron(string $id): void
    {
        $this->cache->delete('padrones_todos');
        $this->cache->delete("beneficiarios_{$id}_default");
        $this->cache->delete("coropletas_{$id}");

        $indexKey = "cache_index_{$id}";
        $indice   = $this->cache->get($indexKey) ?? [];

        foreach ($indice as $key) {
            $this->cache->delete($key);
        }

        $this->cache->delete($indexKey);
    }
}
