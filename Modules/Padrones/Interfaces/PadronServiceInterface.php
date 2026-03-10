<?php

namespace Modules\Padrones\Interfaces;

use Modules\Padrones\DTOs\CreatePadronRequest;
use Modules\Padrones\DTOs\ImportCsvRequest;
use Modules\Padrones\Entities\CatalogoPadron;

interface PadronServiceInterface
{
    public function crearNuevoPadron(CreatePadronRequest $request): CatalogoPadron;

    public function procesarCargaMasiva(ImportCsvRequest $request): array;

    public function obtenerTodosLosPadrones(): array;

    public function obtenerPadronPorId(string $id): ?CatalogoPadron;

    public function obtenerBeneficiarios(string $id): array;
    public function eliminarPadron(string $idPadron, bool $permanente = false): bool;
}