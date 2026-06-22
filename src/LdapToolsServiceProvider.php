<?php

namespace Ideaserv\LdapTools;

use Ideaserv\LdapTools\Console\FindLdapUserCommand;
use Ideaserv\LdapTools\Console\InstallCommand;
use Ideaserv\LdapTools\Console\ListLdapUsersCommand;
use Ideaserv\LdapTools\Console\SetLdapEmployeeIdCommand;
use Ideaserv\LdapTools\Services\LdapService;
use Illuminate\Support\ServiceProvider;

class LdapToolsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ldap-tools.php', 'ldap-tools');

        $this->app->singleton(LdapService::class, function () {
            return new LdapService(config('ldap-tools'));
        });

        $this->app->alias(LdapService::class, 'ldap-tools');
    }

    public function boot()
    {
        if (function_exists('config_path')) {
            $this->publishes([
                __DIR__.'/../config/ldap-tools.php' => config_path('ldap-tools.php'),
            ], 'ldap-tools-config');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                FindLdapUserCommand::class,
                InstallCommand::class,
                ListLdapUsersCommand::class,
                SetLdapEmployeeIdCommand::class,
            ]);
        }
    }
}
