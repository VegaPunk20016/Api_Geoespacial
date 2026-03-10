<?php

namespace Modules\Padrones\DTOs;

class CreatePadronRequest
{
    public string $nombre_padron;
    public ?string $descripcion;
    public ?string $clave_interna;
    public string $entidad_federativa;
    public string $categoria; 

    public function __construct(object $jsonData)
    {
        $this->nombre_padron      = $jsonData->nombre_padron ?? '';
        $this->descripcion        = $jsonData->descripcion ?? null;
        $this->clave_interna      = $jsonData->clave_interna ?? null;
        $this->entidad_federativa = $jsonData->entidad_federativa ?? '';
        $this->categoria          = $jsonData->categoria ?? 'General';
    }

    public function isValid(): bool
    {
        return !empty($this->nombre_padron) && !empty($this->entidad_federativa);
    }
}