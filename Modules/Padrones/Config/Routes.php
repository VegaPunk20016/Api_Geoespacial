<?php

namespace Modules\Padrones\Config;

use Config\Services;

$routes = Services::routes();

$routes->group('api/padrones', ['namespace' => 'Modules\Padrones\Controllers'], function ($routes) {

    $routes->group('', ['filter' => 'jwt'], function ($routes) {
        $routes->get('/',                        'PadronController::index');
        $routes->get('(:segment)/beneficiarios', 'PadronController::getBeneficiarios/$1');
        $routes->get('(:segment)/clusters',      'PadronController::getClusters/$1');
        $routes->get('(:segment)/buscar',        'PadronController::buscar/$1');
        $routes->get('(:segment)',               'PadronController::show/$1');
        $routes->get('(:segment)/resumen', 'PadronController::getResumen/$1');
    });

    $routes->group('', ['filter' => ['jwt', 'role:super_admin,admin']], function ($routes) {
        $routes->post('/',                       'PadronController::create');
        $routes->post('(:segment)/importar',     'PadronController::importCsv/$1');
        $routes->delete('(:segment)',            'PadronController::delete/$1');
    });
});