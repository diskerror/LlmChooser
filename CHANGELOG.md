# Changelog

Branches: features land on `dev`; bug fixes go to `master` and are merged into `dev`.

## 0.9.0 — 2026-11-26

First tagged release. Web dashboard + CLI for running llama.cpp `llama-server`
presets on one host, with an OpenAI-compatible `/v1` proxy.

- Presets and engines as JSON, generated preset form with llama-server help.
- Memory estimates per device (weights, KV, recurrent, draft, mmproj), split
  into resident vs memory-mapped bytes; admission check against free memory;
  uncached read-per-token hint for streamed MoE experts.
- Start/stop/health, n-gram speculative decoding support, load modes.
- Untracked llama-server processes: Adopt (by `--alias`) or Kill.
- Linux (systemd: php -S system/user, nginx + php-fpm) and Apple Silicon
  macOS (launchd) via `install.sh`.
- PHP 8.2+.
