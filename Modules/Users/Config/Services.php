<?php

namespace Modules\Users\Config;

use CodeIgniter\Config\BaseService;
use Modules\Users\Interfaces\AuthServiceInterface;
use Modules\Users\Services\AuthService;

/**
 * Servicios específicos del módulo Users
 */
class Services extends BaseService
{
    public static function authService(bool $getShared = true): AuthServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('authService');
        }

        return new AuthService();
    }
}