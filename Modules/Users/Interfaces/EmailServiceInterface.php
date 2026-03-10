<?php

namespace Modules\Users\Interfaces;

interface EmailServiceInterface
{
    public function sendWelcomeEmail(string $toEmail, string $username): bool;
    public function sendPasswordRecoveryEmail(string $toEmail, string $resetToken): bool;
}