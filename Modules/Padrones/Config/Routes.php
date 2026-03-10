<?php

namespace Modules\Padrones\Config;

use Config\Services;

$routes = Services::routes();

/**
 * Rutas del Módulo de Padrones
 * Aplicando seguridad por niveles:
 * 1. Autenticación (JWT): ¿Es un usuario válido?
 * 2. Autorización (Role): ¿Tiene permiso para esta acción?
 */
$routes->group('api/padrones', ['namespace' => 'Modules\Padrones\Controllers'], function ($routes) {
    
    $routes->group('', ['filter' => 'jwt'], function($routes) {
        $routes->get('(:segment)/beneficiarios', 'PadronController::getBeneficiarios/$1');
        // Listar todos los padrones creados
        $routes->get('/', 'PadronController::index');
        // Ver detalles de un padrón específico (La ruta corta va DESPUÉS)
        $routes->get('(:segment)', 'PadronController::show/$1');
        
    });
    $routes->group('', ['filter' => ['jwt', 'role:super_admin,admin']], function($routes) {
        
        // Crear un nuevo catálogo y su respectiva tabla dinámica SQL
        $routes->post('/', 'PadronController::create'); 
        
        // Subir y procesar el archivo CSV para un padrón existente
        $routes->post('(:segment)/importar', 'PadronController::importCsv/$1'); 

        // Eliminar un padrón y su tabla dinámica SQL asociada
        $routes->delete('(:segment)', 'PadronController::delete/$1');
       
    });

});