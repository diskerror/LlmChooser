# Installing LlmChooser (Debian / Ubuntu)

LlmChooser is a single front-controller PHP app (`public/index.php`). This
covers two ways to run it on a home LAN:

- **nginx + php-fpm** — recommended, survives reboots/crashes, real concurrency.
- **`php -S`** — quick/dev-only, see [Limitations](#php--s-limitations) below.

Build/toolchain requirements:
- PHP **>= 8.4** with the `php-fpm`, `php-cli`, and the extensions your
  `composer.json` deps need (check `diskerror/typed` for anything beyond
  core — it has no extra extension requirements as of this writing).
- Composer, to install vendor deps (not vendored in the repo).

## 1. Common setup (both methods)

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-fpm curl unzip git

# Composer (if not already installed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Get the code
sudo git clone <your-repo-url> /opt/llmchooser
cd /opt/llmchooser
composer install --no-dev --optimize-autoloader

# Writable storage dir (presets, runtime state)
sudo chown -R www-data:www-data storage/
```

> `diskerror/typed` is pulled as a local path repo in `composer.json`
> (`../Typed`). On a deploy box, either vendor a real version-pinned
> release of `diskerror/typed` via Packagist/VCS, or clone it as a sibling
> directory to `LlmChooser` before `composer install`. Don't commit your
> local absolute paths into `composer.json` — keep machine-specific paths
> out of version control.

Check Ubuntu's default PHP version first — Ubuntu 24.04 ships PHP 8.3, not
8.4. Use the `ppa:ondrej/php` PPA if your distro's PHP is older than 8.4:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli
```

## 2. Option A — nginx + php-fpm (recommended)

```bash
sudo apt install -y nginx-light
```

**php-fpm pool** — edit `/etc/php/8.4/fpm/pool.d/www.conf` (or a dedicated
pool file), key settings for a single-user LAN tool:

```ini
pm = ondemand
pm.max_children = 5
```

**nginx site** — `/etc/nginx/sites-available/llmchooser`:

```nginx
server {
    listen 1234;
    server_name _;

    root /opt/llmchooser/public;
    index index.php;

    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # /v1/* is a streaming proxy to a local LLM server — don't let
        # nginx or fpm time it out on long generations.
        fastcgi_read_timeout 3600s;
    }

    location ~ ^/v1/ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_read_timeout 3600s;
        proxy_buffering off;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/llmchooser /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now php8.4-fpm nginx
```

Visit `http://<host-ip>:1234`. Both services auto-restart on crash and
auto-start on boot via systemd — no further supervision needed.

## 3. Option B — `php -S` (quick/dev only)

```bash
cd /opt/llmchooser
php -S 0.0.0.0:1234 -t public public/index.php
```

Run it under a user unit so it survives logout/reboot:

`/etc/systemd/system/llmchooser-dev.service`:

```ini
[Unit]
Description=LlmChooser (php -S dev server)
After=network.target

[Service]
User=www-data
WorkingDirectory=/opt/llmchooser
ExecStart=/usr/bin/php -S 0.0.0.0:1234 -t public public/index.php
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now llmchooser-dev
```

### `php -S` limitations

- **No auth, no TLS.** Anyone on the LAN (or anything that reaches that
  port — other VLANs, a compromised IoT device) gets full plaintext access.
- **Single-threaded.** One request at a time; a long `/v1/*` chat-proxy
  stream will block every other request/tab until it finishes.
- **No `.htaccess`-equivalent deny rules.** Routing relies entirely on the
  `cli-server` check in `index.php` — fine here since it only passes
  through existing static files, but there's no independent safety net.
- **Minimal logging**, no rotation — stdout only, lost if the service log
  isn't captured (journald captures it under systemd, which is why the
  unit file above is worth using even for "quick" mode).
- Even with the systemd unit for auto-restart/auto-start, it's still
  single-threaded and unencrypted — treat Option B as "good enough for me,
  alone, on my own LAN," not as a stepping stone to anything multi-user or
  internet-facing.

## 4. Verify

```bash
curl -s http://localhost:1234/ | head -20
```

Should return the LlmChooser HTML page. Check `storage/runtime_state.json`
is writable and `journalctl -u php8.4-fpm` / `-u nginx` / `-u llmchooser-dev`
for errors.
