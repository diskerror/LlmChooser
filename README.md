# LlmChooser

A small web app that manages [llama.cpp](https://github.com/ggml-org/llama.cpp)
`llama-server` instances: pick a model, tune its settings, start and stop it,
and see what it costs in memory before you load it.

> **Status: beta.** LlmChooser is in daily use but still changing: settings
> formats, the memory estimator's calibration and some features may change
> without notice. There is no authentication or TLS; run it on a trusted LAN
> only.

## Motivation

- **See the memory a model really needs.** Weights, KV cache and the rest add
  up to more than the file size, and loading a model that doesn't fit is a slow
  way to find out.
- **Separate presets for the same model.** One model file, several presets
  (for example creative vs. precise sampling, or different context sizes),
  each with its own settings.
- **N-gram drafts.** Presets and engines that use speculative drafting with
  n-gram tables and MTP draft models needed first-class handling.
- **Clearer help.** Each setting has a short hint, with full help one click
  away.
- **A GUI for settings.** llama-server has a long list of flags; a form beats
  a shell history.
- **Hands-on understanding.** Seeing every setting, its default and what it
  does is a good way to learn what the knobs actually change.

## What you get

- Manages `llama-server`: start, stop and restart presets, with a dashboard
  showing what is loaded, what is available and how much memory is in use.
- A choice of help detail: short hints by default, full help on request.
- Two layers of help for every setting: llama.cpp's own description, read from
  your `llama-server` binary (so it always matches the build you run), then
  our notes beneath it. Reload it from the engine form; `llmchooser help-review`
  lists settings whose llama.cpp wording has changed since our notes were last
  checked. llama.cpp's text is used under its MIT license (see `NOTICE.md`).
- A live estimate of the memory a preset needs, updated as you edit its
  settings.
- A web-based GUI.
- Runs on PHP's built-in server (no web server needed), or behind nginx +
  php-fpm.
- PHP 8.2 or newer. Unit tests are run on PHP 8.2 through 8.5; the developer
  uses it daily on Debian 13 with PHP 8.4.

You also need a built `llama-server` (llama.cpp) on the machine that will run
the models.

Runs on Linux and on Apple Silicon macOS (see [macOS](#macos)).

## Installing (Debian / Ubuntu)

Requirements, all from the distribution's own packages (no extra package
sources needed):
- PHP 8.2 or newer, CLI, with the `curl` and `intl` extensions (the
  distribution's default PHP is fine: Debian 12 ships 8.2, Debian 13 8.4,
  Ubuntu 24.04 8.3, Ubuntu 26.04 8.5; Ubuntu 22.04's 8.1 is too old).
  `pcntl` and `posix` come with `php-cli`.
- Composer, to install the PHP dependencies.
- A built `llama-server` (llama.cpp).
- Option B only: nginx and php-fpm.

Two ways to serve it:
- **Option A — PHP's built-in server.** One systemd service, no web server.
  Suited to a home lab: a handful of users, for example a family each using
  their own model, or several sharing one, including streaming chats from
  agents (main chat, summarizer and embedder at once).
- **Option B — nginx + php-fpm**, if you already run nginx.

Neither has authentication or TLS; keep it on a trusted LAN (or put a reverse
proxy with TLS/auth in front).

## 1. Common setup

```bash
sudo apt install php-cli php-curl php-intl composer git
sudo git clone https://github.com/diskerror/LlmChooser.git /opt/llmchooser
sudo chown -R "$USER" /opt/llmchooser
cd /opt/llmchooser
composer install --no-dev --optimize-autoloader
```

> Composer fetches both libraries: `diskerror/typed` from Packagist and
> `diskerror/p_lib` from GitHub (it is not on Packagist), so `git` must be
> installed.
>
> Working on p_lib too? Clone it next to LlmChooser, save a copy of
> `composer.json` as `composer.local.json` with a `path` repository for
> `../p_lib` (git ignores it), and install with
> `COMPOSER=composer.local.json composer install`. Composer then symlinks
> your checkout into `vendor/`.

LlmChooser runs as any user and from any directory, including your home
directory. Then either run the installer:

```bash
sudo ./install.sh                  # Option A as a system service, user "llama"
./install.sh --mode user           # Option A as your own user service, no sudo
sudo ./install.sh --mode nginx     # Option B
./install.sh --help                # --user, --port, --workers, --dry-run
```

or copy the files from `deploy/` by hand as below.

The installer checks requirements (and prints the `apt install` line for
anything missing; it never installs packages), creates the service user if
needed and adds it to `video` and `render` for GPU access, gives it
`storage/` and a `~/.cache` (for the GPU shader cache), and installs the
service files with your settings.

`storage/` holds presets, runtime state and the model logs (which can include
prompts), so it is private to the service user: directories `750`, files
`640`. The installer sets this, and LlmChooser creates new files the same way
(umask `027`). By hand:

```bash
sudo chown -R llama: storage
find storage -type d -exec chmod 750 {} + ; find storage -type f -exec chmod 640 {} +
```

Run CLI commands as the service user (`sudo -u llama php bin/llmchooser …`),
not as root, or files they create in `storage/` will belong to root. It does not overwrite an existing file that
differs: it writes the new one next to it as `*.new`.

Models are started as the service user, so it needs read access to your
model directories and to the `llama-server` binary.

## 2. Option A — PHP's built-in server

[`deploy/llmchooser.service`](deploy/llmchooser.service) →
`/etc/systemd/system/llmchooser.service`:

```ini
[Unit]
Description=LlmChooser web UI and /v1 proxy (php -S)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=llama
Group=llama
WorkingDirectory=/opt/llmchooser
Environment=PHP_CLI_SERVER_WORKERS=8
ExecStart=/usr/bin/php -S 0.0.0.0:1234 -t public public/index.php
Restart=on-failure
RestartSec=5
LimitMEMLOCK=infinity
KillMode=process

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now llmchooser
```

- **Workers.** `PHP_CLI_SERVER_WORKERS` is the number of requests served at
  once. A streaming chat holds a worker for its whole length; the dashboard
  needs one or two more. Without it, PHP's built-in server handles one
  request at a time. Measured with 8 workers: 6 simultaneous 3-second streams
  finished in 3.3 s while the dashboard still answered in 55 ms; with 1
  worker, 3 streams took 9 s and the dashboard waited for them. A request
  beyond the worker count waits for a free one. Linux and macOS only.
- **`KillMode=process`** keeps running models alive when you restart
  LlmChooser.
- Logs: `journalctl -u llmchooser`.

**Per-user service (home directory, no sudo).**
[`deploy/llmchooser-user.service`](deploy/llmchooser-user.service) →
`~/.config/systemd/user/llmchooser.service` (set `WorkingDirectory` to your
copy), or `./install.sh --mode user`:

```bash
systemctl --user daemon-reload && systemctl --user enable --now llmchooser
loginctl enable-linger      # keep running after logout; start at boot
```

Your user needs to be in `video` and `render` for GPU access
(`sudo usermod -aG video,render $USER`, then log in again). Logs:
`journalctl --user -u llmchooser`.

## macOS

Apple Silicon only (unified memory, Metal). Requirements: PHP 8.2+ with
`curl` and `intl` (MacPorts `php84` + `php84-curl` + `php84-intl`, or
Homebrew `php`), Composer, and `llama-server` (MacPorts/Homebrew `llama.cpp`,
or your own build).

Run it as a per-user launchd agent. In the project directory, after
`composer install --no-dev`:

```bash
./install.sh                       # --port N, --workers N, --php PATH, --dry-run
```

It checks PHP and its extensions (printing the MacPorts/Homebrew command if
something is missing), warns if the port is taken, writes
`~/Library/LaunchAgents/com.llmchooser.plist` from
[`deploy/com.llmchooser.plist`](deploy/com.llmchooser.plist) (an existing,
differing plist is left alone; the new one goes next to it as `.new`) and
loads it. By hand: copy the template, set the PHP path, `WorkingDirectory`,
port and log paths, then
`launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.llmchooser.plist`.
Stop it with `launchctl bootout gui/$(id -u)/com.llmchooser`.

- **`AbandonProcessGroup`** keeps running models alive when LlmChooser stops
  or reloads (macOS has no `setsid`; started from a terminal with `php -S`,
  Ctrl-C would stop the models too).
- **Port 1234** is LM Studio's default as well; stop its server first.
- **Memory.** Total RAM from `sysctl hw.memsize`; free memory from `vm_stat`
  (free + inactive + speculative + purgeable); the GPU can use at most
  `iogpu.wired_limit_mb` if set, else 75% of RAM (macOS's own default is a
  little higher — 24.96 GiB of 32 GiB measured); GPU memory in use from
  `ioreg`. With mmap, Metal uses the mapped model file directly (no copy), so
  loaded weights appear as cached files in Activity Monitor, not app memory.

## 3. Option B — nginx + php-fpm

```bash
sudo apt install nginx-light php-fpm
```

Copy, adjusting user, paths, port and PHP version (or use
`install.sh --mode nginx`):
- [`deploy/php-fpm-llmchooser.conf`](deploy/php-fpm-llmchooser.conf) →
  `/etc/php/8.4/fpm/pool.d/llmchooser.conf` — a pool running as the service
  user; `pm.max_children` plays the role of the worker count.
- [`deploy/php-fpm-killmode.conf`](deploy/php-fpm-killmode.conf) →
  `/etc/systemd/system/php8.4-fpm.service.d/llmchooser.conf` — otherwise
  restarting php-fpm stops every model started from the web UI.
- [`deploy/nginx-llmchooser.conf`](deploy/nginx-llmchooser.conf) →
  `/etc/nginx/sites-available/llmchooser` — unbuffered, with a 1-hour read
  timeout for long streamed answers.

nginx runs as `www-data` and serves `public/` directly, so it must be able
to read it: an install in a private home directory needs Option A.

```bash
sudo ln -s /etc/nginx/sites-available/llmchooser /etc/nginx/sites-enabled/
sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm
sudo nginx -t && sudo systemctl reload nginx
```

## 4. Verify

```bash
curl -s http://localhost:1234/ | head -5
sudo -u llama php /opt/llmchooser/bin/llmchooser health
```

The first should print the start of the dashboard's HTML. On errors, check
`journalctl -u llmchooser` (Option A) or `-u php8.4-fpm` / `-u nginx`
(Option B).
