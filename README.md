# Simple File Sharing

A small, self-hosted file-sharing application for one administrator. Upload files, organise them into categories, and send public file or folder links.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4)

![Demo Screenshot](demo-screenshot.png)

Live demo: [filesharing-demo.timur.ee](https://filesharing-demo.timur.ee)

## Features

- 📤 **Upload files** — drag and drop or choose files; up to 100 MB per file
- 🔗 **Public file links** — copy a stable share-page link for each file
- 📁 **Categories and subcategories** — create, rename, delete, and organise files; one nesting level is supported
- 🗂️ **Public folder links** — share a category (including its direct subcategories) and revoke its link later
- 🔍 **Search and sort** — search by file name or share ID; sort by upload date or file size
- ✏️ **Rename and replace** — rename a file or replace its contents while keeping its share-page URL
- 🗑️ **Delete with undo** — a deletion is committed after a four-second undo window
- 🔐 **Password-protected administration** — one admin account, password changes in the UI, session timeouts, CSRF protection, and login rate limiting
- 📄 **File preview pages** — share pages render the linked file in an iframe when the browser supports it
- 🗜️ **Optional PDF compression** — PDFs up to 40 MB are compressed after upload or replacement when Ghostscript is installed
- 🎨 **Dark mode and responsive UI** — follows the system colour preference and supports modern desktop and mobile browsers

Allowed upload types: PDF, DOC, DOCX, ODP, PPTM, JPG/JPEG, PNG, ZIP, MP4, and MOV. The server checks both the extension and MIME type when `finfo` is available.

## Installation

### 1. Upload the project

Upload all project files to the web root. Apache with `mod_rewrite` is the primary supported setup. A route/configuration reference for nginx is included in `nginx-share-routes.conf`.

### 2. Make runtime directories writable

PHP must be able to write to `data/`, `data/sessions/`, and `s/`. Ownership should normally be assigned to the web-server user; do not make them world-writable unless the host requires it.

```bash
chmod 755 data/ data/sessions/ s/
```

### 3. Sign in and change the password

On a fresh installation, sign in with:

```text
DemoPassword
```

Then immediately use **Change password** in the application. The new password must contain at least 12 characters and invalidates all existing admin sessions.

For a custom password before the first request, copy `config.example.php` to `config.php`, generate a bcrypt hash, and set `FILESHARE_ADMIN_PASSWORD_HASH`. `config.php` is ignored by Git and must not be publicly accessible.

```bash
php -r "echo password_hash('your-password-here', PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;"
```

The bootstrap hash is used only to create the first admin record in SQLite. Afterwards, use the UI to change the password.

### 4. Enable HTTPS

The supplied Apache rules redirect HTTP to HTTPS. If TLS terminates at a reverse proxy, set `TRUSTED_PROXIES` in `config.php` to its comma-separated IP addresses so PHP can trust `X-Forwarded-Proto`.

### 5. Open the application

Open the site root and sign in. The admin UI is at `index.php`; unauthenticated visitors are redirected to `login.php`.

## Architecture

```text
Admin browser
  index.php + app.js
        │ authenticated JSON requests with CSRF tokens
        ▼
  api.php ── auth.php ── security_headers.php
        │
        ├── data/database.sqlite  (files, categories, shares, auth, rate limits, audit log)
        ├── data/sessions/        (PHP session files)
        └── s/                    (uploaded files and generated file share pages)

Public visitors
  /s/{file-id}/      generated file share page
  /s/{file-id}.{ext} direct uploaded file
  /f/{folder-id}     dynamic public folder page
```

All public links are intentionally accessible to anyone who knows the URL. They are not a substitute for per-recipient permissions or encrypted storage.

## File structure

```text
/
├── index.php                 # Authenticated admin application
├── login.php, logout.php     # Admin sign-in and sign-out
├── change-password.php       # Password-change UI
├── api.php                   # Authenticated JSON API and uploads
├── auth.php                  # Sessions, password authentication, CSRF, rate limiting
├── db.php                    # SQLite connection, schema migrations, audit log
├── file.php, folder.php      # Public share-page fallbacks
├── share_pages.php           # Generated /s/{id}/index.html share pages
├── security_headers.php      # CSP and other response security headers
├── app.js, style.css         # Admin interface
├── .htaccess                 # Apache routes, HTTPS redirect, access controls
├── nginx-share-routes.conf   # nginx route and hardening reference
├── config.example.php        # Optional custom initial password hash
├── data/
│   ├── database.sqlite       # Created automatically; SQLite application data
│   └── sessions/             # Created automatically; PHP sessions
├── s/
│   ├── {id}.{ext}            # Public uploaded file
│   └── {id}/index.html       # Generated public file share page
├── icons/                    # UI icons
├── demo/                     # Static interactive demo
└── deploy.sh                 # FTP/FTPS pull and push helper
```

## Usage

1. Sign in, then upload by dragging files onto the drop zone or choosing **Upload document**.
2. Select a category before uploading, or change a file's category afterwards.
3. Use the copy button to share `https://your-domain/s/{file-id}/`.
4. In a category menu, choose **Copy link** to create `https://your-domain/f/{folder-id}`. Use **Revoke link** to disable it.
5. Rename or replace a file as needed. The `/s/{file-id}/` URL remains stable; a direct URL containing the extension can change if the replacement has another extension.

## Configuration

### Upload limits

The application enforces a 100 MB limit in `api.php`:

```php
define('MAX_FILE_SIZE', 100 * 1024 * 1024);
```

Your PHP configuration must also permit the desired size. Set `upload_max_filesize` and `post_max_size` in `php.ini`, your hosting panel, or the server's PHP-FPM configuration; not every Apache host permits `php_value` in `.htaccess`.

### Categories

`All files` is created automatically and cannot be renamed or deleted. Other categories can have one level of subcategories. Deleting a category moves its files, and the files of its direct subcategories, to `All files`.

### Optional PDF compression

If the `gs` executable is available and PHP may execute it, the application attempts to reduce PDFs up to 40 MB after responding to the upload request. It keeps the original when compression does not make the file smaller.

## Requirements

- PHP 8.1 or newer, with `pdo_sqlite`, `mbstring`, and session support
- Apache with `mod_rewrite` and `mod_headers`, or an equivalent nginx setup
- Write access for `data/`, `data/sessions/`, and `s/`
- HTTPS certificate for production
- Optional: Ghostscript (`gs`) for PDF compression

## Security

- One administrator account; public file and folder links intentionally bypass authentication.
- Password hashes use bcrypt. After five failed logins from an IP within 30 minutes, login is blocked for 30 minutes; a warning is shown from the third failure.
- Admin sessions use HttpOnly, SameSite=Lax cookies, rotate periodically, expire after 14 days of inactivity or 30 days in total, and are invalidated after a password change.
- State-changing requests require CSRF tokens and a same-host Origin or Referer.
- Uploads are type-restricted and PHP execution is disabled under `s/` in the Apache configuration.
- The SQLite audit log records authentication and file-management events.

See [SECURITY.md](SECURITY.md) for deployment checks, server-hardening details, and limitations.

## Demo

`demo/index.html` is a static, no-login preview. It stores changes only in the visitor's browser; it does not upload files to a server. See [demo/README.md](demo/README.md).

## Troubleshooting

### Upload fails

- Confirm that `s/` and `data/` are writable by PHP.
- Ensure PHP's `upload_max_filesize` and `post_max_size` are at least as large as the application limit.
- Verify the file is one of the allowed types and inspect the PHP/web-server error log.

### Cannot sign in

- On a new installation, use `DemoPassword`, unless a custom valid bootstrap hash was set in `config.php` or the environment.
- If a password was already changed, use that password; the bootstrap value is no longer consulted.
- Wait 30 minutes if the IP has reached the five-attempt rate limit, and confirm that `data/` is writable.

### Public link gives 404

- Confirm `.htaccess` is present and `mod_rewrite` is enabled, or configure the equivalent nginx routes.
- Check that both the database entry and the uploaded file in `s/` exist.

## License

MIT License — free for personal and commercial use.
