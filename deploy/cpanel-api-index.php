<?php

// Example GoDaddy/cPanel API front controller.
// Expected paths:
//   /home/ACCOUNT/study-schedule-backend/   (private framework source)
//   /home/ACCOUNT/public_html/api/           (this file)
//
// Copy to public_html/api/index.php after updating the private folder name if needed.
// NEVER copy backend/app, backend/vendor, backend/writable or backend/.env into public_html.
use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.2';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    http_response_code(503);
    exit('This service requires PHP 8.2 or newer.');
}

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require dirname(__DIR__, 2) . '/study-schedule-backend/app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
exit(Boot::bootWeb($paths));
