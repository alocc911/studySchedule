<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Security extends BaseConfig
{
    // Session-backed CSRF protection is required for Shield's session login.
    public string $csrfProtection = 'session';
    public bool $tokenRandomize = false;
    public string $tokenName = 'csrf_token';
    public string $headerName = 'X-CSRF-TOKEN';
    public string $cookieName = 'csrf_cookie_name';
    public int $expires = 7200;

    // A session-scoped token avoids an unnecessary refresh round trip on every
    // JSON mutation. It is never exposed to third-party origins.
    public bool $regenerate = false;
    public bool $redirect = false;
}
