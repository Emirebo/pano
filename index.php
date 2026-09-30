<?php
/* =========================================================
   PANO — cihazlar arası kopyala/yapıştır
   Kurulum: bu dosyayı sunucuna yükle, aşağıdaki şifreyi değiştir.
   ========================================================= */

// Şifre config.php dosyasından okunur (kurulum scripti oluşturur).
// Elle kuruyorsan aşağıdaki varsayılan değeri değiştir.
define('PASSWORD', is_file(__DIR__ . '/config.php') ? (string)(require __DIR__ . '/config.php') : 'buraya-guclu-bir-sifre-yaz');
const MAX_CLIPS = 200;                            // saklanacak en fazla kayıt
const MAX_SIZE  = 1048576;                        // tek kayıt için en fazla 1 MB
const DATA_DIR  = __DIR__ . '/data';

/* ---------------------------------------------------------- */

if (PASSWORD === 'buraya-guclu-bir-sifre-yaz' || strlen(PASSWORD) < 8) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Pano henüz kurulmadı: config.php içinde en az 8 karakterlik bir şifre belirle.\n");
}

const DATA_FILE = DATA_DIR . '/clips.php';
const LOCK_FILE = DATA_DIR . '/clips.lock'; // veri dosyasından ayrı, hiç yeniden adlandırılmayan sabit kilit dosyası
const GUARD     = "<?php exit; ?>\n"; // dosya tarayıcıdan açılırsa içerik görünmez
const COOKIE    = 'pano_auth';

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function auth_token(): string { return hash_hmac('sha256', 'pano-auth-v1', PASSWORD); }
function authed(): bool {
    return isset($_COOKIE[COOKIE]) && hash_equals(auth_token(), (string)$_COOKIE[COOKIE]);
}
function set_auth_cookie(string $value, int $expires): void {
    setcookie(COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}

function ensure_storage(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!file_exists(DATA_DIR . '/index.html')) file_put_contents(DATA_DIR . '/index.html', '');
}

/**
 * Veriyi sabit bir kilit dosyası üzerinden kilitli şekilde okur, isteğe bağlı
 * olarak değiştirip yazar. Kilit, veri dosyasından AYRI tutulur (clips.lock):
 * atomik rename ile değiştirilen bir dosyanın üzerinde flock tutmak güvenli
 * değildir (rename sonrası yeni açılan handle'lar eski kilidi görmez).
 */
function with_store(callable $fn, bool $write) {
    ensure_storage();
    $lockFp = @fopen(LOCK_FILE, 'c+');
    if (!$lockFp) throw new RuntimeException('Kilit dosyası açılamadı. data/ klasörünün yazma iznini kontrol et.');
    flock($lockFp, $write ? LOCK_EX : LOCK_SH);
    try {
        $raw = is_file(DATA_FILE) ? @file_get_contents(DATA_FILE) : '';
        if ($raw === false) throw new RuntimeException('Veri dosyası okunamadı. data/ klasörünün okuma iznini kontrol et.');

        if ($raw === '') {
            // Dosya hiç yok ya da gerçekten boş: ilk kullanım, boş store ile başla.
            $store = ['rev' => 0, 'clips' => []];
        } else {
            $decoded = (strncmp($raw, GUARD, strlen(GUARD)) === 0)
                ? json_decode(substr($raw, strlen(GUARD)), true)
                : null;
            if (!is_array($decoded) || !isset($decoded['clips']) || !is_array($decoded['clips']) || !isset($decoded['rev'])) {
                // Dosyada içerik var ama okunamıyor: sessizce sıfırlama, açıkça hata ver.
                throw new RuntimeException('Veri dosyası bozuk görünüyor (data/clips.php). Güvenlik için üzerine yazılmadı; dosyayı elle kontrol et.');
            }
            $store = $decoded;
        }

        $result = $fn($store);

        if ($write) {
            $store['rev']++;
            $json = json_encode($store, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Veri kodlanamadı (' . json_last_error_msg() . '). Kayıt yapılmadı.');
            }
            write_store_file(GUARD . $json);
            $result = $store;
        }

        return $result;
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
}

/**
 * Veriyi aynı klasördeki geçici bir dosyaya tam olarak yazıp, ardından
 * atomik rename ile gerçek dosyanın üzerine geçirir. Yarıda kalan bir yazma
 * asıl data/clips.php dosyasını hiç etkilemez; temp dosya her durumda temizlenir.
 */
function write_store_file(string $payload): void {
    $tmp = DATA_FILE . '.tmp-' . bin2hex(random_bytes(4));
    $fp = @fopen($tmp, 'wb');
    if (!$fp) throw new RuntimeException('Geçici dosya oluşturulamadı. Kayıt yapılmadı.');
    $written = fwrite($fp, $payload);
    $flushed = fflush($fp);
    fclose($fp);
    if ($written !== strlen($payload) || !$flushed) {
        @unlink($tmp);
        throw new RuntimeException('Veri diske tam yazılamadı. Kayıt yapılmadı, önceki veri korundu.');
    }
    if (!@rename($tmp, DATA_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Veri dosyası güncellenemedi. Kayıt yapılmadı, önceki veri korundu.');
    }
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- Çıkış ---------------- */
if (isset($_GET['logout'])) {
    set_auth_cookie('', time() - 3600);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

/* ---------------- Giriş ---------------- */
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals(PASSWORD, (string)$_POST['password'])) {
        set_auth_cookie(auth_token(), time() + 60 * 60 * 24 * 90); // 90 gün hatırla
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    sleep(1); // kaba kuvvet denemelerini yavaşlat
    $loginError = 'Şifre yanlış. Tekrar dene.';
}

/* ---------------- API ---------------- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!authed()) json_out(['error' => 'Oturum kapalı. Sayfayı yenileyip giriş yap.'], 401);

    $action = (string)$_GET['api'];
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($isPost && ($_SERVER['HTTP_X_PANO'] ?? '') !== '1') json_out(['error' => 'Geçersiz istek.'], 403);
    $in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

    try {
        switch ($action) {
            case 'list':
                $clientRev = isset($_GET['rev']) ? (int)$_GET['rev'] : -1;
                $store = with_store(fn(&$s) => $s, false);
                if ($store['rev'] === $clientRev) json_out(['changed' => false, 'rev' => $store['rev']]);
                json_out(['changed' => true] + $store);

            case 'add':
                if (!$isPost) json_out(['error' => 'POST gerekli.'], 405);
                $text = $in['text'] ?? '';
                if (!is_string($text) || trim($text) === '') json_out(['error' => 'Boş metin gönderilemez.'], 400);
                if (strlen($text) > MAX_SIZE) json_out(['error' => 'Metin çok uzun (en fazla 1 MB).'], 413);
                $device = trim((string)($in['device'] ?? ''));
                $device = function_exists('mb_substr') ? mb_substr($device, 0, 40) : preg_replace('/^(.{0,40}).*$/us', '$1', $device);
                $device = $device ?: 'Cihaz';
                $store = with_store(function (&$s) use ($text, $device) {
                    array_unshift($s['clips'], [
                        'id' => bin2hex(random_bytes(6)),
                        'text' => $text,
                        'device' => $device,
                        'ts' => time(),
                    ]);
                    $s['clips'] = array_slice($s['clips'], 0, MAX_CLIPS);
                }, true);
                json_out(['changed' => true] + $store);

            case 'delete':
                if (!$isPost) json_out(['error' => 'POST gerekli.'], 405);
                $id = (string)($in['id'] ?? '');
                $store = with_store(function (&$s) use ($id) {
                    $s['clips'] = array_values(array_filter($s['clips'], fn($c) => $c['id'] !== $id));
                }, true);
                json_out(['changed' => true] + $store);

            case 'clear':
                if (!$isPost) json_out(['error' => 'POST gerekli.'], 405);
                $store = with_store(function (&$s) { $s['clips'] = []; }, true);
                json_out(['changed' => true] + $store);

            default:
                json_out(['error' => 'Bilinmeyen işlem.'], 404);
        }
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
}

$loggedIn = authed();
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Pano</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📋</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#E9EDF1; --surface:#FFFFFF; --ink:#1A2330; --muted:#5E6A7A;
  --line:#CBD3DC; --accent:#2350C8; --accent-ink:#FFFFFF; --danger:#B3261E;
  --code-bg:#F4F6F9;
  --sans:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  --mono:"JetBrains Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  box-sizing:border-box;
  padding-top:env(safe-area-inset-top,0px); padding-bottom:env(safe-area-inset-bottom,0px);
}
@media (prefers-color-scheme:dark){
  :root{
    --bg:#12171E; --surface:#1B222C; --ink:#E4E9EF; --muted:#94A0B0;
    --line:#2E3845; --accent:#7C9CFF; --accent-ink:#0E1420; --danger:#FF8A80;
    --code-bg:#151B23;
  }
}
*,*::before,*::after{box-sizing:inherit}
html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:16px/1.5 var(--sans);-webkit-text-size-adjust:100%}
button,input,textarea{font:inherit;color:inherit}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}

.wrap{max-width:780px;margin:0 auto;padding:20px 16px 48px}

header{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
header h1{font-size:1.5rem;font-weight:600;margin:0;letter-spacing:-.01em;flex:1}
.device{display:flex;align-items:center;gap:6px;color:var(--muted);font-size:.875rem}
.device input{width:9.5em;padding:4px 8px;border:1px solid var(--line);border-radius:6px;background:var(--surface);font-size:.875rem}
.link{color:var(--muted);font-size:.875rem;text-decoration:none}
.link:hover{color:var(--ink);text-decoration:underline}

.composer{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:12px}
.composer textarea{
  display:block;width:100%;min-height:120px;resize:vertical;border:0;background:transparent;
  font:14px/1.55 var(--mono);padding:4px;outline:none;tab-size:4;
}
.composer-row{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
.hint{color:var(--muted);font-size:.8125rem;margin-right:auto}

.btn{border:1px solid var(--line);background:var(--surface);border-radius:7px;padding:7px 14px;cursor:pointer;font-size:.9375rem;font-weight:500;white-space:nowrap}
.btn:hover{border-color:var(--muted)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.small{padding:4px 10px;font-size:.8125rem}
.btn.danger{color:var(--danger)}
.btn.done{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.btn:disabled{opacity:.55;cursor:default}

.list-head{display:flex;align-items:baseline;justify-content:space-between;margin:28px 0 10px}
.list-head h2{font-size:1rem;font-weight:600;margin:0}

.clip{background:var(--surface);border:1px solid var(--line);border-radius:10px;margin-bottom:10px;overflow:hidden}
.clip.fresh{animation:fresh 1.6s ease-out}
@keyframes fresh{from{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}}
.clip-meta{display:flex;align-items:center;gap:8px;padding:8px 10px 8px 14px;font-size:.8125rem;color:var(--muted)}
.clip-meta .who{font-weight:500;color:var(--ink)}
.clip-meta .actions{margin-left:auto;display:flex;gap:6px}
.clip pre{
  margin:0;padding:12px 14px;background:var(--code-bg);border-top:1px solid var(--line);
  font:13.5px/1.55 var(--mono);white-space:pre-wrap;word-break:break-word;
  max-height:18em;overflow:auto;tab-size:4;cursor:copy;
}

.empty{border:1px dashed var(--line);border-radius:10px;padding:28px 16px;text-align:center;color:var(--muted)}
.status{position:fixed;left:50%;bottom:calc(16px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);
  background:var(--ink);color:var(--bg);padding:8px 14px;border-radius:8px;font-size:.875rem;opacity:0;pointer-events:none;transition:opacity .2s}
.status.show{opacity:1}

/* giriş */
.login{max-width:340px;margin:18vh auto 0;padding:0 16px}
.login h1{font-size:1.5rem;font-weight:600;margin:0 0 4px}
.login p{color:var(--muted);margin:0 0 20px}
.login input{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);margin-bottom:10px}
.login .btn{width:100%;padding:10px}
.login .err{color:var(--danger);font-size:.875rem;margin:0 0 10px}

@media (max-width:520px){
  .hint{display:none}
  .composer-row .btn{flex:1}
}
@media (prefers-reduced-motion:reduce){.clip.fresh{animation:none}.status{transition:none}}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
  <main class="login">
    <h1>Pano</h1>
    <p>Devam etmek için şifreni gir.</p>
    <form method="post" autocomplete="on">
      <?php if ($loginError): ?><p class="err"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
      <input type="password" name="password" placeholder="Şifre" autofocus required autocomplete="current-password">
      <button class="btn primary" type="submit">Giriş yap</button>
    </form>
  </main>
<?php else: ?>
  <div class="wrap">
    <header>
      <h1>Pano</h1>
      <label class="device">Bu cihaz <input id="device" maxlength="40" spellcheck="false"></label>
      <a class="link" href="?logout=1">Çıkış</a>
    </header>

    <section class="composer">
      <textarea id="input" placeholder="Metni veya kodu buraya yapıştır…" spellcheck="false" autocapitalize="off" autocomplete="off"></textarea>
      <div class="composer-row">
        <span class="hint">Ctrl + Enter ile gönder</span>
        <button class="btn" id="pasteBtn" type="button">Panodan yapıştır</button>
        <button class="btn primary" id="sendBtn" type="button">Gönder</button>
      </div>
    </section>

    <div class="list-head">
      <h2 id="count">Kayıtlar</h2>
      <button class="btn small danger" id="clearBtn" type="button" hidden>Tümünü sil</button>
    </div>
    <div id="list"></div>
  </div>
  <div class="status" id="status" role="status" aria-live="polite"></div>

<script>
(() => {
  const $ = s => document.querySelector(s);
  const input = $('#input'), list = $('#list'), sendBtn = $('#sendBtn');
  const state = { rev: -1, clips: [], seen: new Set(), first: true };

  // --- 401 sonrası reload'da yazılmakta olan taslağı kurtar ---
  try {
    const draft = sessionStorage.getItem('pano_draft');
    if (draft) { input.value = draft; sessionStorage.removeItem('pano_draft'); }
  } catch (e) {}

  // --- cihaz adı ---
  const deviceInput = $('#device');
  const guess = /Android|iPhone|iPad|Mobile/i.test(navigator.userAgent) ? 'Telefon' : 'Bilgisayar';
  let deviceName = guess;
  try { deviceName = localStorage.getItem('pano_device') || guess; } catch (e) {}
  deviceInput.value = deviceName;
  deviceInput.addEventListener('change', () => {
    deviceName = deviceInput.value.trim() || guess;
    deviceInput.value = deviceName;
    try { localStorage.setItem('pano_device', deviceName); } catch (e) {}
  });

  // --- bildirim ---
  let statusTimer;
  function toast(msg) {
    const el = $('#status');
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(statusTimer);
    statusTimer = setTimeout(() => el.classList.remove('show'), 1800);
  }

  // --- API ---
  async function api(action, body) {
    const url = '?api=' + action + (body ? '' : '&rev=' + state.rev);
    const opt = body
      ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Pano': '1' }, body: JSON.stringify(body) }
      : { cache: 'no-store' };
    const r = await fetch(url, opt);
    if (r.status === 401) {
      try { if (input.value.trim()) sessionStorage.setItem('pano_draft', input.value); } catch (e) {}
      location.reload();
      throw new Error('auth');
    }
    const j = await r.json().catch(() => ({ error: 'Sunucudan geçersiz yanıt geldi.' }));
    if (!r.ok) throw new Error(j.error || 'İşlem başarısız.');
    if (j.changed) { state.rev = j.rev; state.clips = j.clips || []; render(); }
    return j;
  }

  // --- kopyalama ---
  async function copyText(text) {
    try { await navigator.clipboard.writeText(text); return true; } catch (e) {}
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
    document.body.appendChild(ta);
    ta.select(); ta.setSelectionRange(0, text.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    ta.remove();
    return ok;
  }

  function timeAgo(ts) {
    const d = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (d < 45) return 'az önce';
    if (d < 3600) return Math.round(d / 60) + ' dk önce';
    if (d < 86400) return Math.round(d / 3600) + ' sa önce';
    return new Date(ts * 1000).toLocaleString('tr-TR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  // --- listeyi çiz ---
  function render() {
    const clips = state.clips;
    $('#count').textContent = clips.length ? `Kayıtlar (${clips.length})` : 'Kayıtlar';
    $('#clearBtn').hidden = clips.length === 0;
    list.replaceChildren();

    if (!clips.length) {
      const e = document.createElement('div');
      e.className = 'empty';
      e.textContent = 'Henüz kayıt yok. Yukarıya bir şey yapıştırıp gönder, diğer cihazında burada görünsün.';
      list.append(e);
      return;
    }

    for (const c of clips) {
      const art = document.createElement('article');
      art.className = 'clip';
      if (!state.first && !state.seen.has(c.id)) art.classList.add('fresh');
      state.seen.add(c.id);

      const meta = document.createElement('div');
      meta.className = 'clip-meta';
      const who = document.createElement('span'); who.className = 'who'; who.textContent = c.device;
      const when = document.createElement('time'); when.dataset.ts = c.ts; when.textContent = timeAgo(c.ts);

      const actions = document.createElement('div'); actions.className = 'actions';
      const copyBtn = document.createElement('button');
      copyBtn.className = 'btn small'; copyBtn.type = 'button'; copyBtn.textContent = 'Kopyala';
      const delBtn = document.createElement('button');
      delBtn.className = 'btn small danger'; delBtn.type = 'button'; delBtn.textContent = 'Sil';
      actions.append(copyBtn, delBtn);
      meta.append(who, when, actions);

      const pre = document.createElement('pre');
      pre.textContent = c.text;
      pre.title = 'Kopyalamak için çift tıkla';

      const doCopy = async () => {
        if (await copyText(c.text)) {
          copyBtn.textContent = 'Kopyalandı'; copyBtn.classList.add('done');
          setTimeout(() => { copyBtn.textContent = 'Kopyala'; copyBtn.classList.remove('done'); }, 1400);
        } else toast('Kopyalanamadı. Metni seçip elle kopyala.');
      };
      copyBtn.addEventListener('click', doCopy);
      pre.addEventListener('dblclick', doCopy);
      delBtn.addEventListener('click', () => {
        if (!confirm('Bu kaydı silmek istediğine emin misin?')) return;
        api('delete', { id: c.id }).catch(e => toast(e.message));
      });

      art.append(meta, pre);
      list.append(art);
    }
    state.first = false;
  }

  // --- gönder ---
  async function send() {
    const text = input.value;
    if (!text.trim()) { input.focus(); return; }
    sendBtn.disabled = true;
    try {
      await api('add', { text, device: deviceName });
      input.value = '';
      toast('Gönderildi');
    } catch (e) { toast(e.message); }
    finally { sendBtn.disabled = false; input.focus(); }
  }
  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
    if (e.key === 'Tab' && !e.shiftKey) { // kod yazarken Tab karakteri eklensin
      e.preventDefault();
      const s = input.selectionStart, en = input.selectionEnd;
      input.setRangeText('\t', s, en, 'end');
    }
  });

  $('#pasteBtn').addEventListener('click', async () => {
    try {
      const t = await navigator.clipboard.readText();
      if (t) { input.value = t; input.focus(); } else toast('Pano boş.');
    } catch (e) {
      input.focus();
      toast('Tarayıcı izin vermedi. Kutuya uzun basıp / Ctrl+V ile yapıştır.');
    }
  });

  $('#clearBtn').addEventListener('click', () => {
    if (confirm('Tüm kayıtlar silinsin mi? Bu geri alınamaz.')) api('clear', {}).catch(e => toast(e.message));
  });

  // --- canlı güncelleme ---
  const poll = () => { if (!document.hidden) api('list').catch(() => {}); };
  poll();
  setInterval(poll, 3000);
  document.addEventListener('visibilitychange', poll);
  window.addEventListener('focus', poll);
  setInterval(() => document.querySelectorAll('time[data-ts]').forEach(t => t.textContent = timeAgo(+t.dataset.ts)), 30000);
})();
</script>
<?php endif; ?>
</body>
</html>
