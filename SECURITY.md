# Security

Single-admin file sharing application. Public links to files (`/s/{id}`) and folders (`/f/{id}`) are **intentionally public**: knowing the link means access to the content.

## Implemented mitigations

| Measure | Where |
|---|---|
| Built-in bootstrap password (`DemoPassword`) or optional configured hash; bcrypt password hashing | `config.php`, `auth.php` |
| Session: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode | `auth.php` |
| Idle 14d / absolute 30d timeout, session regenerate | `auth.php` |
| Login rate-limit (5 fails / 30 min → block 30 min) | `auth.php` + SQLite |
| CSRF tokens + same-host Origin/Referer on POST | `auth.php` |
| Prepared statements | `api.php`, `db.php` |
| Upload whitelist + finfo MIME check | `api.php` |
| Random 5-character share IDs and path containment | `api.php` |
| No PHP execution under `/s/` | `s/.htaccess` |
| Security headers (CSP, nosniff, Referrer, Frame, HSTS) | `security_headers.php`, `.htaccess` |
| `data/` and `config.php` denied from web | `.htaccess`, `nginx-share-routes.conf` |
| Audit log (login, upload, delete, ...) | `audit_log` table |
| `.env`, `config.php`, SQLite, and uploads excluded from Git | `.gitignore` |

## Post-install / post-deploy checklist

1. Before public deployment, either create `config.php` from `config.example.php` with a unique bootstrap hash, or sign in with `DemoPassword` and change it immediately through the UI. Never commit or expose `config.php`.
2. **HTTPS** enabled (`.htaccess` already redirects, honoring `X-Forwarded-Proto`).
3. For nginx, add the `data/` and `config.php` denial rules from `nginx-share-routes.conf` to the server block.
4. PHP `display_errors=Off` in production.
5. If the site is behind a reverse proxy that isn't localhost, define `TRUSTED_PROXIES` with the proxy IP addresses before serving requests; otherwise `X-Forwarded-Proto` is not trusted.
6. Permissions: `data/` and `s/` are writable by PHP, not world-writable unnecessarily.
7. Back up the SQLite database and `s/` uploads using your hosting provider or an access-controlled backup process.

## File uploads

Allowed extensions: `pdf`, `doc`, `docx`, `odp`, `pptm`, `jpg`, `jpeg`, `png`, `zip`, `mp4`, `mov`.

Limit: 100 MB. Extension is normalized; MIME is checked via `finfo` when available.

HTML/PHP/SVG/JS **cannot** be uploaded through the API. `/s/` additionally has the PHP engine disabled.

## Public links

- Files: `/s/{id}/` (share page) and `/s/{id}.{ext}` (direct file).
- Folders: `/f/{shareId}`.
- Links use 5 random characters. Do not rely on link secrecy as the only protection for confidential content.

Don't upload confidential documents without a separate access policy.

## Deploy

- `deploy.sh pull|push` — full sync (code + `data/` + `s/`), **excludes** `.env`, `USELESS/`, `tests/`, and `backups/`.
- `pull` stops if local files are newer than the server's (`pull --force` to override).
- `FTP_SSL=1` in `.env` enables FTPS (if the host supports it).
- `php -l` runs against root-level `*.php` before push.

## Monitoring

- `audit_log` table (action, target_id, ip, created_at)
- Web server access/error logs (login brute-force attempts)
- Unusual upload/delete activity in the audit log

## Known limitations

1. No at-rest file encryption.
2. Single admin account (no RBAC).
3. "Link = access" model for public files/folders.
4. PDF compression via Ghostscript runs after the response (shutdown hook) and requires `gs` in `PATH`.

## Reporting a vulnerability

Contact the host administrator through a private channel; do not publish a PoC with working credentials.
