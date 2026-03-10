<?php

namespace Modules\Padrones\Config;

use CodeIgniter\Config\BaseService;
use Modules\Padrones\Services\PadronService;
use Modules\Padrones\Services\PadronTableService;
use Modules\Padrones\Services\PadronImportService;
use Modules\Padrones\Services\PadronMapperService; 
use Modules\Padrones\Services\FileConverterService; // ✨ 1. Importamos el nuevo servicio
use Modules\Padrones\Models\CatalogoPadronModel;
use Modules\Padrones\Models\BeneficiarioDinamicoModel;

class Services extends BaseService
{
    public static function padronService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('padronService');
        }

        $db = \Config\Database::connect();
        $forge = \Config\Database::forge();
        
        // 1. Instanciamos los Modelos
        $catalogoModel = new CatalogoPadronModel();
        $modeloDinamico = new BeneficiarioDinamicoModel();
        
        // 2. Instanciamos las herramientas independientes
        $mapper = new PadronMapperService(); 
        $converterService = new FileConverterService(); // ✨ 2. Creamos la instancia del convertidor

        // 3. Instanciamos los Servicios que dependen de la BD o del Mapper
        $tableService = new PadronTableService($forge, $db);
        $importService = new PadronImportService($db, $mapper);

        // 4. Retornamos el PadronService inyectando TODOS los componentes
        return new PadronService(
            $catalogoModel, 
            $modeloDinamico, 
            $db, 
            $tableService, 
            $importService,
            $converterService 
        );
    }
}