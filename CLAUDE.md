# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Clippy is a single-file PHP script (`clippy.php`) that shares clipboard contents between a Linux PC and an Android phone over local WiFi. No app needed on the phone — just a browser.

## Running

### PHP (primary)

```bash
php clippy.php          # default port 18080
php clippy.php 3000     # custom port
```

### Docker

```bash
docker compose up --build              # default port 18080
PORT=3000 docker compose up --build    # custom port
```

For Wayland sessions, add the Wayland socket volume to `docker-compose.yml`:
```yaml
volumes:
  - ${XDG_RUNTIME_DIR}:${XDG_RUNTIME_DIR}:ro
```

Override LAN IP detection with `HOST_IP=x.x.x.x` (works for both native and Docker).

## Dependencies

PHP 8.5+ with `proc_open` enabled, plus `wl-clipboard` (Wayland) or `xclip` (X11). Docker handles all dependencies automatically.

## Architecture

### PHP (`clippy.php`)

Everything lives in `clippy.php` — a single PHP file that acts as both the CLI launcher and the request router for PHP's built-in web server.

**Execution flow:** When run from CLI (`php_sapi_name() === 'cli'`), the script checks dependencies, prints a startup banner, sets `CLIPPY_SERVER_URL` env var, and launches `php -S 0.0.0.0:$port clippy.php`. Each incoming request hits the same file under the `cli-server` SAPI, which routes to `handleRequest()`.

**Key sections in the file:**
- **Clipboard helpers** (`getClipboard()`/`setClipboard()`) — auto-detect Wayland vs X11, uses `proc_open` for piping to clipboard commands. On Wayland, `getClipboard()` reads from a cache file kept fresh by a background `wl-paste --watch` process (started at launch), avoiding repeated subprocess spawning on every poll. On X11, `getClipboard()` calls `xclip` directly.
- **HTML template** — `HTML_TEMPLATE` constant (heredoc) with inline CSS/JS, uses `__SERVER_URL__` placeholder replaced at serve time. All sizing uses `rem` (not `px`) except 1px borders. CSS custom properties (`--bg`, `--fg`, `--accent`, etc.) in `:root` define the dark (AMOLED black) palette; `body.light` overrides with the light palette. A tiny inline `<script>` before content reads the `clippy-theme` cookie (or falls back to screen-width detection via `matchMedia`) and adds the `light` class to prevent flash.
- **Request handler** (`handleRequest()`) — routes based on URI, uses native `json_encode`/`json_decode`
- **Main entry** — dependency check, IP detection, env setup, starts clipboard watcher (Wayland), launches PHP built-in server. The watcher is cleaned up via `register_shutdown_function` on exit.

### Docker

- `Dockerfile` — PHP 8.5 CLI image with `xclip` and `wl-clipboard` installed
- `docker-compose.yml` — uses `network_mode: host` for direct LAN access and clipboard socket pass-through

**API routes:**
- `GET /` — serves HTML with `__SERVER_URL__` replaced
- `GET /api/clipboard` — returns PC clipboard as JSON
- `POST /api/clipboard` — sets PC clipboard from JSON body

**Frontend:** Single-page app loaded from CDN `qrcodejs` for QR codes. Polls `GET /api/clipboard` every 2s (server-side reads are cheap file reads on Wayland, not subprocess spawns). URLs in clipboard are auto-detected and rendered as clickable links with a scannable QR code. Copy-to-device uses Clipboard API with `execCommand('copy')` fallback for non-secure contexts. QR codes render black-on-white with a white quiet zone (`background:#fff;padding:.5rem`) for reliable scanning on any theme. The Connect card and QR toggle button are hidden on mobile via `@media(max-width:47.99rem)`. Theme preference is stored in a `clippy-theme` cookie (1-year expiry); default is light on screens >= 48rem, dark (AMOLED) on smaller screens.

**Environment variables (internal):**
- `CLIPPY_SERVER_URL` — set by CLI entry, used by the server to inject the LAN URL into the HTML template
- `CLIPPY_WATCHER` — set when the `wl-paste --watch` background process is running; tells `getClipboard()` to read from cache file instead of spawning a subprocess
