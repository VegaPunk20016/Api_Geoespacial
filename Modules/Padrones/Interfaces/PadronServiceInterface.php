<?php

namespace Modules\Padrones\Interfaces;

use Modules\Padrones\DTOs\CreatePadronRequest;
use Modules\Padrones\DTOs\ImportCsvRequest;
use Modules\Padrones\Entities\CatalogoPadron;

interface PadronServiceInterface
{
    // Catálogo
    public function crearNuevoPadron(CreatePadronRequest $request): CatalogoPadron;
    public function obtenerTodosLosPadrones(): array;
    public function obtenerPadronPorId(string $id): ?CatalogoPadron;
    public function eliminarPadron(string $idPadron, bool $permanente = false): bool;

    // Importación
    public function procesarCargaMasiva(ImportCsvRequest $request): array;
    public function previsualizarArchivo(ImportCsvRequest $request): array;
    public function importarConMapeoManual(string $padronId, array $mapeo): array;
    // Consulta
    public function obtenerBeneficiarios(string $id, array $filtros = []): array;
    public function obtenerDetalleBeneficiario(string $padronId, string $beneficiarioId): ?array;
    public function obtenerClusters(string $id, array $filtros = []): array;
    public function buscarMunicipios(string $id, string $termino): array;
    public function buscarPorCodigoPostal(string $idPadron, string $cp, int $limit = 1000): array;
    public function obtenerResumenAgnostico(string $id, ?string $municipio = null): array;
    public function obtenerPlantillaCampos(string $id): array;

    // CRUD beneficiarios
    public function guardarBeneficiario(string $padronId, array $datosFijos, array $datosGenerales = []): array;
    public function actualizarBeneficiario(string $padronId, string $beneficiarioId, array $datosFijos, array $datosGenerales = []): bool;
    public function eliminarBeneficiario(string $padronId, string $beneficiarioId): bool;
    public function generarArchivoCsvExportacion(string $id): string;
    public function buscarGlobal(string $idPadron, string $termino, int $pagina = 1, int $limit = 50): array;
}
