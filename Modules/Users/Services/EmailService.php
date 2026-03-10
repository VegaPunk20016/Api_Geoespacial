<?php

namespace Modules\Users\Services;

use Modules\Users\Interfaces\EmailServiceInterface;
use CodeIgniter\Email\Email;
use Config\Services;

class EmailService implements EmailServiceInterface
{
    private Email $emailClient;

    public function __construct()
    {
        $this->emailClient = Services::email();
    }

    public function sendWelcomeEmail(string $toEmail, string $username): bool
    {
        $this->emailClient->clear(); 
        
        $this->emailClient->setTo($toEmail);
        $this->emailClient->setSubject('¡Bienvenido a Iidesoft Geoespacial!');
        $mensaje = "
            <h2>¡Hola, {$username}!</h2>
            <p>Gracias por registrarte en <b>Iidesoft Geoespacial</b>.</p>
            <p>Tu cuenta ha sido creada exitosamente. Ahora puedes explorar y gestionar nuestros datos geoespaciales.</p>
            <br>
            <p>Saludos,<br>El equipo de Iidesoft</p>
        ";
        
        $this->emailClient->setMessage($mensaje);

        if (!$this->emailClient->send()) {
            return false;
        }

        return true;
    }

    // En Modules\Users\Services\EmailService.php

public function sendPasswordRecoveryEmail(string $toEmail, string $resetToken): bool
{
    $this->emailClient->clear();
    $this->emailClient->setTo($toEmail);
    $this->emailClient->setSubject('Recuperación de Contraseña - Iidesoft');
 
    $frontendUrl = env('public.frontendURL') . "reset-password?token={$resetToken}";

    $mensaje = "
        <div style='font-family: sans-serif; color: #012737;'>
            <h2 style='color: #177DA6;'>Recuperación de Contraseña</h2>
            <p>Hemos recibido una solicitud para restablecer la contraseña de tu cuenta.</p>
            <p>Haz clic en el botón de abajo para continuar:</p>
            <div style='margin: 30px 0;'>
                <a href='{$frontendUrl}' style='padding: 12px 20px; background-color: #177DA6; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                    Restablecer Contraseña
                </a>
            </div>
            <p style='font-size: 12px; color: #666;'>
                <i>Si no solicitaste este cambio, puedes ignorar este correo. El enlace expirará en 1 hora.</i>
            </p>
        </div>
    ";
    
    $this->emailClient->setMessage($mensaje);
    return $this->emailClient->send();
}
}