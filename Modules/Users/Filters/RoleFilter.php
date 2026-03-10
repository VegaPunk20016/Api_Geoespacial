<?php

namespace Modules\Users\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Si la ruta no especifica roles permitidos, dejamos pasar
        if (empty($arguments)) {
            return;
        }

        $header = $request->getHeaderLine('Authorization');
        $response = Services::response();

        // Extraemos el token igual que en tu JWTFilter
        if (!$header || !preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $response->setJSON([
                'status'  => 401,
                'message' => 'Token no proporcionado o formato inválido.'
            ])->setStatusCode(401);
        }

        $token = $matches[1];
        $key = getenv('JWT_SECRET');

        try {
            $decoded = JWT::decode($token, new Key($key, 'HS256'));

            // Extraemos el rol del payload (es un string, ej: 'admin')
            $userRole = $decoded->role ?? '';

            // Comparamos el rol del token con los roles permitidos en la ruta ($arguments)
            if (!in_array($userRole, $arguments)) {
                return $response->setJSON([
                    'status'  => 403,
                    'message' => "Acceso Denegado: Tu rol ({$userRole}) no tiene los permisos necesarios para realizar esta acción."
                ])->setStatusCode(403);
            }

        } catch (Exception $e) {
            return $response->setJSON([
                'status'  => 401,
                'message' => 'Error al validar permisos del token.'
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}