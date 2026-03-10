<?php

namespace Modules\Padrones\Models;

use CodeIgniter\Model;
use Modules\Padrones\Entities\CatalogoPadron;

class CatalogoPadronModel extends Model
{
    protected $table            = 'catalogo_padrones';
    protected $primaryKey       = 'id';    
    protected $useAutoIncrement = false;  
    protected $returnType       = CatalogoPadron::class;
    protected $useSoftDeletes   = true;
    protected $allowedFields = [
        'id', 
        'nombre_padron', 
        'descripcion', 
        'clave_interna', 
        'entidad_federativa', 
        'categoria', 
        'nombre_tabla_destino'
    ];
    protected $useTimestamps    = true;
}