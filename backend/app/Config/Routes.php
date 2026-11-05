<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);

$routes->get('/', 'Api\\Health::index');
$routes->get('health', 'Api\\Health::index');
$routes->get('ready', 'Api\\Health::ready');

// Shield handles the standard login form; registration/magic links are disabled.
service('auth')->routes($routes, ['only' => ['login']]);

// All admin endpoints, including reads/CSV, require a logged-in superadmin.
$routes->group('admin', ['namespace' => 'App\Controllers\Api', 'filter' => 'professor'], static function ($routes) {
    $routes->get('me', 'Professor::me');
    $routes->post('logout', 'Professor::logout');
    $routes->get('dashboard', 'Professor::dashboard');
    $routes->get('studies', 'Professor::studies');
    $routes->post('studies', 'Professor::createStudy');
    $routes->put('studies/(:num)', 'Professor::updateStudy/$1');
    $routes->post('studies/(:num)/status', 'Professor::changeStudyStatus/$1');

    $routes->get('sessions', 'Professor::sessions');
    $routes->post('studies/(:num)/sessions', 'Professor::createSessions/$1');
    $routes->post('sessions/(:num)/capacity', 'Professor::changeCapacity/$1');
    $routes->post('sessions/(:num)/status', 'Professor::changeSessionStatus/$1');

    $routes->get('invitations', 'Professor::invitations');
    $routes->post('invitations', 'Professor::issueInvitation');
    $routes->post('invitations/(:num)/revoke', 'Professor::revokeInvitation/$1');

    $routes->get('bookings', 'Professor::bookings');
    $routes->get('bookings.csv', 'Professor::exportBookings');
    $routes->post('bookings/(:num)/cancel', 'Professor::cancelBooking/$1');
    $routes->post('bookings/(:num)/reschedule', 'Professor::rescheduleBooking/$1');
});
