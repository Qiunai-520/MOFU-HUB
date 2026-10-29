# PHP2Shell: CVE-2026-109618 / CVE-2026-109619

**Severity:** CVSS v4.0 9.2, unauthenticated remote code execution.

**Affected:** PHP where the patch level is below the fixed release for its branch. PHP 5 and PHP 7 no longer receive official patches.

**Fixed releases:**

| Branch | Fixed version |
| --- | --- |
| PHP 8.5 | 8.5.12 |
| PHP 8.4 | 8.4.27 |
| PHP 8.3 | 8.3.36 |
| PHP 8.2 | 8.2.35 |

This repository now requires a patched PHP runtime. PHP 8.2.35 is the absolute floor; PHP 8.3.35, 8.4.26 and 8.5.11 are still vulnerable.

## Complete fix

Update PHP and restart every worker/process that serves requests. With PHP-FPM, restarting only Nginx/Apache is not enough; restart the FPM master too.

Typical Debian/Ubuntu package flow:

```bash
sudo apt update
# Install the patched packages supplied by your distribution.
sudo apt install --only-upgrade 'php8.2-*'
sudo systemctl restart php8.2-fpm
sudo systemctl restart apache2   # or nginx
php -v                           # branch floor must be met
```

Use the equivalent package manager, hosting-panel update, container image update, or source build for your deployment. For Docker, rebuild with a patched base image tag rather than reusing an old layer.

Apache mod_php is the highest-risk SAPI for the confirmed paths. PHP-FPM and other SAPIs are also in scope.

## If immediate update is impossible

These are **partial mitigations only**; they do not make the installation safe:

- Disable Session Upload Progress in server-level PHP configuration and restart all workers:

  ```ini
  ; php.ini
  session.upload_progress.enabled=0
  ```

  PHP-FPM pool example:

  ```ini
  php_admin_value[session.upload_progress.enabled] = 0
  ; Only if you accept the memory-exhaustion trade-off and have a cgroup/container limit:
  ; php_admin_value[memory_limit] = -1
  php_admin_value[post_max_size] = 68M
  php_admin_value[upload_max_filesize] = 64M
  ```

  Apache mod_php example (highest-risk SAPI for the confirmed paths):

  ```apache
  php_admin_value session.upload_progress.enabled 0
  # php_admin_value memory_limit -1
  php_admin_value post_max_size 68M
  php_admin_value upload_max_filesize 64M
  ```

  PHP recommends `php.ini`, an FPM pool, or Apache/server configuration. Do not rely on `ini_set()` or `.user.ini`.

- PHP also documents `memory_limit=-1` as a partial mitigation for the OOM path. This is dangerous without an external memory boundary: use a container/cgroup limit and understand that memory exhaustion becomes more likely. Do not substitute `RLIMIT_AS` or `RLIMIT_DATA`.

- Limit request bodies at the web server or proxy. The application needs larger limits for video uploads; if video upload is not required, use `post_max_size=8M`. If uploads are required, keep `upload_max_filesize` below `post_max_size`.

- Turn off diagnostic output before request initialization and remove invalid/deprecated per-request INI settings.

- On PHP 8.5 only, `fatal_error_backtraces=0` blocks one confirmed fatal-backtrace path.

- Restrict internet access to the PHP service until it can be patched if the risk is unacceptable.

## Verify the deployment

```bash
php -v
php -i | grep -E 'session.upload_progress.enabled|memory_limit|post_max_size'
php bin/security-check.php
composer audit --locked
```

`composer security` deliberately fails on vulnerable branches, including PHP 8.2.34, PHP 8.3.35, PHP 8.4.26 and PHP 8.5.11.

## Check for compromise

Preserve logs before changing anything. Look for unexpected web shells, new files under `public/uploads`, suspicious cron jobs, new system users, and unusual outbound connections from PHP workers.

References:

- [PHP security advisory, 21 Oct 2026](https://www.php.net/archive/2026.php#2026-10-21-5)
- [watchTowr PHP2Shell briefing](https://watchtowr.com/intelligence/php-remote-code-execution-php2shell/)
- [CVE-2026-109618](https://nvd.nist.gov/vuln/detail/CVE-2026-109618)
- [CVE-2026-109619](https://nvd.nist.gov/vuln/detail/CVE-2026-109619)
