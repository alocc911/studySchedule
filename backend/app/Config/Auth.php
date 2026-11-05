<?php

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;

final class Auth extends ShieldAuth
{
    // A single professor account is provisioned via CLI. Never permit public signup.
    public bool $allowRegistration = false;
    public bool $allowMagicLinkLogins = false;

    public array $redirects = [
        'register' => '/admin/',
        'login' => '/admin/',
        'logout' => '/admin/',
        'force_reset' => '/admin/',
        'permission_denied' => '/admin/',
        'group_denied' => '/admin/',
    ];

    public array $sessionConfig = [
        'field' => 'user',
        'allowRemembering' => false,
        'rememberCookieName' => 'remember',
        'rememberLength' => 30 * DAY,
    ];

    // Shield supports email-based 2FA; enable when the site's mail transport is
    // configured during security/deployment, before handling real participant data.
}
