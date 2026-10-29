# Security Policy

## Supported runtime

- PHP with the branch-specific PHP2Shell fix:
  - PHP 8.2: **8.2.35+**
  - PHP 8.3: **8.3.36+**
  - PHP 8.4: **8.4.27+**
  - PHP 8.5: **8.5.12+**
- MySQL / MariaDB 8.0+
- Current Composer dependencies pinned by `composer.lock`

PHP 8.1 and older are end-of-life and must not be deployed. A raw `>= 8.2.35` comparison is not sufficient: 8.3.35, 8.4.26 and 8.5.11 are still vulnerable.

## PHP2Shell (CVE-2026-109618 / CVE-2026-109619)

These are unauthenticated PHP RCE vulnerabilities fixed in PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12. The only complete fix is updating the PHP runtime on every server and restarting every worker.

Application-level code cannot patch them. See [PHP2Shell handling](docs/security/php2shell.md) for update commands, partial mitigations and compromise checks.

## Reporting a vulnerability

Do not open a public issue for an unfixed vulnerability. Send a private report with:

- affected URL or component
- reproduction steps
- affected version
- impact
- any suggested fix

## Handling PHP / dependency CVEs

1. Verify the CVE against [NVD](https://nvd.nist.gov/), [CVE.org](https://www.cve.org/) or the upstream PHP release notes. Confirm the affected versions and fixed version.
2. If it is a PHP core vulnerability, upgrade the server PHP runtime to the fixed security release. Application code cannot patch PHP itself.
3. If it is a Composer dependency vulnerability, update the affected package and commit the refreshed `composer.lock`.
4. Run:

   ```bash
   composer security
   composer audit --locked
   composer test
   npm test
   npm run build
   ```

5. Deploy with `APP_ENV=production`, `APP_DEBUG=false`, a random `APP_KEY`, HTTPS, and `expose_php=Off`.
6. Ensure the web server denies script execution under `public/uploads/`.

## Deployment security gate

`php bin/security-check.php` / `composer security` checks:

- minimum PHP version
- required extensions (`pdo_mysql`, `mbstring`, `json`, `fileinfo`, `session`, `openssl`; `gd` is required for image features)
- `APP_KEY` presence and length
- debug/error-display settings
- dangerous `allow_url_include`
- upload and POST size limits
- writable runtime directories
- sensitive files inside `public/`
- executable files in `public/uploads/`
- upload-directory `.htaccess` deny rules

A failed gate exits with a non-zero status and should block deployment.

