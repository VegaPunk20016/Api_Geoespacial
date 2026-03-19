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

    // Límites para mapa (sin paginación)
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
    // IMPORTAR CSV / XLSX
    // =========================================================

    public function procesarCargaMasiva(ImportCsvRequest $request): array
    {
        $padron = $this->catalogoModel->find($request->catalogo_padron_id);

        if (!$padron) {
            throw new RuntimeException("El padrón no existe.");
        }

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
        if (!$padron) throw new RuntimeException("El padrón solicitado no existe.");

        $tieneBbox = isset($filtros['min_lat'], $filtros['max_lat'], $filtros['min_lng'], $filtros['max_lng'])
            && $filtros['min_lat'] !== null;

        // ── Normalizar municipio ────────────────────────────────────────────
        $municipioFiltro = $filtros['municipio'] ?? $filtros['municipio_cvegeo'] ?? null;
        $municipio = ($municipioFiltro !== null && $municipioFiltro !== 'null' && $municipioFiltro !== '')
            ? trim($municipioFiltro)
            : '';

        $cp = isset($filtros['codigo_postal']) ? trim($filtros['codigo_postal']) : '';

        // ── Paginación (solo modo tabla, nunca en mapa) ─────────────────────
        $paginar   = !$tieneBbox && isset($filtros['pagina']);
        $pagina    = max(1, (int)($filtros['pagina'] ?? 1));
        $porPagina = max(10, min(200, (int)($filtros['por_pagina'] ?? 50)));
        $offset    = ($pagina - 1) * $porPagina;

        // ── Clave de caché ──────────────────────────────────────────────────
        if ($tieneBbox) {
            $p        = self::BBOX_PRECISION;
            $minLat   = round((float)$filtros['min_lat'], $p);
            $maxLat   = round((float)$filtros['max_lat'], $p);
            $minLng   = round((float)$filtros['min_lng'], $p);
            $maxLng   = round((float)$filtros['max_lng'], $p);
            $munSlug  = $municipio ? '_' . md5($municipio) : '';
            $cpSlug   = $cp ? '_cp' . $cp : '';
            $cacheKey = "bbox_{$id}_{$minLat}_{$maxLat}_{$minLng}_{$maxLng}{$munSlug}{$cpSlug}";
        } else {
            $munSlug   = $municipio ? '_' . md5($municipio) : '';
            $cpSlug    = $cp ? '_cp' . $cp : '';
            $paginaSlug = $paginar ? "_p{$pagina}x{$porPagina}" : '';
            $cacheKey  = "beneficiarios_{$id}_default{$munSlug}{$cpSlug}{$paginaSlug}";
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        // ── Query ───────────────────────────────────────────────────────────
        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $builder = $this->modeloDinamico->select(
            'id, clave_unica, nombre_completo, municipio, codigo_postal, seccion, latitud, longitud, datos_generales'
        );

        if ($tieneBbox) {
            $builder
                ->where('latitud IS NOT NULL',  null, false)
                ->where('longitud IS NOT NULL', null, false)
                ->where('latitud >=',  (float)$filtros['min_lat'])
                ->where('latitud <=',  (float)$filtros['max_lat'])
                ->where('longitud >=', (float)$filtros['min_lng'])
                ->where('longitud <=', (float)$filtros['max_lng']);
        }

        // TRIM+UPPER+LIKE para tolerar espacios, mayúsculas y sufijos en BD
        if ($municipio !== '') {
            $builder->where("UPPER(TRIM(municipio)) LIKE", '%' . strtoupper($municipio) . '%');
        }

        if ($cp !== '') {
            $builder->where('codigo_postal', $cp);
        }

        if ($paginar) {
            // Contar total antes de aplicar LIMIT/OFFSET
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
            // Mapa → límite fijo, sin paginación
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
    // CLUSTERS DEL SERVIDOR (zoom bajo)
    // =========================================================

    public function obtenerClusters(string $id, array $filtros = []): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        $zoom = (int)($filtros['zoom'] ?? 10);

        if ($zoom <= 9)      $precision = 1;
        elseif ($zoom <= 11) $precision = 2;
        else                 $precision = 3;

        $tieneBbox = isset($filtros['min_lat'], $filtros['max_lat'], $filtros['min_lng'], $filtros['max_lng'])
            && $filtros['min_lat'] !== null;

        $municipioFiltro = $filtros['municipio'] ?? $filtros['municipio_cvegeo'] ?? null;
        $municipio = ($municipioFiltro !== null && $municipioFiltro !== 'null' && $municipioFiltro !== '')
            ? trim($municipioFiltro)
            : '';

        $munSlug  = $municipio ? '_' . md5($municipio) : '';
        $cacheKey = "clusters_{$id}_z{$zoom}{$munSlug}";

        if ($tieneBbox) {
            $cacheKey .= '_' . round((float)$filtros['min_lat'], 1)
                . '_' . round((float)$filtros['max_lat'], 1)
                . '_' . round((float)$filtros['min_lng'], 1)
                . '_' . round((float)$filtros['max_lng'], 1);
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
            ->where('latitud IS NOT NULL',  null, false)
            ->where('longitud IS NOT NULL', null, false);

        if ($tieneBbox) {
            $builder
                ->where('latitud >=',  (float)$filtros['min_lat'])
                ->where('latitud <=',  (float)$filtros['max_lat'])
                ->where('longitud >=', (float)$filtros['min_lng'])
                ->where('longitud <=', (float)$filtros['max_lng']);
        }

        // TRIM+UPPER+LIKE para clusters también
        if ($municipio !== '') {
            $builder->where("UPPER(TRIM(municipio)) LIKE", '%' . strtoupper($municipio) . '%');
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
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        $termino = trim($termino);
        if ($termino === '') return [];

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);
        $like  = '%' . strtoupper($termino) . '%';

        return $this->db->query(
            "SELECT   TRIM(municipio)  AS municipio_estandarizado,
                      COUNT(id)        AS total_registros
             FROM     {$tabla}
             WHERE    municipio IS NOT NULL
               AND    municipio != ''
               AND    UPPER(TRIM(municipio)) LIKE ?
             GROUP BY TRIM(municipio)
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
        if (!$padron) throw new RuntimeException("Padrón no encontrado");

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);

        // CASO A: sin municipio → conteo global para coropletas
        if ($municipio === null) {
            $cacheKey = "coropletas_{$id}";
            $cached   = $this->cache->get($cacheKey);
            if ($cached !== null) return $cached;

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

        // CASO B: análisis profundo de municipio — TRIM+UPPER+LIKE
        $municipio      = trim($municipio);
        $municipioUpper = strtoupper($municipio);
        $like           = '%' . $municipioUpper . '%';
        $cacheKey       = "resumen_{$id}_" . md5($municipioUpper);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $row = $this->db->table($tabla)
            ->select('datos_generales')
            ->where("UPPER(TRIM(municipio)) LIKE", $like)
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
            ->where("UPPER(TRIM(municipio)) LIKE", $like);

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
        $padron = $this->obtenerPadronPorId($padronId);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $registro = [
            'id'                 => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'catalogo_padron_id' => $padronId,
            'nombre_completo'    => $datosFijos['nombre_completo'] ?? 'SIN NOMBRE',
            'municipio'          => $datosFijos['municipio']       ?? null,
            'seccion'            => $datosFijos['seccion']         ?? null,
            'codigo_postal'      => $datosFijos['codigo_postal']   ?? null,
            'latitud'            => isset($datosFijos['latitud'])  ? (float)$datosFijos['latitud']  : null,
            'longitud'           => isset($datosFijos['longitud']) ? (float)$datosFijos['longitud'] : null,
            'datos_generales'    => !empty($datosGenerales) ? json_encode($datosGenerales) : null,
            'created_at'         => date('Y-m-d H:i:s'),
        ];

        $registro['clave_unica'] = $datosFijos['clave_unica'] ?? md5($padronId . ($registro['nombre_completo']));

        if (!$this->modeloDinamico->insert($registro)) {
            throw new \RuntimeException("Error al insertar registro.");
        }

        $this->invalidarCachePadron($padronId);
        return $registro;
    }

    public function actualizarBeneficiario(string $padronId, string $beneficiarioId, array $datosFijos, array $datosGenerales = []): bool
    {
        $padron = $this->obtenerPadronPorId($padronId);
        if (!$padron) throw new \RuntimeException("Padrón no encontrado.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $updateData = array_filter([
            'nombre_completo' => $datosFijos['nombre_completo'] ?? null,
            'municipio'       => $datosFijos['municipio']       ?? null,
            'codigo_postal'   => $datosFijos['codigo_postal']   ?? null,
            'seccion'         => $datosFijos['seccion']         ?? null,
            'latitud'         => isset($datosFijos['latitud'])  ? (float)$datosFijos['latitud']  : null,
            'longitud'        => isset($datosFijos['longitud']) ? (float)$datosFijos['longitud'] : null,
        ], fn($v) => !is_null($v));

        if (!empty($datosGenerales)) {
            $updateData['datos_generales'] = json_encode($datosGenerales);
        }

        $this->invalidarCachePadron($padronId);
        return $this->modeloDinamico->update($beneficiarioId, $updateData);
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

        $cacheKey = "search_cp_{$idPadron}_{$cp}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $resultado = $this->modeloDinamico
            ->select('id, clave_unica, nombre_completo, municipio, codigo_postal, seccion, latitud, longitud, datos_generales')
            ->where('codigo_postal', trim($cp))
            ->orderBy('nombre_completo', 'ASC')
            ->findAll($limit);

        $this->cache->save($cacheKey, $resultado, self::TTL_BBOX);
        $this->registrarClaveCache($idPadron, $cacheKey);

        return $resultado;
    }

    // =========================================================
    // PLANTILLA DE CAMPOS (datos_generales)
    // =========================================================

    public function obtenerPlantillaCampos(string $id): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("Padrón no encontrado");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        $ultimo = $this->modeloDinamico
            ->where('datos_generales IS NOT NULL')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$ultimo || empty($ultimo['datos_generales'])) return [];

        $data = json_decode($ultimo['datos_generales'], true);

        $camposFijos = [
            'id', 'catalogo_padron_id', 'clave_unica', 'nombre_completo',
            'municipio', 'codigo_postal', 'seccion', 'latitud', 'longitud',
            'datos_generales', 'estatus_duplicidad', 'created_at',
        ];

        $plantilla = [];
        foreach ($data as $llave => $valor) {
            if (!in_array(strtolower($llave), $camposFijos)) {
                $plantilla[$llave] = '';
            }
        }

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