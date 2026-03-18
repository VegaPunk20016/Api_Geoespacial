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
     * Umbral de zoom a partir del cual se devuelven puntos reales en lugar de clusters.
     * Declarado como constante para que sea el único lugar donde vive esta lógica.
     * El frontend ya no necesita conocer este valor — lee el campo `modo` de la respuesta.
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

            return $this->respond([
                'status' => 200,
                'data'   => $padrones,
            ]);
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
            if (!$id) {
                return $this->failValidationErrors('El ID del padrón es obligatorio.');
            }

            $padron = $this->padronService->obtenerPadronPorId($id);

            if (!$padron) {
                return $this->failNotFound('Padrón no encontrado.');
            }

            return $this->respond([
                'status' => 200,
                'data'   => $padron,
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/beneficiarios
    // ============================================================

    public function getBeneficiarios($id = null)
    {
        try {
            if (!$id) {
                return $this->failValidationErrors('El ID del padrón es obligatorio.');
            }

            $filtros = [
                'min_lat' => $this->request->getGet('min_lat'),
                'max_lat' => $this->request->getGet('max_lat'),
                'min_lng' => $this->request->getGet('min_lng'),
                'max_lng' => $this->request->getGet('max_lng'),
            ];

            $beneficiarios = $this->padronService->obtenerBeneficiarios($id, $filtros);

            return $this->respond([
                'status' => 200,
                'total'  => count($beneficiarios),
                'data'   => $beneficiarios,
            ]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error al obtener registros: ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/buscar
    // ============================================================

    public function buscar($id = null)
    {
        try {
            if (!$id) {
                return $this->failValidationErrors('El ID del padrón es obligatorio.');
            }

            $termino = $this->request->getGet('q');

            if (!$termino || strlen(trim($termino)) < 2) {
                return $this->respond(['status' => 200, 'data' => []]);
            }

            $resultados = $this->padronService->buscarMunicipios($id, $termino);

            return $this->respond([
                'status' => 200,
                'data'   => $resultados,
            ]);
        } catch (RuntimeException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error en la búsqueda: ' . $e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/resumen
    // ============================================================

    public function getResumen($id = null)
    {
        try {
            $municipio = $this->request->getGet('municipio');
            $data      = $this->padronService->obtenerResumenAgnostico($id, $municipio);

            return $this->respond([
                'status' => 200,
                'data'   => $data,
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // POST /api/padrones
    // ============================================================

    public function create()
    {
        try {
            $json = $this->request->getJSON();

            if (!$json) {
                return $this->failValidationErrors('No se enviaron datos.');
            }

            $dto = new CreatePadronRequest($json);

            if (!$dto->isValid()) {
                return $this->failValidationErrors(
                    'El nombre del padrón y la entidad federativa son obligatorios.'
                );
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
            if (!$padronId) {
                return $this->failValidationErrors('El ID del padrón es obligatorio.');
            }

            $archivo = $this->request->getFile('archivo');

            if (!$archivo) {
                return $this->failValidationErrors(
                    'Error A: No se recibió la variable "archivo". Verifica el FormData en Vue.'
                );
            }

            if (!$archivo->isValid()) {
                return $this->failValidationErrors(
                    'Error B: PHP bloqueó la carga. Motivo: ' . $archivo->getErrorString() .
                    ' (Código ' . $archivo->getError() . ')'
                );
            }

            $dto = new ImportCsvRequest($padronId, $archivo);

            if (!$dto->isValid()) {
                return $this->failValidationErrors(
                    'Formato no permitido. Solo se aceptan archivos CSV, TXT, XLSX y XLS.'
                );
            }

            $resultado = $this->padronService->procesarCargaMasiva($dto);

            return $this->respond([
                'status'  => 200,
                'message' => 'Carga masiva completada con éxito.',
                'data'    => $resultado,
            ]);
        } catch (\RuntimeException $e) {
            return $this->failValidationErrors($e->getMessage());
        } catch (\Exception $e) {
            return $this->failServerError('Error interno: ' . $e->getMessage());
        }
    }

    // ============================================================
    // DELETE /api/padrones/{id}
    // ============================================================

    public function delete($id = null)
    {
        try {
            $esPermanente = $this->request->getGet('permanente') === 'true';

            $this->padronService->eliminarPadron($id, $esPermanente);

            $mensaje = $esPermanente
                ? 'Padrón y tabla física eliminados permanentemente.'
                : 'Padrón enviado a la papelera (Borrado lógico).';

            return $this->respondDeleted([
                'status'  => 200,
                'message' => $mensaje,
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    // ============================================================
    // GET /api/padrones/{id}/clusters
    //
    // Punto de entrada único para el mapa.
    // Decide internamente qué devolver según el zoom:
    //   zoom >= ZOOM_UMBRAL_PUNTOS → puntos reales (hasta 5000, filtrados por BBOX)
    //   zoom <  ZOOM_UMBRAL_PUNTOS → clusters agrupados del servidor
    //
    // La respuesta incluye { modo: 'puntos'|'clusters' } para que el frontend
    // sepa qué recibió sin necesidad de conocer el umbral.
    // ============================================================

    public function getClusters($id = null)
    {
        try {
            if (!$id) {
                return $this->failValidationErrors('El ID del padrón es obligatorio.');
            }

            $zoom    = (int)($this->request->getGet('zoom') ?? 10);
            $filtros = [
                'min_lat'  => $this->request->getGet('min_lat'),
                'max_lat'  => $this->request->getGet('max_lat'),
                'min_lng'  => $this->request->getGet('min_lng'),
                'max_lng'  => $this->request->getGet('max_lng'),
                'municipio'=> $this->request->getGet('municipio'),
                'zoom'     => $zoom,
            ];

            if ($zoom >= self::ZOOM_UMBRAL_PUNTOS) {
                $data = $this->padronService->obtenerBeneficiarios($id, $filtros);

                return $this->respond([
                    'status' => 200,
                    'modo'   => 'puntos',
                    'total'  => count($data),
                    'data'   => $data,
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
}