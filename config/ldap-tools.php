<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LDAP Connection
    |--------------------------------------------------------------------------
    |
    | Use LDAP_SSL=true for LDAPS, usually port 636. Use LDAP_TLS=true only
    | when your server expects StartTLS, usually over port 389.
    |
    */

    'host' => env('LDAP_HOST'),
    'port' => (int) env('LDAP_PORT', 389),
    'base_dn' => env('LDAP_BASE_DN'),
    'username' => env('LDAP_USERNAME'),
    'password' => env('LDAP_PASSWORD'),
    'ssl' => filter_var(env('LDAP_SSL', false), FILTER_VALIDATE_BOOLEAN),
    'tls' => filter_var(env('LDAP_TLS', false), FILTER_VALIDATE_BOOLEAN),
    'timeout' => (int) env('LDAP_TIMEOUT', 5),
    'logging' => filter_var(env('LDAP_LOGGING', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | HTTP Routes
    |--------------------------------------------------------------------------
    |
    | These routes expose LDAP lookup/listing over HTTP. Keep auth middleware
    | enabled unless you are placing these routes behind another trusted layer.
    |
    */

    'routes' => [
        'enabled' => filter_var(env('LDAP_TOOLS_ROUTES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'prefix' => env('LDAP_TOOLS_ROUTE_PREFIX', 'ldap-tools'),
        'middleware' => array_filter(explode(',', env('LDAP_TOOLS_ROUTE_MIDDLEWARE', 'web,auth'))),
        'controller' => env('LDAP_TOOLS_ROUTE_CONTROLLER', 'Ideaserv\LdapTools\Http\Controllers\LdapUserController'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile Fields
    |--------------------------------------------------------------------------
    |
    | These attributes are read from LDAP and normalized by the service.
    | No password fields are read or printed by the package commands.
    |
    */

    'attributes' => [
        'dn',
        'samaccountname',
        'employeeid',
        'employeenumber',
        'mail',
        'userprincipalname',
        'cn',
        'displayname',
        'givenname',
        'sn',
        'department',
        'title',
        'company',
        'telephonenumber',
        'mobile',
        'memberof',
    ],
];
