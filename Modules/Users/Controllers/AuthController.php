<?php

namespace Modules\Users\Controllers;

use CodeIgniter\RESTful\ResourceController;
use Modules\Users\Services\AuthService;
use Modules\Users\DTOs\{RegisterRequest, LoginRequest, UpdateRequest};
use InvalidArgumentException;
use Exception;

class AuthController extends ResourceController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function register()
    {
        $rules = [
            'username' => 'required|min_length[3]|max_length[50]|is_unique[users.username]',
            'email'    => 'required|valid_email|is_unique[users.email]',
            'password' => 'required|min_length[8]'
        ];

        if (!$this->validate($rules)) return $this->failValidationErrors($this->validator->getErrors());

        try {
            $json = $this->request->getJSON(true) ?? [];
            $req = new RegisterRequest($json);
            
            $user = $this->authService->registerUser($req);

            return $this->respondCreated([
                'status'  => 201,
                'message' => 'Usuario registrado exitosamente',
                'data'    => ['email' => $user->email]
            ]);
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    public function login()
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required'
        ];

        if (!$this->validate($rules)) return $this->failValidationErrors($this->validator->getErrors());

        try {
            $json = $this->request->getJSON(true) ?? [];
            $req = new LoginRequest($json);
            
            $token = $this->authService->login($req);

            return $this->respond(['status' => 200, 'token' => $token]);
        } catch (InvalidArgumentException $e) {
            return $this->failUnauthorized($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    public function update($email = null)
    {
        if (!$email) return $this->fail('Email requerido.', 400);
        $email = urldecode($email);

        $rules = [
            'username' => "permit_empty|min_length[3]|max_length[50]|is_unique[users.username,email,{$email}]",
            'email'    => "permit_empty|valid_email|is_unique[users.email,email,{$email}]",
            'password' => "permit_empty|min_length[8]"
        ];

        if (!$this->validate($rules)) return $this->failValidationErrors($this->validator->getErrors());

        try {
            $json = $this->request->getJSON(true) ?? [];
            $req = new UpdateRequest($json);
            
            $user = $this->authService->updateUser($email, $req);

            return $this->respond(['status' => 200, 'message' => 'Usuario actualizado', 'data' => ['email' => $user->email]]);
        } catch (InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }

    public function delete($email = null)
    {
        if (!$email) return $this->fail('Email requerido.', 400);
        $email = urldecode($email);

        try {
            $this->authService->deleteUser($email);
            return $this->respondDeleted(['status' => 200, 'message' => "Usuario eliminado."]);
        } catch (InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError('Error al eliminar usuario.');
        }
    }

    public function setRole($email = null)
    {
        if (!$email) return $this->fail('Email requerido en la URL', 400);
        
        $email = urldecode($email);
        $roleId = $this->request->getJSON()->role_id ?? null;

        if (!$roleId) return $this->fail('El role_id es requerido en el JSON', 400);

        try {
            $this->authService->setRole($email, (int)$roleId);
            return $this->respond(['status' => 200, 'message' => "Rol actualizado a ID: {$roleId}"]);
        } catch (InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (Exception $e) {
            return $this->failServerError($e->getMessage());
        }
    }
}