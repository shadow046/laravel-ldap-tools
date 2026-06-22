<?php

namespace Ideaserv\LdapTools\Http\Controllers;

use Ideaserv\LdapTools\Services\LdapService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LdapUserController extends Controller
{
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
        'telephone_number',
        'mobile',
        'member_of_count',
        'dn',
    ];

    public function index(Request $request, LdapService $ldap)
    {
        if (! $ldap->isConfigured()) {
            return response()->json([
                'message' => 'LDAP is not configured.',
            ], 422);
        }

        $search = $request->query('search');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;
        $limit = max(0, (int) $request->query('limit', 100));
        $pageSize = max(1, (int) $request->query('page_size', $request->query('page-size', 500)));
        $format = strtolower((string) $request->query('format', 'json'));

        $users = $ldap->listProfiles($search, $limit, $pageSize);

        if ($format === 'csv') {
            $filename = 'ldap-users-'.date('Ymd-His').'.csv';

            return response($this->csvString($users), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        return response()->json([
            'data' => $users,
            'meta' => [
                'count' => count($users),
                'limit' => $limit,
                'page_size' => $pageSize,
                'search' => $search,
            ],
            'ldap_error' => $users === [] ? $ldap->lastError() : null,
        ]);
    }

    public function show($login, LdapService $ldap)
    {
        if (! $ldap->isConfigured()) {
            return response()->json([
                'message' => 'LDAP is not configured.',
            ], 422);
        }

        $profile = $ldap->findProfile((string) $login);

        if ($profile === null) {
            return response()->json([
                'message' => 'LDAP user not found.',
                'ldap_error' => $ldap->lastError(),
            ], 404);
        }

        return response()->json([
            'data' => $profile,
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $profiles
     */
    private function csvString(array $profiles)
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $this->columns);

        foreach ($profiles as $profile) {
            $row = [];

            foreach ($this->columns as $column) {
                $row[] = isset($profile[$column]) ? $profile[$column] : '';
            }

            fputcsv($stream, $row);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }
}
