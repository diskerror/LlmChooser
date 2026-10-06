# Deploying LlmChooser to beelink

Verified on beelink, 2026-10-03: PHP 8.4.26 with `curl`, `intl`, `pcntl`,
`posix`; `/usr/bin/systemctl` present; user `llama` exists (uid 988, in
groups `video` and `render` — both needed for GPU access);
`/opt/llama.cpp-ngrams/build/bin/llama-server` exists.

Prerequisites (already satisfied):

    sudo apt install php8.4-cli php8.4-curl php8.4-intl
    # php8.4-cli on Debian 13 includes pcntl and posix.
    # intl is required by diskerror/typed; curl by the /v1 proxy.

Project location on beelink: decide once, e.g. /opt/llmchooser.
The `llama` user must be able to write to `storage/` there:

    sudo chown -R llama:llama /opt/llmchooser/storage

Run CLI commands as the llama user so file ownership stays consistent:

    sudo -u llama php /opt/llmchooser/bin/llmchooser health

`bin/llmchooser` is `#!/usr/bin/env php`; on beelink `php` is 8.4, so it can
be run directly. It refuses to start under PHP < 8.4.

Engine binary: config/engines/beelink-local.json points at
/opt/llama.cpp-ngrams/build/bin/llama-server. LlmChooser sets
LD_LIBRARY_PATH to that binary's directory automatically.

Ports: LlmChooser uses 5800–5900 for instances. llama-swap stays on
1234 until the Phase 7 cutover.

## Starting a preset at boot

    sudo php /opt/llmchooser/bin/llmchooser set-startup <preset-id>

This writes `/etc/systemd/system/llmchooser-<preset-id>.service`, runs
`daemon-reload` and `enable`. It needs root because it writes to `/etc`; the
command prints the exact `sudo` line to use when it is not root. Start it now
with `systemctl start llmchooser-<preset-id>`, undo with `remove-startup`.

The unit runs `start <id> --foreground`, so the service stays attached to the
instance. The instance is in the unit's cgroup, so `systemctl stop` reaches
the llama-server itself, not just the PHP wrapper. `Restart=on-failure` with
`RestartSec=10` brings back a crashed instance; `LimitMEMLOCK=infinity`
matches what llama.cpp needs for pinned memory.

Check a stuck start with the log file named by `health <preset-id>` — a failed
model load shows up there, not in the CLI's one-line summary.

## Web app + proxy service

Streaming requests hold a PHP worker for their whole duration, so give
the built-in server enough workers for the concurrent chats you expect.

`/etc/systemd/system/llmchooser-web.service`

    [Unit]
    Description=LlmChooser web UI and /v1 proxy
    After=network-online.target
    Wants=network-online.target

    [Service]
    Type=simple
    User=llama
    Group=llama
    WorkingDirectory=/opt/llmchooser
    Environment=PHP_CLI_SERVER_WORKERS=8
    ExecStart=/usr/bin/php -S 0.0.0.0:8090 -t public public/index.php
    Restart=on-failure
    RestartSec=5
    # Instances are started in their own session (setsid); KillMode=process
    # makes sure restarting this service does NOT stop running models.
    KillMode=process

    [Install]
    WantedBy=multi-user.target

Enable: `sudo systemctl daemon-reload && sudo systemctl enable --now llmchooser-web`

Later, nginx + php-fpm can replace `php -S` without code changes
(set `fastcgi_buffering off` for `/v1/` so streaming is not buffered).

## Cutover from llama-swap (manual)

1. Run LlmChooser web on :8090 next to llama-swap on :1234. Create
   presets matching the llama-swap models (command lines are in
   `/etc/llama-swap/config.yaml`). The qwen3.8-flash-next-q5-ngrams preset
   needs binary `/opt/llama.cpp-ngrams/build/bin/llama-server`, mmproj and
   draft (mtp) paths from the "shared" folder, `spec_type draft-mtp`,
   `spec_draft_n_max 2`, `parallel 1`, and extra_flags
   `--fit off --lazy-mode on --metrics`.
2. Start one preset from the dashboard; test it:
   `curl -N http://beelink:8090/v1/chat/completions?preset=ID ...`
3. Point ONE client (e.g. a Hermes test profile) at
   `http://beelink:8090/v1` and use it for a day.
4. When happy: `sudo systemctl disable --now llama-swap`, then either move
   LlmChooser to :1234 (change the ExecStart port) or repoint clients.
5. Rollback: `sudo systemctl enable --now llama-swap`.

Not handled yet: `ttl_seconds` (idle unload). llama-swap unloads idle
models; LlmChooser keeps them running until stopped. `last_used` is
recorded on every proxied request so an idle-stop job can be added.
