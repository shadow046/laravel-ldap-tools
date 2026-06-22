<?php

use Illuminate\Support\Facades\Route;

$prefix = config('ldap-tools.routes.prefix', 'ldap-tools');
$middleware = config('ldap-tools.routes.middleware', ['web', 'auth']);

Route::group([
    'prefix' => $prefix,
    'middleware' => $middleware,
], function () {
    Route::get('users', 'Ideaserv\LdapTools\Http\Controllers\LdapUserController@index')
        ->name('ldap-tools.users.index');

    Route::get('users/{login}', 'Ideaserv\LdapTools\Http\Controllers\LdapUserController@show')
        ->where('login', '.*')
        ->name('ldap-tools.users.show');
});
