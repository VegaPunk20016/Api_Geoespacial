<?php

namespace Modules\Users\Config;

use Config\Services;

$routes = Services::routes();


$routes->group('api/users', ['namespace' => 'Modules\Users\Controllers'], function($routes) {
    $routes->post('register', 'AuthController::register');
    $routes->post('login', 'AuthController::login');

    $routes->put('(:segment)', 'AuthController::update/$1', ['filter' => 'jwt:write']);
    $routes->delete('(:segment)', 'AuthController::delete/$1', ['filter' => 'jwt:delete']);
    
    $routes->patch('set-role/(:segment)', 'AuthController::setRole/$1', ['filter' => 'jwt:manage_users']);
    $routes->get('/', 'AuthController::index', ['filter' => 'jwt:manage_users']);
    
    $routes->post('forgot-password', 'AuthController::forgotPassword');
    $routes->post('reset-password', 'AuthController::resetPassword');
});

//padron de beneficiarios, registros de padron, diferentes registros (personas, empresas, etc), busqueda de registros, actualizacion de registros, eliminacion de registros, exportacion de registros, importacion de registros, etc
// npmbre, latitud longitud, clave, descripcion o detalles gral (todo) textbox en genral, yabla dinamica, datos GENERALES, manual y masiva