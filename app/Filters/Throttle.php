<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class Throttle implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // 1. Llamamos al servicio de Throttler (La herramienta de conteo)
        $throttler = Services::throttler();

        // 2. Obtenemos la IP del usuario para identificar quién es
        $ipAddress = $request->getIPAddress();
        
        // Creamos una "clave" única para esa IP
        $key = 'api_limit_' . md5($ipAddress);

        // 3. LA REGLA: 60 peticiones cada 60 segundos
        // check(identificador, capacidad, segundos)
        if ($throttler->check($key, 60, 60) === false) {
            
            return Services::response()
                ->setStatusCode(429)
                ->setJSON([
                    'status' => 429,
                    'error' => 'Demasiadas solicitudes. Intenta de nuevo en un minuto.'
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}