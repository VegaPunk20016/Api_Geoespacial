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
use RuntimeException;

class PadronService implements PadronServiceInterface
{
    private CatalogoPadronModel $catalogoModel;
    private BeneficiarioDinamicoModel $modeloDinamico;
    private ConnectionInterface $db;
    private PadronTableService $tableService;
    private PadronImportService $importService;
    private FileConverterService $converterService;

    public function __construct(
        CatalogoPadronModel $catalogoModel,
        BeneficiarioDinamicoModel $modeloDinamico,
        ConnectionInterface $db,
        PadronTableService $tableService,
        PadronImportService $importService,
        FileConverterService $converterService
    ) {
        $this->catalogoModel = $catalogoModel;
        $this->modeloDinamico = $modeloDinamico;
        $this->db = $db;
        $this->tableService = $tableService;
        $this->importService = $importService;
        $this->converterService = $converterService;
    }

  
    public function crearNuevoPadron(CreatePadronRequest $datos): CatalogoPadron
    {
        $uuid = Uuid::uuid7()->toString();
        $nombreLimpio = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $datos->nombre_padron));
        $nombreTabla = substr('padron_' . trim($nombreLimpio, '_'), 0, 60);

        $padron = new CatalogoPadron([
            'id' => $uuid,
            'nombre_padron' => $datos->nombre_padron,
            'descripcion' => $datos->descripcion,
            'clave_interna' => $datos->clave_interna,
            'entidad_federativa' => $datos->entidad_federativa,
            'categoria' => $datos->categoria ?? 'General',
            'nombre_tabla_destino' => $nombreTabla
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

        return $this->catalogoModel->find($uuid);
    }

    public function procesarCargaMasiva(ImportCsvRequest $request): array
    {
        $padron = $this->catalogoModel->find($request->catalogo_padron_id);

        if (!$padron) {
            throw new RuntimeException("El padrón no existe.");
        }

        $rutaOriginal = $request->getTempPath();
        
        $extension = $request->getExtensionOriginal(); 

        $rutaCsvFinal = $this->converterService->prepararCsv($rutaOriginal, $extension);

        $resultado = $this->importService->importar(
            $padron->nombre_tabla_destino,
            $request->catalogo_padron_id,
            $rutaCsvFinal
        );

        if ($rutaCsvFinal !== $rutaOriginal && file_exists($rutaCsvFinal)) {
            unlink($rutaCsvFinal);
        }

        return $resultado;
    }

    public function obtenerTodosLosPadrones(): array
    {
        return $this->catalogoModel
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    public function obtenerPadronPorId(string $id): ?CatalogoPadron
    {
        return $this->catalogoModel->find($id);
    }



   public function obtenerBeneficiarios(string $id, array $filtros = []): array
{
    $padron = $this->catalogoModel->find($id);
    if (!$padron) throw new RuntimeException("No existe.");

    $this->modeloDinamico->setTable($padron->nombre_tabla_destino);
    $query = $this->modeloDinamico->select('id, clave_unica, nombre_completo, municipio, seccion, latitud, longitud, datos_generales');

    // Verificamos que los 4 puntos del "cuadro" existan en el array
    if (isset($filtros['min_lat'], $filtros['max_lat'], $filtros['min_lng'], $filtros['max_lng']) && 
        $filtros['min_lat'] !== null) {
        
        $query->where('latitud >=', (float)$filtros['min_lat'])
              ->where('latitud <=', (float)$filtros['max_lat'])
              ->where('longitud >=', (float)$filtros['min_lng'])
              ->where('longitud <=', (float)$filtros['max_lng']);
        
        return $query->findAll(5000); 
    }

    return $query->findAll(1000); // Esto solo sale si NO hay coordenadas
}

    public function eliminarPadron(string $idPadron, bool $permanente = false): bool
    {
        $padron = $this->catalogoModel->withDeleted()->find($idPadron);

        if (!$padron) {
            throw new RuntimeException("El padrón no existe.");
        }

        if ($permanente) {
            // ✅ FIX: deshabilitar FKs antes del borrado para evitar timeout
            $this->db->query("SET FOREIGN_KEY_CHECKS=0");

            try {
                // Borrar tabla física PRIMERO, luego el registro del catálogo
                $this->tableService->eliminarTabla($padron->nombre_tabla_destino);
                $this->catalogoModel->delete($idPadron, true);
            } finally {
                $this->db->query("SET FOREIGN_KEY_CHECKS=1");
            }
        } else {
            $this->catalogoModel->delete($idPadron);
        }

        return true;
    }
}