<?php

namespace Modules\Padrones\Config;

use Config\Services;

$routes = Services::routes();

$routes->group('api/padrones', ['namespace' => 'Modules\Padrones\Controllers', 'filter' => 'jwt'], function ($routes) {

    // 1. Rutas específicas (Van ARRIBA)
    $routes->get('/', 'PadronController::index');
    $routes->get('(:segment)/buscar-cp/(:segment)', 'PadronController::buscarPorCP/$1/$2');
    $routes->get('(:segment)/beneficiarios',        'PadronController::getBeneficiarios/$1');
    $routes->get('(:segment)/clusters',             'PadronController::getClusters/$1');
    $routes->get('(:segment)/buscar',               'PadronController::buscar/$1');
    $routes->get('(:segment)/resumen',              'PadronController::getResumen/$1');
    $routes->get('(:segment)/plantilla', 'PadronController::getPlantilla/$1');
    $routes->get('(:segment)', 'PadronController::show/$1');

    $routes->group('', ['filter' => 'role:super_admin,admin'], function ($routes) {
        $routes->post('/', 'PadronController::create');
        $routes->post('(:segment)/importar', 'PadronController::importCsv/$1');
        $routes->delete('(:segment)', 'PadronController::delete/$1');
        
        // CRUD de Beneficiarios
        $routes->post('(:segment)/beneficiarios', 'PadronController::createBeneficiario/$1');
        
        // CAMBIADO A PUT PARA COINCIDIR CON AXIOS
        $routes->put('(:segment)/beneficiarios/(:segment)', 'PadronController::updateBeneficiario/$1/$2');
        
        $routes->delete('(:segment)/beneficiarios/(:segment)', 'PadronController::deleteBeneficiario/$1/$2');
    });
});