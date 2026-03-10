<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cors;

class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $cors = new Cors();

        // Manejar solicitudes preflight (OPTIONS)
        if ($request->getMethod() === 'options') {
            $response = service('response');
            $response->setHeader('Access-Control-Allow-Origin', implode(', ', $cors->default['allowedOrigins']));
            $response->setHeader('Access-Control-Allow-Methods', implode(', ', $cors->default['allowedMethods']));
            $response->setHeader('Access-Control-Allow-Headers', implode(', ', $cors->default['allowedHeaders']));
            $response->setHeader('Access-Control-Allow-Credentials', $cors->default['supportsCredentials'] ? 'true' : 'false');
            $response->setHeader('Access-Control-Max-Age', $cors->default['maxAge']);
            return $response;
        }

        // Aplicar encabezados CORS a todas las solicitudes
        $response = service('response');
        $response->setHeader('Access-Control-Allow-Origin', implode(', ', $cors->default['allowedOrigins']));
        $response->setHeader('Access-Control-Allow-Methods', implode(', ', $cors->default['allowedMethods']));
        $response->setHeader('Access-Control-Allow-Headers', implode(', ', $cors->default['allowedHeaders']));
        $response->setHeader('Access-Control-Allow-Credentials', $cors->default['supportsCredentials'] ? 'true' : 'false');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No es necesario hacer nada después de la solicitud
        return $response;
    }
}