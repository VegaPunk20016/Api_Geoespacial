<?php

namespace Modules\Users\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class JWTFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine('Authorization');
        $response = Services::response();

        if (!$header || !preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $response->setJSON([
                'status'  => 401,
                'error'   => 'Unauthorized',
                'message' => 'Token no proporcionado o formato inválido. Use: Bearer <token>'
            ])->setStatusCode(401);
        }

        $token = $matches[1];

        try {
            $key = getenv('JWT_SECRET');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));


        } catch (Exception $e) {
            return $response->setJSON([
                'status'  => 401,
                'error'   => 'Unauthorized',
                'message' => 'Token inválido o expirado: ' . $e->getMessage()
            ])->setStatusCode(401);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}