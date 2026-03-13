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

    private const BBOX_PRECISION = 2;
    private const TTL_BBOX       = 300;
    private const TTL_LISTA      = 600;

    public function __construct(
        CatalogoPadronModel       $catalogoModel,
        BeneficiarioDinamicoModel $modeloDinamico,
        ConnectionInterface       $db,
        PadronTableService        $tableService,
        PadronImportService       $importService,
        FileConverterService      $converterService,
        CacheInterface            $cache
    ) {
        $this->catalogoModel    = $catalogoModel;
        $this->modeloDinamico   = $modeloDinamico;
        $this->db               = $db;
        $this->tableService     = $tableService;
        $this->importService    = $importService;
        $this->converterService = $converterService;
        $this->cache            = $cache;
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
    // OBTENER BENEFICIARIOS (BBOX + municipio + caché)
    // =========================================================

    public function obtenerBeneficiarios(string $id, array $filtros = []): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("El padrón solicitado no existe.");

        $tieneBbox = isset($filtros['min_lat'], $filtros['max_lat'], $filtros['min_lng'], $filtros['max_lng'])
            && $filtros['min_lat'] !== null;

        /**
         * Lógica de Limpieza:
         * Priorizamos 'municipio' (nombre). Si es el string "null" o está vacío, lo tratamos como nulo.
         */
        $municipioFiltro = $filtros['municipio'] ?? $filtros['municipio_cvegeo'] ?? null;
        $municipio = ($municipioFiltro !== null && $municipioFiltro !== 'null' && $municipioFiltro !== '')
            ? trim($municipioFiltro)
            : '';

        // Generación de Clave de Caché
        if ($tieneBbox) {
            $p        = self::BBOX_PRECISION;
            $minLat   = round((float)$filtros['min_lat'], $p);
            $maxLat   = round((float)$filtros['max_lat'], $p);
            $minLng   = round((float)$filtros['min_lng'], $p);
            $maxLng   = round((float)$filtros['max_lng'], $p);
            $munSlug  = $municipio ? '_' . md5($municipio) : '';
            $cacheKey = "bbox_{$id}_{$minLat}_{$maxLat}_{$minLng}_{$maxLng}{$munSlug}";
        } else {
            $munSlug  = $municipio ? '_' . md5($municipio) : '';
            $cacheKey = "beneficiarios_{$id}_default{$munSlug}";
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        // Consulta a la Base de Datos
        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);
        $builder = $this->modeloDinamico->select(
            'id, clave_unica, nombre_completo, municipio, seccion, latitud, longitud, datos_generales'
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

        /**
         * Filtro de Municipio:
         * Usamos la columna 'municipio' directamente para aprovechar el índice B-Tree
         */
        if ($municipio !== '') {
            $builder->where('municipio', $municipio);
        }

        // Limitamos a 5000 para no saturar el navegador con demasiados nodos DOM
        $resultado = $builder->findAll($tieneBbox ? 5000 : 1000);

        $this->cache->save($cacheKey, $resultado, self::TTL_BBOX);
        $this->registrarClaveCache($id, $cacheKey);

        return $resultado;
    }

    // =========================================================
    // CLUSTERS DEL SERVIDOR (zoom bajo)
    // =========================================================

    public function obtenerClusters(string $id, array $filtros = []): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        $zoom = (int)($filtros['zoom'] ?? 10);

        // Definimos precisión de agrupación según el nivel de zoom
        if ($zoom <= 9)      $precision = 1;
        elseif ($zoom <= 11) $precision = 2;
        else                 $precision = 3;

        $tieneBbox = isset($filtros['min_lat'], $filtros['max_lat'], $filtros['min_lng'], $filtros['max_lng'])
            && $filtros['min_lat'] !== null;

        // Lógica de limpieza idéntica para consistencia
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

        // Filtro de Municipio
        if ($municipio !== '') {
            $builder->where('municipio', $municipio);
        }

        $resultado = $builder
            ->groupBy("ROUND(latitud, {$precision}), ROUND(longitud, {$precision})", false)
            ->orderBy('count', 'DESC')
            ->limit(2000)
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
            $this->db->query("SET FOREIGN_KEY_CHECKS=0");
            try {
                $this->tableService->eliminarTabla($padron->nombre_tabla_destino);
                $this->catalogoModel->delete($idPadron, true);
            } finally {
                $this->db->query("SET FOREIGN_KEY_CHECKS=1");
            }
        } else {
            $this->catalogoModel->delete($idPadron);
        }

        $this->invalidarCachePadron($idPadron);
        $this->cache->delete('padrones_todos');

        return true;
    }

    public function buscarBeneficiario(string $id, string $termino): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("El padrón no existe.");

        $this->modeloDinamico->setTable($padron->nombre_tabla_destino);

        return $this->modeloDinamico
            ->select('TRIM(municipio) as municipio_estandarizado, COUNT(id) as total_registros')
            ->like('municipio', trim($termino), 'both', null, true)
            ->where('municipio IS NOT NULL')
            ->where('municipio !=', '')
            ->groupBy('TRIM(municipio)')
            ->orderBy('total_registros', 'DESC')
            ->limit(10) // Traemos las 10 coincidencias más grandes
            ->get()
            ->getResultArray();
    }

    // =========================================================
    // HELPERS DE CACHÉ
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

    /**
     * Obtiene un resumen estadístico agnóstico de un municipio.
     * Si no se especifica municipio, trae el conteo global por municipio para el mapa.
     */
    public function obtenerResumenAgnostico(string $id, ?string $municipio = null): array
    {
        $padron = $this->catalogoModel->find($id);
        if (!$padron) throw new RuntimeException("Padrón no encontrado");

        $tabla = $this->db->escapeIdentifier($padron->nombre_tabla_destino);

        // CASO A: Si no hay municipio, es para el mapa (Coropletas)
        if ($municipio === null) {
            return $this->db->table($tabla)
                ->select('municipio, COUNT(id) as total')
                ->where('municipio IS NOT NULL')
                ->groupBy('municipio')
                ->get()
                ->getResultArray();
        }

        // CASO B: Análisis profundo de un municipio específico
        $municipio = trim($municipio);

        // 1. Tomamos una muestra para detectar llaves del JSON
        $row = $this->db->table($tabla)
            ->select('datos_generales')
            ->where('municipio', $municipio)
            ->where('datos_generales IS NOT NULL')
            ->limit(1)
            ->get()
            ->getRowArray();

        $camposSumables = [];
        if ($row) {
            $json = json_decode($row['datos_generales'], true);
            foreach ($json as $key => $val) {
                // Si el valor de la muestra es numérico, intentaremos sumarlo
                if (is_numeric($val)) {
                    $camposSumables[] = $key;
                }
            }
        }

        $builder = $this->db->table($tabla)
            ->select('COUNT(id) as total_registros')
            ->where('municipio', $municipio);

        foreach ($camposSumables as $campo) {
            $builder->selectSum("CAST(datos_generales->>'$.\"{$campo}\"' AS UNSIGNED)", "sum_{$campo}");
        }
        
        return [
            'municipio' => $municipio,
            'stats'     => $builder->get()->getRowArray(),
            'campos'    => $camposSumables
        ];
    }

    private function invalidarCachePadron(string $id): void
    {
        $this->cache->delete("beneficiarios_{$id}_default");

        $indexKey = "cache_index_{$id}";
        $indice   = $this->cache->get($indexKey) ?? [];

        foreach ($indice as $key) {
            $this->cache->delete($key);
        }

        $this->cache->delete($indexKey);
    }
}
