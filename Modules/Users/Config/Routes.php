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
    
});