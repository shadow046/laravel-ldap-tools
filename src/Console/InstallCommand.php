<?php

namespace Ideaserv\LdapTools\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ldap-tools:install
        {--force : Overwrite existing config and sample integration files}
        {--no-sample : Publish config only, without the sample login trait}';

    protected $description = 'Install LDAP tools config and optional sample login integration';

    public function handle()
    {
        $this->info('Installing Ideaserv LDAP Tools...');
        $this->newLine();

        $this->call('vendor:publish', [
            '--tag' => 'ldap-tools-config',
            '--force' => (bool) $this->option('force'),
        ]);

        if (! $this->option('no-sample')) {
            $this->publishSampleTrait();
        }

        $this->newLine();
        $this->info('Add these values to your .env:');
        $this->line('LDAP_HOST=ucs.apsoft.com.ph');
        $this->line('LDAP_PORT=636');
        $this->line('LDAP_BASE_DN="DC=teamapsoft,DC=lab"');
        $this->line('LDAP_USERNAME="tmsweb.id@teamapsoft.lab"');
        $this->line('LDAP_PASSWORD="secret"');
        $this->line('LDAP_SSL=true');
        $this->line('LDAP_TLS=false');
        $this->line('LDAP_TIMEOUT=5');
        $this->line('LDAP_LOGGING=false');
        $this->line('LDAP_TOOLS_ROUTES_ENABLED=true');
        $this->line('LDAP_TOOLS_ROUTE_PREFIX=ldap-tools');
        $this->line('LDAP_TOOLS_ROUTE_MIDDLEWARE=web,auth');

        $this->newLine();
        $this->info('Then clear config and test LDAP:');
        $this->line('php artisan config:clear');
        $this->line('php artisan ldap:find jolopez.id');
        $this->line('php artisan ldap:list-users --limit=5');

        $this->newLine();
        $this->info('Optional HTTP endpoints after login/auth middleware:');
        $this->line('GET /ldap-tools/users');
        $this->line('GET /ldap-tools/users?search=jolopez');
        $this->line('GET /ldap-tools/users?format=csv');
        $this->line('GET /ldap-tools/users/jolopez.id');

        $this->newLine();
        $this->info('To wire login, inspect: app/Support/LdapAuthenticatesUsers.php');

        return 0;
    }

    private function publishSampleTrait()
    {
        if (! function_exists('app_path')) {
            $this->warn('Cannot publish sample trait because app_path() is unavailable.');

            return;
        }

        $targetDirectory = app_path('Support');
        $targetPath = $targetDirectory.'/LdapAuthenticatesUsers.php';

        if (file_exists($targetPath) && ! $this->option('force')) {
            $this->warn('Sample login trait already exists: '.$targetPath);
            $this->line('Use --force to overwrite it.');

            return;
        }

        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        copy(__DIR__.'/../../stubs/LdapAuthenticatesUsers.stub', $targetPath);
        $this->info('Published sample login trait: '.$targetPath);
    }
}
