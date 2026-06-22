<?php

use Illuminate\Support\Facades\Route;

$prefix = config('ldap-tools.routes.prefix', 'ldap-tools');
$middleware = config('ldap-tools.routes.middleware', ['web', 'auth']);
$controller = config('ldap-tools.routes.controller', 'Ideaserv\LdapTools\Http\Controllers\LdapUserController');

Route::group([
    'prefix' => $prefix,
    'middleware' => $middleware,
], function () use ($controller) {
    Route::get('users', $controller.'@index')
        ->name('ldap-tools.users.index');

    Route::get('users/{login}', $controller.'@show')
        ->where('login', '.*')
        ->name('ldap-tools.users.show');
});
