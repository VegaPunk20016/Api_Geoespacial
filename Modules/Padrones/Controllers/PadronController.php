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
                'data'   => $padrones
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
                'data'   => $padron
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

            // 1. Recolectamos los filtros de la URL (si existen)
            $filtros = [
                'min_lat' => $this->request->getGet('min_lat'),
                'max_lat' => $this->request->getGet('max_lat'),
                'min_lng' => $this->request->getGet('min_lng'),
                'max_lng' => $this->request->getGet('max_lng'),
            ];

            // 2. Llamamos al Service pasando el ID y los filtros
            // Si los filtros van vacíos, el Service ya sabe que debe devolver los primeros 1000
            $beneficiarios = $this->padronService->obtenerBeneficiarios($id, $filtros);

            return $this->respond([
                'status' => 200,
                'total'  => count($beneficiarios),
                'data'   => $beneficiarios
            ]);
        } catch (RuntimeException $e) {
            // Error específico (ej: Padrón no encontrado)
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            // Error genérico del servidor
            return $this->failServerError('Error al obtener registros: ' . $e->getMessage());
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
                'data'    => $nuevoPadron
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

            // 1. Capturamos el archivo
            $archivo = $this->request->getFile('archivo');

            // 🕵️‍♂️ DETECTIVE: Error de Comunicación (Frontend/Axios)
            if (!$archivo) {
                return $this->failValidationErrors(
                    'Error A: No se recibió la variable "archivo". Verifica el FormData en Vue.'
                );
            }

            // 🕵️‍♂️ DETECTIVE: Error de Configuración (PHP.ini/Permisos)
            if (!$archivo->isValid()) {
                return $this->failValidationErrors(
                    'Error B: PHP bloqueó la carga. Motivo: ' . $archivo->getErrorString() .
                        ' (Código ' . $archivo->getError() . ')'
                );
            }

            // 2. Validación de Formatos (DTO)
            $dto = new ImportCsvRequest($padronId, $archivo);

            if (!$dto->isValid()) {
                return $this->failValidationErrors(
                    'Formato no permitido. Solo se aceptan archivos CSV, TXT, XLSX y XLS.'
                );
            }

            // 3. Procesamiento (Aquí es donde entran el Converter y el ImportService)
            $resultado = $this->padronService->procesarCargaMasiva($dto);

            return $this->respond([
                'status'  => 200,
                'message' => 'Carga masiva completada con éxito.',
                'data'    => $resultado
            ]);
        } catch (\RuntimeException $e) {
            // 🚨 CAMBIO CRÍTICO: Usamos failValidationErrors (400) en lugar de failNotFound (404)
            // para que el frontend sepa que es un error de los DATOS del archivo.
            return $this->failValidationErrors($e->getMessage());
        } catch (\Exception $e) {
            // Error inesperado (500)
            return $this->failServerError('Error interno: ' . $e->getMessage());
        }
    }

    /**
     * Método adicional para eliminar un padrón y su tabla asociada (Eliminacion directa)
     */

    public function delete($id = null)
    {
        try {
            // Leemos si la URL trae el flag de purga total: ?permanente=true
            $esPermanente = $this->request->getGet('permanente') === 'true';

            $this->padronService->eliminarPadron($id, $esPermanente);

            $mensaje = $esPermanente
                ? 'Padrón y tabla física eliminados permanentemente.'
                : 'Padrón enviado a la papelera (Borrado lógico).';

            return $this->respondDeleted([
                'status' => 200,
                'message' => $mensaje
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }


    public function datos($id)
    {
        // Capturamos los datos del GET
        $filtros = [
            'min_lat' => $this->request->getGet('min_lat'),
            'max_lat' => $this->request->getGet('max_lat'),
            'min_lng' => $this->request->getGet('min_lng'),
            'max_lng' => $this->request->getGet('max_lng'),
        ];

        $data = $this->padronService->obtenerBeneficiarios($id, $filtros);
        return $this->response->setJSON($data);
    }
}
