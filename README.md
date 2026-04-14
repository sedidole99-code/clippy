# Clippy

Clipboard sharing between a Linux PC and an Android phone over local WiFi. Single PHP script, no phone app needed.

## How it works

1. Run `php clippy.php` on your PC
2. Open the printed URL in your browser — a QR code is shown
3. Scan the QR code with your phone camera
4. Both devices now see the same web interface

**PC to phone:** Copy something on your PC — it appears on the phone automatically (2s polling). On Wayland, a background `wl-paste --watch` process detects clipboard changes instantly with no repeated subprocess spawning. URLs become clickable links with a scannable QR code.

**Phone to PC:** Paste text into the "Send to PC" box and tap send — it lands in your PC clipboard.

## UI

- **Dark mode (AMOLED black)** by default on mobile, **light mode** by default on tablet/desktop (>= 768px). Toggle at the bottom of the page; preference saved as a cookie.
- **QR codes** use black-on-white with a white quiet zone for reliable scanning on any background.
- **Responsive** — all sizing uses `rem` units. QR code section and toggle are hidden on mobile (phone doesn't need to scan itself).
- **Toggle QR code** button on PC to show/hide the Connect section.

## Requirements

PHP 8.5+ with `proc_open` enabled, plus clipboard tools:

```bash
sudo apt install wl-clipboard   # Wayland
sudo apt install xclip          # X11
```

## Usage

```bash
php clippy.php          # serves on port 18080
php clippy.php 3000     # custom port
```

Override auto-detected LAN IP:

```bash
HOST_IP=192.168.1.50 php clippy.php
```

### Docker

```bash
./start                # build and start in background (port 18080)
PORT=3000 ./start      # custom port
./stop                 # stop and remove the container
```

## Limitations

- Text-only clipboard (no images/files)
- No authentication — anyone on your WiFi can access it
- QR code library loaded from CDN (requires internet on first load)
