<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);

// Explicit, read-only foundation endpoints. Study/booking routes arrive in Phase 2.
$routes->get('/', 'Api\\Health::index');
$routes->get('health', 'Api\\Health::index');
$routes->get('ready', 'Api\\Health::ready');
