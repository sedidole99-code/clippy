<?php

declare(strict_types=1);

// Clippy - Clipboard sharing between PC and Android phone over WiFi.
//
// Dependencies: PHP 8.5+, wl-clipboard (Wayland) or xclip (X11)
//
// Usage: php clippy.php [PORT]
//        PORT=3000 php clippy.php

// --- Defaults ---

$DEFAULT_PORT = 18080;
$MAX_CLIPBOARD_SIZE = 256 * 1024; // 256 KB
$POLL_INTERVAL_MS = 2000;
$CACHE_FILE = sys_get_temp_dir() . '/clippy_clipboard_cache';

// Clipboard tools — Wayland
$CMD_WL_COPY = 'wl-copy';
$CMD_WL_PASTE = 'wl-paste';
$PKG_WL_CLIPBOARD = 'wl-clipboard';

// Clipboard tools — X11
$CMD_XCLIP = 'xclip';
$PKG_XCLIP = 'xclip';

// --- Clipboard ---

function getClipboard(): string
{
    // Wayland: read from cache file kept fresh by background wl-paste --watch
    $cacheFile = $GLOBALS['CACHE_FILE'];
    if (getenv('CLIPPY_WATCHER') && file_exists($cacheFile)) {
        return file_get_contents($cacheFile);
    }

    // X11: read directly
    return (string) shell_exec($GLOBALS['CMD_XCLIP'] . ' -selection clipboard -o 2>/dev/null');
}

function setClipboard(string $text): void
{
    $cmd = getenv('XDG_SESSION_TYPE') === 'wayland'
        ? $GLOBALS['CMD_WL_COPY'] . ' 2>/dev/null'
        : $GLOBALS['CMD_XCLIP'] . ' -selection clipboard 2>/dev/null';

    $proc = proc_open($cmd, [0 => ['pipe', 'r']], $pipes);
    if (is_resource($proc)) {
        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        proc_close($proc);
    }
}

// --- Network ---

function getLanIp(): string
{
    if ($ip = getenv('HOST_IP')) {
        return $ip;
    }
    $output = shell_exec('hostname -I 2>/dev/null');
    if ($output) {
        return explode(' ', trim($output))[0];
    }
    return '127.0.0.1';
}

// --- HTML ---

define('HTML_TEMPLATE', <<<'HTMLEOF'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">
<meta name="theme-color" content="#000000">
<title>Clippy</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#000;--fg:#e2e8f0;--fg-muted:#94a3b8;--fg-faint:#64748b;
  --accent:#818cf8;--accent-hover:#6366f1;--accent-glow:rgba(129,140,248,.15);
  --card:#111;--card-sh1:rgba(0,0,0,.4);--card-sh2:rgba(0,0,0,.3);
  --heading:#94a3b8;--surface:#000;--border:#222;
  --btn2:#1a1a1a;--btn2-fg:#cbd5e1;--btn2-hover:#2a2a2a;
  --toast-bg:#f1f5f9;--toast-fg:#000;--url-bg:#1a1033;
}
body.light{
  --bg:#f5f5f5;--fg:#1e293b;--fg-muted:#64748b;--fg-faint:#94a3b8;
  --accent:#4f46e5;--accent-hover:#4338ca;--accent-glow:rgba(79,70,229,.1);
  --card:#fff;--card-sh1:rgba(0,0,0,.06);--card-sh2:rgba(0,0,0,.04);
  --heading:#475569;--surface:#f8fafc;--border:#e2e8f0;
  --btn2:#f1f5f9;--btn2-fg:#475569;--btn2-hover:#e2e8f0;
  --toast-bg:#1e293b;--toast-fg:#fff;--url-bg:#eef2ff;
}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
  background:var(--bg);color:var(--fg);min-height:100vh;padding:1rem;
}
.container{max-width:28.75rem;margin:0 auto;padding-top:1rem}
.card{
  background:var(--card);border-radius:.875rem;padding:1.25rem;margin-bottom:.875rem;
  box-shadow:0 1px 3px var(--card-sh1),0 1px 2px var(--card-sh2);
}
.card h2{
  font-size:.9375rem;font-weight:700;color:var(--heading);
  text-transform:uppercase;letter-spacing:.03em;margin-bottom:.875rem;
}
.qr-wrap{display:flex;justify-content:center;padding:.75rem 0}
.qr-wrap img,.qr-wrap canvas{border-radius:.5rem}
.server-url{
  display:block;text-align:center;
  font-family:'SF Mono',SFMono-Regular,Consolas,monospace;font-size:.875rem;
  color:var(--accent);padding:.5rem;background:var(--url-bg);border-radius:.5rem;
  margin-top:.625rem;word-break:break-all;
}
.clip-box{
  min-height:3.75rem;max-height:18.75rem;overflow-y:auto;
  background:var(--surface);border:1px solid var(--border);border-radius:.625rem;
  padding:.875rem;font-size:.875rem;line-height:1.5;
  word-break:break-word;white-space:pre-wrap;
}
.clip-box.empty{
  color:var(--fg-faint);font-style:italic;
  display:flex;align-items:center;justify-content:center;
}
.clip-box a{color:var(--accent);text-decoration:none;font-weight:500}
.clip-box a:hover{text-decoration:underline}
.btn-row{display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap}
button{
  flex:1;padding:.625rem 1rem;border:none;border-radius:.625rem;
  font-size:.875rem;font-weight:600;cursor:pointer;transition:all .15s;
}
.btn-s{background:var(--btn2);color:var(--btn2-fg)}
.btn-s:hover{background:var(--btn2-hover)}
.btn-p{background:var(--accent);color:#fff}
.btn-p:hover{background:var(--accent-hover)}
textarea{
  width:100%;min-height:6.25rem;padding:.875rem;
  border:1px solid var(--border);border-radius:.625rem;
  background:var(--surface);color:var(--fg);
  font-family:inherit;font-size:.875rem;resize:vertical;outline:none;
  transition:border-color .15s;
}
textarea:focus{border-color:var(--accent);box-shadow:0 0 0 .1875rem var(--accent-glow)}
.toast{
  position:fixed;bottom:1.5rem;left:50%;
  transform:translateX(-50%) translateY(6.25rem);
  background:var(--toast-bg);color:var(--toast-fg);padding:.75rem 1.5rem;border-radius:.625rem;
  font-size:.875rem;font-weight:500;opacity:0;transition:all .3s ease;
  z-index:100;pointer-events:none;
}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.dot{
  display:inline-block;width:.5rem;height:.5rem;border-radius:50%;
  background:#10b981;margin-right:.375rem;animation:pulse 2s ease-in-out infinite;
}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.auto-lbl{
  font-size:.75rem;color:var(--fg-faint);display:flex;align-items:center;
  margin-top:.625rem;justify-content:center;gap:.375rem;
}
.auto-lbl input{accent-color:var(--accent)}
#content-qr{margin-top:.75rem}
.theme-lbl{
  text-align:center;padding:1rem 0 .5rem;font-size:.75rem;color:var(--fg-faint);
}
.theme-lbl label{cursor:pointer;display:inline-flex;align-items:center;gap:.375rem}
.theme-lbl input{accent-color:var(--accent)}
.qr-wrap canvas,.qr-wrap img{background:#fff;padding:.5rem}
.connect-toggle{margin-bottom:.875rem}
.connect-toggle button{width:100%}
@media(max-width:47.99rem){.connect-card,.connect-toggle,#content-qr{display:none!important}}
</style>
</head>
<body>
<script>(function(){var c=(document.cookie.match(/(?:^|; )clippy-theme=(\w+)/)||[])[1];if(c==="light"||(!c&&matchMedia("(min-width:48rem)").matches))document.body.classList.add("light")})()</script>
<div class="container">
  <div class="card">
    <h2><span class="dot"></span>Clipboard Content</h2>
    <div class="clip-box empty" id="clip-display">Loading&hellip;</div>
    <div class="qr-wrap" id="content-qr"></div>
    <div class="btn-row">
      <button class="btn-s" onclick="refresh()">&#8635; Refresh</button>
      <button class="btn-s" onclick="copyToLocal()">Copy</button>
    </div>
    <label class="auto-lbl">
      <input type="checkbox" id="auto-poll" checked> Auto-refresh every __POLL_LABEL__
    </label>
  </div>

  <div class="card">
    <h2>Send to Clipboard</h2>
    <textarea id="send-input" placeholder="Paste or type text here..."></textarea>
    <div class="btn-row" style="margin-top:.625rem">
      <button class="btn-s" onclick="pasteFromLocal()">Paste</button>
      <button class="btn-p" onclick="sendToClipboard()">Send</button>
    </div>
  </div>

  <div class="card connect-card" id="connect-section">
    <h2>Connect</h2>
    <p style="font-size:.8125rem;color:var(--fg-muted);margin-bottom:.5rem;text-align:center">
      Scan to open on another device
    </p>
    <div class="qr-wrap" id="connect-qr"></div>
    <code class="server-url" id="server-url"></code>
  </div>

  <div class="connect-toggle">
    <button class="btn-s" id="connect-toggle-btn" onclick="toggleConnect()">Toggle QR code</button>
  </div>

  <div class="theme-lbl">
    <label><input type="checkbox" id="theme-toggle"> Light mode</label>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const URL_RE = /^https?:\/\/\S+$/;
const SERVER = "__SERVER_URL__";
let last = null;

// Theme toggle — default depends on screen width (set in inline script above)
const themeEl = document.getElementById("theme-toggle");
themeEl.checked = document.body.classList.contains("light");
themeEl.addEventListener("change", () => {
  document.body.classList.toggle("light", themeEl.checked);
  document.cookie="clippy-theme="+(themeEl.checked?"light":"dark")+";path=/;max-age=31536000;SameSite=Lax";
});

document.getElementById("server-url").textContent = SERVER;

function esc(s) {
  return s.replace(/&/g,"&amp;").replace(/</g,"&lt;")
          .replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

function toast(m) {
  const t = document.getElementById("toast");
  t.textContent = m; t.classList.add("show");
  setTimeout(() => t.classList.remove("show"), 2200);
}

function toggleConnect() {
  const s = document.getElementById("connect-section");
  s.hidden = !s.hidden;
}

function initQR() {
  if (typeof QRCode === "undefined") {
    document.getElementById("connect-qr").innerHTML =
      '<p style="color:var(--fg-faint);font-size:13px;text-align:center">QR library failed to load</p>';
    return;
  }
  new QRCode(document.getElementById("connect-qr"), {
    text: SERVER, width: 200, height: 200,
    colorDark: "#000000", colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
}

async function refresh() {
  try {
    const r = await fetch("/api/clipboard");
    const d = await r.json();
    const text = d.text || "";
    if (text === last) return;
    last = text;

    const el = document.getElementById("clip-display");
    const qr = document.getElementById("content-qr");

    if (d.error) {
      el.className = "clip-box empty";
      el.textContent = d.error;
      qr.innerHTML = "";
      last = null;
      return;
    }

    if (!text) {
      el.className = "clip-box empty";
      el.textContent = "Clipboard is empty";
      qr.innerHTML = "";
      return;
    }

    el.className = "clip-box";

    if (URL_RE.test(text.trim())) {
      const u = text.trim();
      el.innerHTML = '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(u) + '</a>';
      qr.innerHTML = "";
      if (typeof QRCode !== "undefined") {
        new QRCode(qr, {
          text: u, width: 180, height: 180,
          colorDark: "#000000", colorLight: "#ffffff",
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    } else {
      el.textContent = text;
      qr.innerHTML = "";
    }
  } catch (e) {
    console.error("refresh:", e);
  }
}

async function sendToClipboard() {
  const inp = document.getElementById("send-input");
  if (!inp.value) { toast("Nothing to send"); return; }
  try {
    const r = await fetch("/api/clipboard", {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({text: inp.value})
    });
    const d = await r.json();
    if (d.ok) {
      toast("\u2713 Sent to clipboard");
      inp.value = "";
      last = null;
      refresh();
    } else {
      toast(d.error || "Failed to set clipboard");
    }
  } catch (e) { toast("Error: " + e.message); }
}

async function copyToLocal() {
  const text = last || "";
  if (!text) { toast("Clipboard is empty"); return; }
  try {
    await navigator.clipboard.writeText(text);
    toast("\u2713 Copied");
  } catch (e) {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.cssText = "position:fixed;opacity:0;left:-9999px";
    document.body.appendChild(ta);
    ta.focus(); ta.select();
    try {
      document.execCommand("copy");
      toast("\u2713 Copied");
    } catch (e2) { toast("Long-press the text to copy"); }
    document.body.removeChild(ta);
  }
}

async function pasteFromLocal() {
  const inp = document.getElementById("send-input");
  try {
    const t = await navigator.clipboard.readText();
    inp.value = t;
    toast("\u2713 Pasted");
  } catch (e) {
    inp.focus();
    if (document.execCommand("paste")) return;
    toast("Long-press the text field, then tap Paste");
  }
}

setInterval(() => {
  if (document.getElementById("auto-poll").checked) refresh();
}, __POLL_INTERVAL__);

initQR();
refresh();
</script>
</body>
</html>
HTMLEOF);

// --- Request handler (built-in server) ---

function handleRequest(): void
{
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($uri) {
        case '/':
            $serverUrl = getenv('CLIPPY_SERVER_URL') ?: 'http://localhost:' . $GLOBALS['DEFAULT_PORT'];
            $html = str_replace(
                ['__SERVER_URL__', '__POLL_INTERVAL__', '__POLL_LABEL__'],
                [$serverUrl, (string) $GLOBALS['POLL_INTERVAL_MS'], ($GLOBALS['POLL_INTERVAL_MS'] / 1000) . 's'],
                HTML_TEMPLATE
            );
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache');
            echo $html;
            return;

        case '/api/clipboard':
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');

            if ($method === 'POST') {
                $body = file_get_contents('php://input');
                if (strlen($body) > $GLOBALS['MAX_CLIPBOARD_SIZE']) {
                    http_response_code(413);
                    echo json_encode(['ok' => false, 'error' => 'Content too large (max ' . ($GLOBALS['MAX_CLIPBOARD_SIZE'] / 1024) . ' KB)']);
                    return;
                }
                $data = json_decode($body, true);
                $text = $data['text'] ?? '';
                setClipboard($text);
                echo json_encode(['ok' => true]);
            } else {
                $text = getClipboard();
                if (strlen($text) > $GLOBALS['MAX_CLIPBOARD_SIZE']) {
                    echo json_encode(['text' => '', 'error' => 'Clipboard content too large (' . round(strlen($text) / 1024) . ' KB)']);
                } else {
                    echo json_encode(['text' => $text]);
                }
            }
            return;

        default:
            http_response_code(404);
            echo 'Not Found';
            return;
    }
}

// Built-in server routes here for each request
if (php_sapi_name() === 'cli-server') {
    handleRequest();
    exit;
}

// --- Main entry point (CLI) ---

$port = (int) ($argv[1] ?? getenv('PORT') ?: $DEFAULT_PORT);

// Check dependencies
$missing = [];
$sessionType = getenv('XDG_SESSION_TYPE') ?: '';
if ($sessionType === 'wayland') {
    if (!trim((string) shell_exec("command -v {$CMD_WL_PASTE} 2>/dev/null"))) {
        $missing[] = $PKG_WL_CLIPBOARD;
    }
} else {
    if (!trim((string) shell_exec("command -v {$CMD_XCLIP} 2>/dev/null"))) {
        $missing[] = $PKG_XCLIP;
    }
}
if ($missing) {
    fwrite(STDERR, 'Missing dependencies: ' . implode(', ', $missing) . "\n");
    fwrite(STDERR, 'Install with: sudo apt install ' . implode(' ', $missing) . "\n");
    exit(1);
}

$ip = getLanIp();
$serverUrl = "http://{$ip}:{$port}";

echo <<<BANNER

  Clippy - Clipboard Share

  Local:   http://localhost:{$port}
  Network: {$serverUrl}

  Open in browser or scan QR on phone
  Press Ctrl+C to stop

BANNER;

putenv("CLIPPY_SERVER_URL={$serverUrl}");

// Start clipboard watcher for Wayland — a single long-lived wl-paste --watch
// process that writes to the cache file only when the clipboard actually changes,
// instead of spawning wl-paste on every HTTP poll.
$cacheFile = $CACHE_FILE;
$watcherProc = null;

if ($sessionType === 'wayland') {
    $watchCmd = "exec {$CMD_WL_PASTE} --no-newline --type text/plain --watch sh -c "
        . escapeshellarg('cat > ' . $cacheFile);
    $watcherProc = proc_open($watchCmd, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $watcherPipes);
    if (is_resource($watcherProc)) {
        putenv('CLIPPY_WATCHER=1');
    }
}

register_shutdown_function(function() use (&$watcherProc, $cacheFile) {
    if ($watcherProc && is_resource($watcherProc)) {
        proc_terminate($watcherProc);
        proc_close($watcherProc);
    }
    @unlink($cacheFile);
});

$script = escapeshellarg(__FILE__);
passthru("php -S 0.0.0.0:{$port} {$script}");
