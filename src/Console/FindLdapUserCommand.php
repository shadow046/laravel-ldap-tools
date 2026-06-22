<?php

namespace Ideaserv\LdapTools\Console;

use Ideaserv\LdapTools\Services\LdapService;
use Illuminate\Console\Command;

class FindLdapUserCommand extends Command
{
    protected $signature = 'ldap:find {login : AD username, email, UPN, or employee number}';

    protected $description = 'Find an LDAP user profile using the configured service account';

    public function handle(LdapService $ldap)
    {
        $login = (string) $this->argument('login');

        if (! $ldap->isConfigured()) {
            $this->error('LDAP is not configured.');

            return 1;
        }

        $profile = $ldap->findProfile($login);

        if ($profile === null) {
            $this->warn("No LDAP user found for [{$login}].");

            if ($ldap->lastError()) {
                $this->line('LDAP error: '.$ldap->lastError());
            }

            return 1;
        }

        $this->info("LDAP user found for [{$login}]:");
        $this->newLine();

        foreach ($profile as $key => $value) {
            $this->line(str_pad($key, 22).': '.($value === '' ? '(empty)' : $value));
        }

        return 0;
    }
}
