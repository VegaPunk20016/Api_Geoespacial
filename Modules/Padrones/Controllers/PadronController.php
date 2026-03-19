<?php

namespace Modules\Padrones\Controllers;

use CodeIgniter\RESTful\ResourceController;
use Modules\Padrones\Config\Services;
use Modules\Padrones\DTOs\CreatePadronRequest;
use Modules\Padrones\DTOs\ImportCsvRequest;
use Modules\Padrones\Interfaces\PadronServiceInterface;
use Exception;
use RuntimeException;

class PadronController extends ResourceController
{
    /**
     * Umbral de zoom a partir del cual se devuelven puntos reales.
     * El frontend lee el campo `modo` de la respuesta — no necesita conocer este valor.
     */
    private const ZOOM_UMBRAL_PUNTOS = 13;

    protected PadronServiceInterface $padronService;

    public function __construct()
    {
        $this->padronService = Services::padronService();
    }

    // ============================================================
    // GET /api/padrones
    // ============================================================

    public function index()
    {
        try {
            $padrones = $this->padronService->obtenerTodosLosPadrones();
            return $this->respond(['status' => 200, 'data' => $padrones]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}
    // ============================================================

    public function show($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $padron = $this->padronService->obtenerPadronPorId($id);

            if (!$padron) return $this->failNotFound('Padrón no encontrado.');

            return $this->respond(['status' => 200, 'data' => $padron]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/beneficiarios
    //
    // Modo tabla  → ?pagina=1&por_pagina=50  → paginación, devuelve { data, paginacion }
    // Modo mapa   → ?min_lat=...             → límite fijo, sin paginación
    // ============================================================

    public function getBeneficiarios($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $filtros = [
                'min_lat'       => $this->request->getGet('min_lat'),
                'max_lat'       => $this->request->getGet('max_lat'),
                'min_lng'       => $this->request->getGet('min_lng'),
                'max_lng'       => $this->request->getGet('max_lng'),
                'municipio'     => $this->request->getGet('municipio'),
                'seccion'       => $this->request->getGet('seccion'),
                'codigo_postal' => $this->request->getGet('codigo_postal'),
                // Paginación — solo activa cuando el frontend manda ?pagina=N
                'pagina'        => $this->request->getGet('pagina'),
                'por_pagina'    => $this->request->getGet('por_pagina'),
            ];

            $respuesta = $this->padronService->obtenerBeneficiarios($id, $filtros);

            return $this->respond([
                'status'     => 200,
                'total'      => count($respuesta['data']),
                'data'       => $respuesta['data'],
                'paginacion' => $respuesta['paginacion'],
            ]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error al obtener registros: ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{padronId}/beneficiarios/{beneficiarioId}
    // ============================================================

    public function getBeneficiarioDetalle($padronId = null, $beneficiarioId = null)
    {
        try {
            if (!$padronId || !$beneficiarioId) {
                return $this->failValidationErrors('IDs obligatorios.');
            }

            $detalle = $this->padronService->obtenerDetalleBeneficiario($padronId, $beneficiarioId);

            if (!$detalle) return $this->failNotFound('Registro no encontrado.');

            return $this->respond(['status' => 200, 'data' => $detalle]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // POST /api/padrones/{padronId}/beneficiarios
    // ============================================================

    public function createBeneficiario($padronId = null)
    {
        try {
            if (!$padronId) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $json           = $this->request->getJSON(true);
            $datosFijos     = $json['campos_fijos']     ?? $json;
            $datosGenerales = $json['datos_generales']  ?? [];

            $nuevo = $this->padronService->guardarBeneficiario($padronId, $datosFijos, $datosGenerales);

            return $this->respondCreated(['status' => 201, 'data' => $nuevo]);
        } catch (RuntimeException $e) {
            return $this->failValidationErrors($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // PATCH /api/padrones/{padronId}/beneficiarios/{beneficiarioId}
    // ============================================================

    public function updateBeneficiario($padronId = null, $beneficiarioId = null)
    {
        try {
            if (!$padronId || !$beneficiarioId) {
                return $this->failValidationErrors('IDs obligatorios.');
            }

            $json           = $this->request->getJSON(true);
            $datosFijos     = $json['campos_fijos']    ?? $json;
            $datosGenerales = $json['datos_generales'] ?? [];

            $this->padronService->actualizarBeneficiario($padronId, $beneficiarioId, $datosFijos, $datosGenerales);

            return $this->respond(['status' => 200, 'message' => 'Registro actualizado.']);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // DELETE /api/padrones/{padronId}/beneficiarios/{beneficiarioId}
    // ============================================================

    public function deleteBeneficiario($padronId = null, $beneficiarioId = null)
    {
        try {
            if (!$padronId || !$beneficiarioId) {
                return $this->failValidationErrors('IDs obligatorios.');
            }

            $this->padronService->eliminarBeneficiario($padronId, $beneficiarioId);

            return $this->respondDeleted(['status' => 200, 'message' => 'Registro eliminado.']);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/buscar?q=termino
    // ============================================================

    public function buscar($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $termino = $this->request->getGet('q');

            if (!$termino || strlen(trim($termino)) < 2) {
                return $this->respond(['status' => 200, 'data' => []]);
            }

            $resultados = $this->padronService->buscarMunicipios($id, $termino);

            return $this->respond(['status' => 200, 'data' => $resultados]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error en la búsqueda: ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/buscar-cp/{cp}
    // ============================================================

    public function buscarPorCP($id = null, $cp = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            if (!$cp || !preg_match('/^\d{5}$/', $cp)) {
                return $this->failValidationErrors('El Código Postal debe tener 5 dígitos.');
            }

            $resultados = $this->padronService->buscarPorCodigoPostal($id, $cp);

            return $this->respond([
                'status' => 200,
                'total'  => count($resultados),
                'data'   => $resultados,
            ]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error en la búsqueda por CP: ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/resumen?municipio=X
    // ============================================================

    public function getResumen($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $municipio = $this->request->getGet('municipio') ?: null;
            $data      = $this->padronService->obtenerResumenAgnostico($id, $municipio);

            return $this->respond(['status' => 200, 'data' => $data]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/plantilla
    // ============================================================

    public function getPlantilla($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('ID requerido.');

            $plantilla = $this->padronService->obtenerPlantillaCampos($id);

            return $this->respond(['status' => 200, 'data' => $plantilla]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/clusters
    //
    // zoom >= ZOOM_UMBRAL_PUNTOS → puntos reales (hasta 5000)
    // zoom <  ZOOM_UMBRAL_PUNTOS → clusters agrupados del servidor
    // Responde { modo: 'puntos'|'clusters', data, total }
    // ============================================================

    public function getClusters($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $zoom    = (int)($this->request->getGet('zoom') ?? 10);
            $filtros = [
                'min_lat'   => $this->request->getGet('min_lat'),
                'max_lat'   => $this->request->getGet('max_lat'),
                'min_lng'   => $this->request->getGet('min_lng'),
                'max_lng'   => $this->request->getGet('max_lng'),
                'municipio' => $this->request->getGet('municipio'),
                'zoom'      => $zoom,
            ];

            if ($zoom >= self::ZOOM_UMBRAL_PUNTOS) {
                // Puntos reales — sin paginación (modo mapa con BBOX)
                $respuesta = $this->padronService->obtenerBeneficiarios($id, $filtros);

                return $this->respond([
                    'status' => 200,
                    'modo'   => 'puntos',
                    'total'  => count($respuesta['data']),
                    'data'   => $respuesta['data'],
                ]);
            }

            $data = $this->padronService->obtenerClusters($id, $filtros);

            return $this->respond([
                'status' => 200,
                'modo'   => 'clusters',
                'total'  => count($data),
                'data'   => $data,
            ]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error: ' . $e->getMessage());
        }
    }

    // ============================================================
    // POST /api/padrones
    // ============================================================

    public function create()
    {
        try {
            $json = $this->request->getJSON();

            if (!$json) return $this->failValidationErrors('No se enviaron datos.');

            $dto = new CreatePadronRequest($json);

            if (!$dto->isValid()) {
                return $this->failValidationErrors('El nombre del padrón y la entidad federativa son obligatorios.');
            }

            $nuevoPadron = $this->padronService->crearNuevoPadron($dto);

            return $this->respondCreated([
                'status'  => 201,
                'message' => 'Padrón creado correctamente.',
                'data'    => $nuevoPadron,
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // POST /api/padrones/{id}/importar
    // ============================================================

    public function importCsv($padronId = null)
    {
        try {
            if (!$padronId) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $archivo = $this->request->getFile('archivo');

            if (!$archivo) {
                return $this->failValidationErrors('No se recibió el archivo. Verifica el FormData.');
            }

            if (!$archivo->isValid()) {
                return $this->failValidationErrors(
                    'PHP bloqueó la carga. ' . $archivo->getErrorString() . ' (Código ' . $archivo->getError() . ')'
                );
            }

            $dto = new ImportCsvRequest($padronId, $archivo);

            if (!$dto->isValid()) {
                return $this->failValidationErrors('Formato no permitido. Solo CSV, TXT, XLSX y XLS.');
            }

            $resultado = $this->padronService->procesarCargaMasiva($dto);

            return $this->respond([
                'status'  => 200,
                'message' => 'Carga masiva completada con éxito.',
                'data'    => $resultado,
            ]);
        } catch (RuntimeException $e) {
            return $this->failValidationErrors($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error interno: ' . $e->getMessage());
        }
    }

    // ============================================================
    // DELETE /api/padrones/{id}
    // ============================================================

    public function delete($id = null)
    {
        try {
            if (!$id) return $this->failValidationErrors('El ID del padrón es obligatorio.');

            $esPermanente = $this->request->getGet('permanente') === 'true';

            $this->padronService->eliminarPadron($id, $esPermanente);

            $mensaje = $esPermanente
                ? 'Padrón y tabla física eliminados permanentemente.'
                : 'Padrón enviado a la papelera (borrado lógico).';

            return $this->respondDeleted(['status' => 200, 'message' => $mensaje]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }
}