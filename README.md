# Ideaserv Laravel LDAP Tools

LDAP login, lookup, list, CSV export, and employee ID maintenance tools for Laravel 5.6+.

This package uses native PHP `ext-ldap` instead of LdapRecord so it can be installed in older Laravel applications with fewer Composer conflicts.

## Requirements

- PHP `>=7.3`
- PHP LDAP extension: `ext-ldap`
- Laravel `>=5.6`

## Install

```bash
composer require shadow046/laravel-ldap-tools
```

For Laravel 5.6+ package auto-discovery should register the provider automatically. If needed, register it manually in `config/app.php`:

```php
'providers' => [
    Ideaserv\LdapTools\LdapToolsServiceProvider::class,
],
```

Publish config:

```bash
php artisan vendor:publish --tag=ldap-tools-config
```

Or use the guided installer:

```bash
php artisan ldap-tools:install
```

The installer publishes:

- `config/ldap-tools.php`
- optional sample login helper at `app/Support/LdapAuthenticatesUsers.php`

Installer options:

```bash
php artisan ldap-tools:install --force
php artisan ldap-tools:install --no-sample
```

## Environment

```env
LDAP_HOST=ucs.apsoft.com.ph
LDAP_PORT=636
LDAP_BASE_DN="DC=teamapsoft,DC=lab"
LDAP_USERNAME="tmsweb.id@teamapsoft.lab"
LDAP_PASSWORD="secret"
LDAP_SSL=true
LDAP_TLS=false
LDAP_TIMEOUT=5
LDAP_LOGGING=false
```

## Commands

Find one user:

```bash
php artisan ldap:find jolopez.id
php artisan ldap:find jolopez@ideaserv.com.ph
```

List users:

```bash
php artisan ldap:list-users --limit=50
php artisan ldap:list-users --search=jolopez
```

CSV output:

```bash
php artisan ldap:list-users --format=csv
php artisan ldap:list-users --limit=0 --csv=storage/app/ldap-users.csv
```

Dry-run employee ID update:

```bash
php artisan ldap:set-employee-id jolopez.id 639
```

Commit employee ID update:

```bash
php artisan ldap:set-employee-id jolopez.id 639 --commit
```

## Login Usage

Inject or resolve the service:

```php
use Ideaserv\LdapTools\Services\LdapService;

$ldap = app(LdapService::class);

$profile = $ldap->authenticate($login, $password);

if ($profile === null) {
    // Invalid LDAP login.
}
```

The recommended login identifier is the local part of `userPrincipalName`.

Example:

```text
userPrincipalName: jolopez.id@TEAMAPSOFT.LAB
login input:       jolopez.id
```

The returned profile includes:

```php
[
    'dn' => '...',
    'samaccount_name' => 'jolopez.id',
    'employee_id' => '',
    'employee_number' => '',
    'mail' => 'jolopez@ideaserv.com.ph',
    'user_principal_name' => 'jolopez.id@TEAMAPSOFT.LAB',
    'user_principal_id' => 'jolopez.id',
    'display_name' => 'Jerome Lopez',
]
```

For app identity, match local users in this order:

1. `employee_id`
2. `employee_number`
3. `mail`
4. `user_principal_id`
5. `samaccount_name`

## Sample Login Trait

After running:

```bash
php artisan ldap-tools:install
```

Inspect and adapt:

```text
app/Support/LdapAuthenticatesUsers.php
```

Example controller usage:

```php
use App\Support\LdapAuthenticatesUsers;

class LoginController extends Controller
{
    use LdapAuthenticatesUsers;

    public function login(Request $request)
    {
        $user = $this->authenticateWithLdap(
            $request->input('login'),
            $request->input('password')
        );

        if (! $user) {
            return back()->withErrors([
                'login' => 'Invalid LDAP credentials.',
            ]);
        }

        return redirect()->intended('/home');
    }
}
```
