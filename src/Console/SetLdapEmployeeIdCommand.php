<?php

namespace Ideaserv\LdapTools\Console;

use Ideaserv\LdapTools\Services\LdapService;
use Illuminate\Console\Command;

class SetLdapEmployeeIdCommand extends Command
{
    protected $signature = 'ldap:set-employee-id
        {login : AD username, email, UPN, or current employee number}
        {id_number : Employee ID number to write}
        {--field=employeeID : LDAP field to update: employeeID or employeeNumber}
        {--commit : Actually write to LDAP. Without this, only shows a dry-run preview.}';

    protected $description = 'Set an LDAP employeeID or employeeNumber value using the configured service account';

    public function handle(LdapService $ldap)
    {
        if (! $ldap->isConfigured()) {
            $this->error('LDAP is not configured.');

            return 1;
        }

        $login = (string) $this->argument('login');
        $idNumber = trim((string) $this->argument('id_number'));
        $field = (string) $this->option('field');

        if (! in_array($field, ['employeeID', 'employeeNumber'], true)) {
            $this->error('Invalid --field value. Use employeeID or employeeNumber.');

            return 1;
        }

        if ($idNumber === '') {
            $this->error('ID number must not be empty.');

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
        $this->line('  dn: '.$profile['dn']);
        $this->line('  user_principal_id: '.$profile['user_principal_id']);
        $this->line('  samaccount_name: '.$profile['samaccount_name']);
        $this->line('  mail: '.$profile['mail']);
        $this->line('  current employee_id: '.($profile['employee_id'] === '' ? '(empty)' : $profile['employee_id']));
        $this->line('  current employee_number: '.($profile['employee_number'] === '' ? '(empty)' : $profile['employee_number']));
        $this->newLine();

        if (! $this->option('commit')) {
            $this->warn('Dry run only. No LDAP changes were made.');
            $this->line("Would set {$field} to [{$idNumber}].");
            $this->line('Run again with --commit to write the change.');

            return 0;
        }

        $result = $ldap->updateEmployeeIdentifier($login, $idNumber, $field);

        if ($result === null) {
            $this->error("Failed to update {$field} for [{$login}].");
            $this->line('LDAP error: '.($ldap->lastError() ?: '(unavailable)'));

            return 1;
        }

        $this->info("Updated {$field} for [{$login}].");
        $this->line('  before employee_id: '.($result['before']['employee_id'] === '' ? '(empty)' : $result['before']['employee_id']));
        $this->line('  before employee_number: '.($result['before']['employee_number'] === '' ? '(empty)' : $result['before']['employee_number']));
        $this->line('  after employee_id: '.($result['after']['employee_id'] === '' ? '(empty)' : $result['after']['employee_id']));
        $this->line('  after employee_number: '.($result['after']['employee_number'] === '' ? '(empty)' : $result['after']['employee_number']));

        return 0;
    }
}
