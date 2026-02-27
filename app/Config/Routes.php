<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$modulesPath = ROOTPATH . 'modules/';
$modules = ['Users', 'DENUE', 'INE'];

foreach ($modules as $module) {
    $routesPath = $modulesPath . $module . '/Config/Routes.php';
    if (file_exists($routesPath)) {
        require $routesPath;
    }
}