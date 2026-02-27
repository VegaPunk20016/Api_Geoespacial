<?php

namespace Modules\Users\Services;

use Modules\Users\Interfaces\AuthServiceInterface;
use Modules\Users\DTOs\{RegisterRequest, LoginRequest, UpdateRequest};
use Modules\Users\Entities\User;
use Modules\Users\Models\UserModel;
use Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;
use InvalidArgumentException;
use RuntimeException;

class AuthService implements AuthServiceInterface
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function registerUser(RegisterRequest $req): User
    {
        $uuid = Uuid::uuid4()->toString();

        $user = new User([
            'id'       => $uuid,
            'username' => $req->username,
            'email'    => $req->email,
            'password' => $req->password,
            'role_id'  => 3 // ID 3 = User normal
        ]);

        if (!$this->userModel->insert($user)) {
            throw new RuntimeException('Error al registrar usuario en la base de datos.');
        }

        return $this->userModel->find($uuid);
    }

    public function login(LoginRequest $req): string
    {
        $user = $this->userModel
            ->select('users.*, roles.name as role_name')
            ->join('roles', 'roles.id = users.role_id')
            ->where('email', $req->email)
            ->first();

        if (!$user || !password_verify($req->password, $user->password)) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        $perms = $this->userModel->db->table('role_permissions rp')
            ->select('p.name')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $user->role_id)
            ->get()
            ->getResultArray();

        $permissions = array_column($perms, 'name');

        $payload = [
            'iat'   => time(),
            'exp'   => time() + 3600,
            'uid'   => $user->id,
            'email' => $user->email,
            'role'  => $user->role_name,
            'perms' => $permissions 
        ];

        return JWT::encode($payload, getenv('JWT_SECRET'), 'HS256');
    }

    public function updateUser(string $email, UpdateRequest $req): User
    {
        /** @var User|null $user */
        $user = $this->userModel->where('email', $email)->first();
        
        if (!$user) throw new InvalidArgumentException('Usuario no encontrado.');

        if ($req->username !== null) $user->username = $req->username;
        if ($req->email !== null)    $user->email    = $req->email;
        if ($req->password !== null) $user->password = $req->password;

        if (!$this->userModel->save($user)) {
            throw new RuntimeException('Error al actualizar.');
        }

        // --- LA CORRECCIÓN EMPIEZA AQUÍ ---
        $updatedUser = $this->userModel->find($user->id);
        
        if (!$updatedUser instanceof User) {
            throw new RuntimeException('Error de tipado: No se pudo recuperar la entidad User actualizada.');
        }

        return $updatedUser;
    }

    public function deleteUser(string $email): bool
    {
        $user = $this->userModel->where('email', $email)->first();
        if (!$user) throw new InvalidArgumentException('Usuario no encontrado.');
        
        return $this->userModel->delete($user->id);
    }

    public function setRole(string $email, int $roleId): bool
    {
        $user = $this->userModel->where('email', $email)->first();
        if (!$user) throw new InvalidArgumentException('Usuario no encontrado.');

        return $this->userModel->update($user->id, ['role_id' => $roleId]);
    }
}