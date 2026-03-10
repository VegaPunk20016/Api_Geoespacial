<?php

namespace Modules\Padrones\Entities;

use CodeIgniter\Entity\Entity;

class CatalogoPadron extends Entity
{
    protected $attributes = [
        'id'                   => null,
        'nombre_padron'        => null,
        'descripcion'          => null,
        'clave_interna'        => null,
        'entidad_federativa'   => null,
        'categoria'            => null, 
        'nombre_tabla_destino' => null,
        'created_at'           => null,
        'updated_at'           => null,
        'deleted_at'           => null,
    ];

    protected $casts = [
        'categoria' => 'string',
    ];
}