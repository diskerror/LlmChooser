# Deploying LLMchooser to beelink

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
/opt/llama.cpp-ngrams/build/bin/llama-server. LLMchooser sets
LD_LIBRARY_PATH to that binary's directory automatically.

Ports: LLMchooser uses 5800–5900 for instances. llama-swap stays on
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
