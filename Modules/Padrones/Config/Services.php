<?php

namespace Modules\Padrones\Config;

use CodeIgniter\Config\BaseService;
use Modules\Padrones\Services\PadronService;
use Modules\Padrones\Services\PadronTableService;
use Modules\Padrones\Services\PadronImportService;
use Modules\Padrones\Services\PadronMapperService;
use Modules\Padrones\Services\FileConverterService;
use Modules\Padrones\Models\CatalogoPadronModel;
use Modules\Padrones\Models\BeneficiarioDinamicoModel;

class Services extends BaseService
{
    public static function padronService($getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('padronService');
        }

        $db    = \Config\Database::connect();
        $forge = \Config\Database::forge();

        $catalogoModel  = new CatalogoPadronModel();
        $modeloDinamico = new BeneficiarioDinamicoModel();
        $mapper         = new PadronMapperService();
        $converterService = new FileConverterService();
        $tableService   = new PadronTableService($forge, $db);
        $importService  = new PadronImportService($db, $mapper);

        // Cache handler de CI4 — configurado para usar MySQL en app/Config/Cache.php
        $cache = \Config\Services::cache();

        return new PadronService(
            $catalogoModel,
            $modeloDinamico,
            $db,
            $tableService,
            $importService,
            $converterService,
            $cache
        );
    }
}