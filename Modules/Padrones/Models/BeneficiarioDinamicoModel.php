<?php

namespace Modules\Padrones\Models;

use CodeIgniter\Model;

class BeneficiarioDinamicoModel extends Model
{
    protected $table            = ''; 
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = false; 
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'id', 
        'catalogo_padron_id', 
        'clave_unica', 
        'nombre_completo',
        'municipio', 
        'seccion', 
        'latitud', 
        'longitud',
        'estatus_duplicidad', 
        'datos_generales', 
        'created_at'
    ];
    protected $useTimestamps = false; 
}