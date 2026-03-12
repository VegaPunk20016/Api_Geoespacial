<?php

namespace Modules\Users\Services;

use Modules\Users\Interfaces\AuthServiceInterface;
use Modules\Users\DTOs\{RegisterRequest, LoginRequest, UpdateRequest};
use Modules\Users\Entities\User;
use Modules\Users\Models\UserModel;
use Modules\Users\Services\EmailService;
use Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;
use InvalidArgumentException;
use RuntimeException;

class AuthService implements AuthServiceInterface
{
    private UserModel $userModel;
    private EmailService $emailService;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->emailService = new EmailService();
    }

    public function registerUser(RegisterRequest $req): User
    {
        $uuid = Uuid::uuid4()->toString();

        $user = new User([
            'id'       => $uuid,
            'username' => $req->username,
            'email'    => $req->email,
            'password' => $req->password,
            'role_id'  => 3
        ]);

        if (!$this->userModel->insert($user)) {
            throw new RuntimeException('Error al registrar usuario en la base de datos.');
        }

        $this->emailService->sendWelcomeEmail($user->email, $user->username);

        return $this->userModel->find($uuid);
    }

    public function getAllUsers(): array
    {
        $users = $this->userModel
            ->withDeleted()
            ->select('users.id, users.username, users.email, roles.name as role_name, users.created_at, users.deleted_at')
            ->join('roles', 'roles.id = users.role_id')
            ->paginate(10);

        return $users;
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
        $user = $this->userModel->withDeleted()->where('email', $email)->first();

        if (!$user) {
            throw new InvalidArgumentException('Usuario no encontrado.');
        }

        $data = [
            'username'   => $req->username ?? $user->username,
            'email'      => $req->email ?? $user->email,
            'role_id'    => $req->role_id ?? $user->role_id,
            'deleted_at' => null, 
        ];

        if ($req->password !== null) {
            $data['password'] = $req->password;
        }


        if (!$this->userModel->withDeleted()->update($user->id, $data)) {
            throw new RuntimeException('Error al actualizar el usuario.');
        }

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


    public function forgotPassword(string $email): void
    {
        $user = $this->userModel->where('email', $email)->first();

        if (!$user) return;

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->userModel->update($user->id, [
            'reset_token'      => $token,
            'reset_expires_at' => $expiresAt
        ]);

        $this->emailService->sendPasswordRecoveryEmail($user->email, $token);
    }


    public function resetPassword(string $token, string $newPassword): bool
    {
        $user = $this->userModel->where('reset_token', $token)
            ->where('reset_expires_at >=', date('Y-m-d H:i:s'))
            ->first();

        if (!$user) {
            throw new InvalidArgumentException('El token es inválido o ha expirado.');
        }

        $user->password = $newPassword;
        $user->reset_token = null;
        $user->reset_expires_at = null;

        return $this->userModel->save($user);
    }
}
