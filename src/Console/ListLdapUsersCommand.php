<?php

namespace Ideaserv\LdapTools\Console;

use Ideaserv\LdapTools\Services\LdapService;
use Illuminate\Console\Command;

class ListLdapUsersCommand extends Command
{
    protected $signature = 'ldap:list-users
        {--search= : Filter by username, email, name, or employee number}
        {--limit=100 : Maximum records to return; use 0 for all available records}
        {--page-size=500 : LDAP page size}
        {--csv= : Write CSV output to this file path}
        {--format=table : Output format: table or csv}';

    protected $description = 'List LDAP user profiles using the configured service account';

    /**
     * @var array<int, string>
     */
    private $columns = [
        'samaccount_name',
        'employee_id',
        'employee_number',
        'mail',
        'user_principal_name',
        'user_principal_id',
        'display_name',
        'given_name',
        'surname',
        'department',
        'title',
        'company',
        'mobile',
        'member_of_count',
        'dn',
    ];

    public function handle(LdapService $ldap)
    {
        if (! $ldap->isConfigured()) {
            $this->error('LDAP is not configured.');

            return 1;
        }

        $search = $this->option('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;
        $limit = max(0, (int) $this->option('limit'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $format = strtolower((string) $this->option('format'));
        $csvPath = $this->option('csv');

        if (! in_array($format, ['table', 'csv'], true)) {
            $this->error('Invalid --format value. Use table or csv.');

            return 1;
        }

        $profiles = $ldap->listProfiles($search, $limit, $pageSize);

        if ($profiles === []) {
            $this->warn('No LDAP users found.');

            if ($ldap->lastError()) {
                $this->line('LDAP error: '.$ldap->lastError());
            }

            return 0;
        }

        if (is_string($csvPath) && trim($csvPath) !== '') {
            $this->writeCsv(trim($csvPath), $profiles);
            $this->info('CSV written to '.trim($csvPath));
        }

        if ($format === 'csv') {
            $this->line($this->csvString($profiles));

            return 0;
        }

        $this->info('LDAP users found: '.count($profiles));
        $this->newLine();
        $this->table($this->columns, $this->rows($profiles));

        return 0;
    }

    /**
     * @param  array<int, array<string, string>>  $profiles
     * @return array<int, array<int, string>>
     */
    private function rows(array $profiles)
    {
        $rows = [];

        foreach ($profiles as $profile) {
            $row = [];

            foreach ($this->columns as $column) {
                $row[] = isset($profile[$column]) ? $profile[$column] : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $profiles
     */
    private function writeCsv($path, array $profiles)
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->csvString($profiles));
    }

    /**
     * @param  array<int, array<string, string>>  $profiles
     */
    private function csvString(array $profiles)
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $this->columns);

        foreach ($this->rows($profiles) as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }
}
