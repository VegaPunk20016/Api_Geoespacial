<?php

namespace Modules\Padrones\Config;

use Config\Services;

$routes = Services::routes();

$routes->group('api/padrones', ['namespace' => 'Modules\Padrones\Controllers', 'filter' => 'jwt'], function ($routes) {

    // ── Todos los roles autenticados ──────────────────────────────────────────
    $routes->get('/',                                        'PadronController::index');
    $routes->get('(:segment)/buscar-cp/(:segment)',          'PadronController::buscarPorCP/$1/$2');
    $routes->get('(:segment)/beneficiarios/(:segment)',      'PadronController::getBeneficiarioDetalle/$1/$2');
    $routes->get('(:segment)/beneficiarios',                 'PadronController::getBeneficiarios/$1');
    $routes->get('(:segment)/clusters',                      'PadronController::getClusters/$1');
    $routes->get('(:segment)/buscar',                        'PadronController::buscar/$1');
    $routes->get('(:segment)/buscar-global', 'PadronController::buscarGlobal/$1');
    $routes->get('(:segment)/resumen',                       'PadronController::getResumen/$1');
    $routes->get('(:segment)/plantilla',                     'PadronController::getPlantilla/$1');
    $routes->get('(:segment)',                               'PadronController::show/$1');
    $routes->get('(:segment)/exportar', 'PadronController::exportarTodos/$1');

    // ── Solo admin y super_admin ──────────────────────────────────────────────
    $routes->group('', ['filter' => 'role:super_admin,admin'], function ($routes) {
        $routes->post('/',                                       'PadronController::create');

        // Importación clásica (automática)
        $routes->post('(:segment)/importar',                    'PadronController::importCsv/$1');
        // Importación con mapeo manual del usuario
        $routes->post('(:segment)/preview-csv',                 'PadronController::previewCsv/$1');
        $routes->post('(:segment)/importar-mapeado',            'PadronController::importarConMapeo/$1');

        $routes->delete('(:segment)',                           'PadronController::delete/$1');

        // CRUD Beneficiarios
        $routes->post('(:segment)/beneficiarios',               'PadronController::createBeneficiario/$1');
        $routes->put('(:segment)/beneficiarios/(:segment)',     'PadronController::updateBeneficiario/$1/$2');
        $routes->patch('(:segment)/beneficiarios/(:segment)',   'PadronController::updateBeneficiario/$1/$2');
        $routes->delete('(:segment)/beneficiarios/(:segment)',  'PadronController::deleteBeneficiario/$1/$2');
    });
});
