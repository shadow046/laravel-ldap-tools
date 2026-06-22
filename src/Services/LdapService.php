<?php

namespace Ideaserv\LdapTools\Services;

use RuntimeException;

class LdapService
{
    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @var string|null
     */
    private $lastError;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured()
    {
        return $this->filled($this->configValue('host'))
            && $this->filled($this->configValue('base_dn'))
            && $this->filled($this->configValue('username'))
            && $this->filled($this->configValue('password'));
    }

    public function lastError()
    {
        return $this->lastError;
    }

    public function attempt($login, $password)
    {
        return $this->authenticate($login, $password) !== null;
    }

    /**
     * @return array<string, string>|null
     */
    public function authenticate($login, $password)
    {
        if (! $this->isConfigured() || trim((string) $password) === '') {
            return null;
        }

        try {
            $connection = $this->connectServiceAccount();
            $entry = $this->findEntry($connection, (string) $login);
            $dn = $this->firstAttribute($entry, 'dn');

            if ($entry === null || $dn === null) {
                return null;
            }

            if (! @ldap_bind($connection, $dn, (string) $password)) {
                $this->lastError = $this->ldapError($connection);

                return null;
            }

            return $this->profileFromEntry($entry);
        } catch (\Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return null;
        }
    }

    /**
     * Search LDAP using the service account only. This does not verify the
     * employee password.
     *
     * @return array<string, string>|null
     */
    public function findProfile($login)
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $connection = $this->connectServiceAccount();

            return $this->profileFromEntry($this->findEntry($connection, (string) $login));
        } catch (\Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function listProfiles($search = null, $limit = 100, $pageSize = 500)
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $limit = max(0, (int) $limit);
        $pageSize = max(1, (int) $pageSize);

        try {
            $connection = $this->connectServiceAccount();
            $filter = $this->userListFilter($search);
            $entries = $this->searchEntries($connection, $filter, $pageSize);
            $profiles = [];

            foreach ($entries as $entry) {
                $profile = $this->profileFromEntry($entry);

                if ($profile === null) {
                    continue;
                }

                $profiles[] = $profile;

                if ($limit > 0 && count($profiles) >= $limit) {
                    break;
                }
            }

            return $profiles;
        } catch (\Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return [];
        }
    }

    /**
     * @return array{before: array<string, string>, after: array<string, string>, attribute: string}|null
     */
    public function updateEmployeeIdentifier($login, $value, $attribute = 'employeeID')
    {
        if (! in_array($attribute, ['employeeID', 'employeeNumber'], true)) {
            $this->lastError = 'Invalid attribute. Use employeeID or employeeNumber.';

            return null;
        }

        try {
            $connection = $this->connectServiceAccount();
            $entry = $this->findEntry($connection, (string) $login);
            $before = $this->profileFromEntry($entry);
            $dn = $this->firstAttribute($entry, 'dn');

            if ($before === null || $dn === null) {
                return null;
            }

            if (! @ldap_mod_replace($connection, $dn, [$attribute => [(string) $value]])) {
                $this->lastError = $this->ldapError($connection);

                return null;
            }

            $after = $this->profileFromEntry($this->findEntry($connection, (string) $login));

            if ($after === null) {
                return null;
            }

            return [
                'before' => $before,
                'after' => $after,
                'attribute' => $attribute,
            ];
        } catch (\Throwable $exception) {
            $this->lastError = $exception->getMessage();

            return null;
        }
    }

    /**
     * @return resource|\LDAP\Connection
     */
    private function connectServiceAccount()
    {
        $host = (string) $this->configValue('host');
        $port = (int) $this->configValue('port', 389);
        $uri = $this->configValue('ssl') ? "ldaps://{$host}:{$port}" : $host;
        $connection = @ldap_connect($uri, $port);

        if (! $connection) {
            throw new RuntimeException("Unable to connect to LDAP host [{$host}:{$port}].");
        }

        @ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        @ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, (int) $this->configValue('timeout', 5));

        if ($this->configValue('tls') && ! @ldap_start_tls($connection)) {
            throw new RuntimeException('Unable to start LDAP TLS: '.$this->ldapError($connection));
        }

        foreach ($this->serviceAccountUsernames() as $username) {
            if (@ldap_bind($connection, $username, (string) $this->configValue('password'))) {
                return $connection;
            }

            $this->lastError = $this->ldapError($connection);
        }

        throw new RuntimeException('Unable to bind LDAP service account: '.($this->lastError ?: 'Unknown LDAP error'));
    }

    /**
     * @param  resource|\LDAP\Connection  $connection
     * @return array<string, mixed>|null
     */
    private function findEntry($connection, $login)
    {
        $filter = $this->loginFilter($login);
        $result = @ldap_search(
            $connection,
            (string) $this->configValue('base_dn'),
            $filter,
            $this->profileAttributes()
        );

        if (! $result) {
            $this->lastError = $this->ldapError($connection);

            return null;
        }

        $entries = ldap_get_entries($connection, $result);

        if (! is_array($entries) || (int) $entries['count'] < 1) {
            return null;
        }

        return $entries[0];
    }

    /**
     * @param  resource|\LDAP\Connection  $connection
     * @return array<int, array<string, mixed>>
     */
    private function searchEntries($connection, $filter, $pageSize)
    {
        $entries = [];
        $baseDn = (string) $this->configValue('base_dn');
        $attributes = $this->profileAttributes();

        if (defined('LDAP_CONTROL_PAGEDRESULTS')) {
            $cookie = '';

            do {
                $controls = [[
                    'oid' => LDAP_CONTROL_PAGEDRESULTS,
                    'value' => [
                        'size' => $pageSize,
                        'cookie' => $cookie,
                    ],
                ]];
                $result = @ldap_search($connection, $baseDn, $filter, $attributes, 0, 0, 0, LDAP_DEREF_NEVER, $controls);

                if (! $result) {
                    $this->lastError = $this->ldapError($connection);
                    break;
                }

                $entries = array_merge($entries, $this->resultEntries($connection, $result));
                $responseControls = [];
                @ldap_parse_result($connection, $result, $errorCode, $matchedDn, $errorMessage, $referrals, $responseControls);
                $cookie = isset($responseControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'])
                    ? $responseControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie']
                    : '';
            } while ($cookie !== '');

            return $entries;
        }

        $result = @ldap_search($connection, $baseDn, $filter, $attributes, 0, $pageSize);

        if (! $result) {
            $this->lastError = $this->ldapError($connection);

            return [];
        }

        return $this->resultEntries($connection, $result);
    }

    /**
     * @param  resource|\LDAP\Connection  $connection
     * @param  resource|\LDAP\Result  $result
     * @return array<int, array<string, mixed>>
     */
    private function resultEntries($connection, $result)
    {
        $rawEntries = ldap_get_entries($connection, $result);
        $entries = [];

        if (! is_array($rawEntries)) {
            return $entries;
        }

        $count = (int) $rawEntries['count'];

        for ($index = 0; $index < $count; $index++) {
            if (isset($rawEntries[$index]) && is_array($rawEntries[$index])) {
                $entries[] = $rawEntries[$index];
            }
        }

        return $entries;
    }

    private function loginFilter($login)
    {
        $escapedLogin = $this->escape($login);
        $upnLogin = $this->userPrincipalNameFromLogin($login);
        $escapedUpnLogin = $upnLogin === null ? null : $this->escape($upnLogin);

        if (strpos($login, '@') !== false) {
            return "(|(userPrincipalName={$escapedLogin})(mail={$escapedLogin})(sAMAccountName={$escapedLogin}))";
        }

        return "(|(sAMAccountName={$escapedLogin})(employeeID={$escapedLogin})(employeeNumber={$escapedLogin})(cn={$escapedLogin})(userPrincipalName={$escapedLogin})"
            .($escapedUpnLogin ? "(userPrincipalName={$escapedUpnLogin})" : '')
            .')';
    }

    private function userListFilter($search)
    {
        $baseFilter = '(&(objectCategory=person)(objectClass=user)(!(objectClass=computer))';

        if (! $this->filled($search)) {
            return "{$baseFilter})";
        }

        $escapedSearch = $this->escape((string) $search);

        return "{$baseFilter}(|"
            ."(sAMAccountName=*{$escapedSearch}*)"
            ."(employeeID=*{$escapedSearch}*)"
            ."(employeeNumber=*{$escapedSearch}*)"
            ."(mail=*{$escapedSearch}*)"
            ."(userPrincipalName=*{$escapedSearch}*)"
            ."(cn=*{$escapedSearch}*)"
            ."(displayName=*{$escapedSearch}*)"
            .'))';
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @return array<string, string>|null
     */
    private function profileFromEntry($entry)
    {
        $dn = $this->firstAttribute($entry, 'dn');

        if ($entry === null || $dn === null) {
            return null;
        }

        $userPrincipalName = $this->firstAttribute($entry, 'userprincipalname') ?: '';

        return [
            'dn' => $dn,
            'samaccount_name' => $this->firstAttribute($entry, 'samaccountname') ?: '',
            'employee_id' => $this->firstAttribute($entry, 'employeeid') ?: '',
            'employee_number' => $this->firstAttribute($entry, 'employeenumber') ?: '',
            'mail' => $this->firstAttribute($entry, 'mail') ?: '',
            'user_principal_name' => $userPrincipalName,
            'user_principal_id' => $this->userPrincipalId($userPrincipalName),
            'cn' => $this->firstAttribute($entry, 'cn') ?: '',
            'display_name' => $this->firstAttribute($entry, 'displayname') ?: '',
            'given_name' => $this->firstAttribute($entry, 'givenname') ?: '',
            'surname' => $this->firstAttribute($entry, 'sn') ?: '',
            'department' => $this->firstAttribute($entry, 'department') ?: '',
            'title' => $this->firstAttribute($entry, 'title') ?: '',
            'company' => $this->firstAttribute($entry, 'company') ?: '',
            'telephone_number' => $this->firstAttribute($entry, 'telephonenumber') ?: '',
            'mobile' => $this->firstAttribute($entry, 'mobile') ?: '',
            'member_of_count' => (string) count($this->arrayAttribute($entry, 'memberof')),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function firstAttribute($entry, $key)
    {
        if ($entry === null || ! array_key_exists($key, $entry)) {
            return null;
        }

        $value = $entry[$key];

        if (is_array($value)) {
            $value = isset($value[0]) ? $value[0] : null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>|null  $entry
     * @return array<int, string>
     */
    private function arrayAttribute($entry, $key)
    {
        if ($entry === null || ! array_key_exists($key, $entry)) {
            return [];
        }

        $value = $entry[$key];

        if (! is_array($value)) {
            return is_string($value) && trim($value) !== '' ? [trim($value)] : [];
        }

        $items = [];

        foreach ($value as $itemKey => $item) {
            if (is_int($itemKey) && is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function serviceAccountUsernames()
    {
        $configuredUsername = (string) $this->configValue('username');
        $shortUsername = $this->shortUsername($configuredUsername);
        $domain = $this->domainFromBaseDn();
        $netbiosDomain = $this->netbiosDomainFromBaseDn();

        return $this->uniqueFilled([
            $configuredUsername,
            $shortUsername && $domain ? "{$shortUsername}@{$domain}" : null,
            $shortUsername && $netbiosDomain ? "{$netbiosDomain}\\{$shortUsername}" : null,
            $shortUsername,
        ]);
    }

    private function shortUsername($username)
    {
        if (preg_match('/CN=([^,]+)/i', $username, $matches)) {
            return $matches[1];
        }

        if (strpos($username, '\\') !== false) {
            $parts = explode('\\', $username);

            return end($parts) ?: null;
        }

        if (strpos($username, '@') !== false) {
            return strstr($username, '@', true) ?: null;
        }

        return $username !== '' ? $username : null;
    }

    private function domainFromBaseDn()
    {
        $parts = $this->baseDnDcParts();

        return $parts === [] ? null : strtolower(implode('.', $parts));
    }

    private function netbiosDomainFromBaseDn()
    {
        $parts = $this->baseDnDcParts();

        return isset($parts[0]) ? strtoupper($parts[0]) : null;
    }

    /**
     * @return array<int, string>
     */
    private function baseDnDcParts()
    {
        preg_match_all('/DC=([^,]+)/i', (string) $this->configValue('base_dn'), $matches);

        return isset($matches[1]) ? $matches[1] : [];
    }

    private function userPrincipalNameFromLogin($login)
    {
        if (strpos($login, '@') !== false) {
            return null;
        }

        $domain = $this->domainFromBaseDn();

        return $domain ? "{$login}@{$domain}" : null;
    }

    private function userPrincipalId($userPrincipalName)
    {
        if (strpos($userPrincipalName, '@') === false) {
            return $userPrincipalName;
        }

        return strstr($userPrincipalName, '@', true) ?: $userPrincipalName;
    }

    /**
     * @return array<int, string>
     */
    private function profileAttributes()
    {
        return isset($this->config['attributes']) && is_array($this->config['attributes'])
            ? $this->config['attributes']
            : [];
    }

    /**
     * @param  array<int, string|null>  $values
     * @return array<int, string>
     */
    private function uniqueFilled(array $values)
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            $key = strtolower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    private function escape($value)
    {
        return ldap_escape((string) $value, '', LDAP_ESCAPE_FILTER);
    }

    private function filled($value)
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function configValue($key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    /**
     * @param  resource|\LDAP\Connection  $connection
     */
    private function ldapError($connection)
    {
        return ldap_error($connection) ?: 'Unknown LDAP error';
    }
}
