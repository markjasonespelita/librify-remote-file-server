<?php
declare(strict_types=1);

/* =====================================================================
   filemanager.php — a single-file web file manager (PHP 8.0+)
   Upload, download, create/rename/delete/edit folders and files,
   browse sub-folders, and preview images, video, audio, PDF and text.
   ===================================================================== */

// ---- Settings --------------------------------------------------------
const APP_PASSWORD  = 'librifydict';              // CHANGE THIS. Use '' to turn the login off (not recommended).
const STORAGE_DIR   = __DIR__ . '/storage';    // Where the files live (created automatically).
const MAX_EDIT_SIZE = 2 * 1024 * 1024;         // Largest text file the editor will open/save (bytes).
const BLOCKED_EXT   = ['php','php3','php4','php5','php7','php8','phtml','pht','phps','phar','cgi','htaccess','htpasswd'];

const KINDS = [
    'image' => ['jpg','jpeg','png','gif','webp','bmp','svg','avif','ico'],
    'video' => ['mp4','m4v','webm','ogv','mov','mkv'],
    'audio' => ['mp3','wav','ogg','oga','m4a','aac','flac','opus'],
    'pdf'   => ['pdf'],
    'text'  => ['txt','md','json','csv','tsv','log','xml','html','htm','css','js','ts','ini','yml','yaml','toml','sql','sh','conf','py','c','h','cpp','java','go','rs'],
];
const MIME = [
    'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp',
    'svg'=>'image/svg+xml','avif'=>'image/avif','ico'=>'image/x-icon',
    'mp4'=>'video/mp4','m4v'=>'video/mp4','webm'=>'video/webm','ogv'=>'video/ogg','mov'=>'video/quicktime','mkv'=>'video/x-matroska',
    'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','oga'=>'audio/ogg','m4a'=>'audio/mp4','aac'=>'audio/aac','flac'=>'audio/flac','opus'=>'audio/ogg',
    'pdf'=>'application/pdf',
];

// ---- Bootstrap -------------------------------------------------------
session_name('fm_session');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

if (!is_dir(STORAGE_DIR)) { @mkdir(STORAGE_DIR, 0755, true); }
$guard = STORAGE_DIR . '/.htaccess';   // Files are only reachable through this script on Apache.
if (is_dir(STORAGE_DIR) && !is_file($guard)) {
    @file_put_contents($guard, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// ---- Helpers ---------------------------------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function root(): string { static $r = null; return $r ??= (string)realpath(STORAGE_DIR); }

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d);
    exit;
}
function fail(string $msg, int $code = 400): void { json_out(['ok' => false, 'error' => $msg], $code); }

/** Normalise a relative path. Returns null if it tries to escape or touch hidden files. */
function clean_rel(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    if (str_contains($rel, "\0")) return null;
    $out = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..' || $seg[0] === '.') return null;
        $out[] = $seg;
    }
    return implode('/', $out);
}
/** Resolve a relative path to an existing absolute path inside the storage folder. */
function resolve(string $rel): ?string {
    $c = clean_rel($rel);
    if ($c === null) return null;
    $real = realpath($c === '' ? root() : root() . '/' . $c);
    if ($real === false) return null;
    if ($real !== root() && !str_starts_with($real, root() . DIRECTORY_SEPARATOR)) return null;
    return $real;
}
function valid_name(string $n, bool $isFile): bool {
    if ($n === '' || strlen($n) > 255) return false;
    if (preg_match('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/', $n)) return false;
    if ($n[0] === '.' || substr($n, -1) === '.' || substr($n, -1) === ' ') return false;
    if ($isFile) {
        $parts = explode('.', strtolower($n));
        array_shift($parts);
        foreach ($parts as $p) { if (in_array($p, BLOCKED_EXT, true)) return false; }
    }
    return true;
}
function unique_path(string $dir, string $name): string {
    $p = "$dir/$name";
    if (!file_exists($p)) return $p;
    $info = pathinfo($name);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    for ($i = 1;; $i++) {
        $p = "$dir/$base ($i)$ext";
        if (!file_exists($p)) return $p;
    }
}
function rrmdir(string $d): bool {
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = "$d/$e";
        if (is_dir($p) && !is_link($p)) rrmdir($p); else @unlink($p);
    }
    return @rmdir($d);
}
function ext_of(string $name): string { return strtolower(pathinfo($name, PATHINFO_EXTENSION)); }
function kind_of(string $name): string {
    $e = ext_of($name);
    foreach (KINDS as $k => $list) { if (in_array($e, $list, true)) return $k; }
    return 'other';
}
function fmt_size(int $b): string {
    $u = ['B','KB','MB','GB','TB'];
    $i = 0; $n = (float)$b;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$n : number_format($n, $n >= 10 ? 0 : 1)) . ' ' . $u[$i];
}
function ini_bytes(string $k): int {
    $v = trim((string)ini_get($k));
    if ($v === '') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}
function upload_limit(): int {
    $l = array_filter([ini_bytes('upload_max_filesize'), ini_bytes('post_max_size')]);
    return $l ? min($l) : 0;
}
function upload_error(int $c): string {
    return match ($c) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is larger than the server limit (' . ini_get('upload_max_filesize') . ').',
        UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
        UPLOAD_ERR_NO_FILE   => 'No file was received.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the file (check temp folder and permissions).',
        default              => 'Upload failed.',
    };
}
function disp(string $type, string $name): string {
    $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
    return "$type; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name);
}
function browse_url(string $rel): string { return '?p=' . str_replace('%2F', '/', rawurlencode($rel)); }
function act_url(string $a, string $rel): string { return '?a=' . $a . '&p=' . rawurlencode($rel); }

/** Stream a file with HTTP Range support, so video/audio can seek. */
function send_file(string $path, bool $download): void {
    $size = (int)filesize($path);
    $name = basename($path);
    $ext  = ext_of($name);
    $kind = kind_of($name);
    $mime = $kind === 'text' ? 'text/plain; charset=utf-8' : (MIME[$ext] ?? 'application/octet-stream');

    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $len = $end - $start + 1;

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    if ($ext === 'svg') header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    header('Content-Disposition: ' . disp($download ? 'attachment' : 'inline', $name));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)filemtime($path)) . ' GMT');
    header('Cache-Control: private, max-age=0, must-revalidate');

    session_write_close();
    while (ob_get_level()) ob_end_clean();
    set_time_limit(0);
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;

    $fp = fopen($path, 'rb');
    if ($fp === false) exit;
    fseek($fp, $start);
    $left = $len;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, min(65536, $left));
        if ($chunk === false) break;
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}

function send_zip(string $dir): void {
    if (!class_exists('ZipArchive')) { http_response_code(501); exit('ZIP support is not enabled in PHP (install the php-zip extension).'); }
    $tmp = tempnam(sys_get_temp_dir(), 'fmz');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Could not create the ZIP file.'); }
    $base = $dir === root() ? 'storage' : basename($dir);
    $zip->addEmptyDir($base);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $fi) {
        $local = str_replace('\\', '/', substr($fi->getPathname(), strlen($dir) + 1));
        if ($fi->isLink() || preg_match('#(^|/)\.#', $local)) continue;
        if ($fi->isDir()) $zip->addEmptyDir("$base/$local"); else $zip->addFile($fi->getPathname(), "$base/$local");
    }
    $zip->close();
    session_write_close();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: ' . disp('attachment', $base . '.zip'));
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ---- Styles (shared by the login page and the app) --------------------
$CSS = <<<'CSS'
:root{--bg:#edf0f3;--panel:#fff;--ink:#16202a;--muted:#667585;--line:#d9dfe5;--hover:#f4f6f8;--accent:#0e6b63;--on-accent:#fff;--folder:#e2a33a;--danger:#b42318;--steel:#8997a5;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#11161b;--panel:#1a2129;--ink:#e7ecf0;--muted:#8a99a8;--line:#2b3641;--hover:#202932;--accent:#3cc4b4;--on-accent:#08201d;--danger:#f2877d;--steel:#6d7e8e;color-scheme:dark}}
*{box-sizing:border-box}
html,body{margin:0}
body{background:var(--bg);color:var(--ink);font:15px/1.45 "Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif}
a{color:inherit}
button,input,textarea{font:inherit;color:inherit}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.45rem .85rem;border:1px solid var(--line);background:var(--panel);border-radius:7px;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn:hover{background:var(--hover)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--on-accent)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.icon-btn{display:inline-grid;place-items:center;width:32px;height:32px;border:0;border-radius:6px;background:transparent;color:var(--muted);cursor:pointer}
.icon-btn:hover{background:var(--line);color:var(--ink)}
.icon-btn.del:hover{color:var(--danger)}
input[type=text],input[type=search],input[type=password]{padding:.5rem .7rem;border:1px solid var(--line);border-radius:7px;background:var(--panel);width:100%}
.bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:.75rem 1rem;align-items:center;padding:.8rem 1.25rem;background:var(--panel);border-bottom:1px solid var(--line)}
.crumbs{flex:1 1 280px;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:.15rem .1rem;font-size:1.05rem}
.crumbs a{color:var(--muted);text-decoration:none;padding:.1rem .3rem;border-radius:5px}
.crumbs a:hover{color:var(--ink);background:var(--hover)}
.crumbs .sep{color:var(--steel)}
.crumbs .cur{font-weight:600;padding:.1rem .3rem}
.tools{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.tools input[type=search]{width:190px}
main{max-width:1100px;margin:1.4rem auto;padding:0 1.25rem}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-weight:500;font-size:.82rem;color:var(--muted);padding:.6rem .9rem;border-bottom:1px solid var(--line)}
td{padding:.5rem .9rem;border-bottom:1px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:var(--hover)}
td.n{width:100%;max-width:0}
td.n a{display:flex;align-items:center;gap:.7rem;text-decoration:none;min-width:0}
td.n a span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
td.n a:hover span{text-decoration:underline}
td.s,td.m{white-space:nowrap;color:var(--muted);font-variant-numeric:tabular-nums}
td.a{white-space:nowrap;text-align:right}
.fo{width:30px;height:26px;flex:none;fill:var(--folder)}
.fi{width:26px;height:32px;flex:none}
.fi .pg{fill:var(--panel);stroke:var(--steel);stroke-width:1.6}
.fi .fold{fill:none;stroke:var(--steel);stroke-width:1.6}
.fi text{font:700 8.5px system-ui,sans-serif;fill:var(--muted)}
.empty{padding:3rem 1rem;text-align:center;color:var(--muted)}
.note{margin:0 0 1rem;padding:.6rem .9rem;border:1px solid var(--line);border-left:3px solid var(--folder);border-radius:7px;background:var(--panel)}
.foot{margin:.9rem .2rem;color:var(--muted);font-size:.85rem}
dialog{border:1px solid var(--line);border-radius:12px;background:var(--panel);color:var(--ink);padding:0;max-width:96vw}
dialog::backdrop{background:rgba(8,12,16,.6)}
.dlg{padding:1.25rem;width:min(420px,92vw)}
.dlg h2{margin:0 0 .4rem;font-size:1.1rem}
.dlg p{margin:0 0 .9rem;color:var(--muted)}
.dlg .row{display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem}
.big{width:min(1100px,96vw);height:min(88vh,840px)}
.big[open]{display:flex;flex-direction:column}
.big header{display:flex;align-items:center;gap:.5rem;padding:.6rem .8rem;border-bottom:1px solid var(--line)}
.big header h2{flex:1;margin:0;font-size:1rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#stage{flex:1;min-height:0;display:grid;place-items:center;background:#0b0f13;overflow:auto}
#stage img,#stage video{max-width:100%;max-height:100%;display:block}
#stage video{width:100%;height:100%;background:#000}
#stage audio{width:min(560px,90%)}
#stage iframe{width:100%;height:100%;border:0;background:#fff}
#stage pre{align-self:stretch;justify-self:stretch;margin:0;padding:1rem;color:#d7dee5;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;overflow:auto}
#code{flex:1;width:100%;border:0;border-radius:0;resize:none;padding:1rem;font:13px/1.55 ui-monospace,Menlo,Consolas,monospace;background:var(--panel);tab-size:4}
#code:focus-visible{outline-offset:-2px}
.big footer{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.6rem .8rem;border-top:1px solid var(--line);color:var(--muted)}
#drop{position:fixed;inset:0;z-index:20;display:none;place-items:center;background:color-mix(in srgb,var(--bg) 88%,transparent);pointer-events:none}
.dragging #drop{display:grid}
#drop div{padding:2.2rem 3rem;border:2px dashed var(--accent);border-radius:14px;background:var(--panel);font-size:1.1rem}
#uploads{position:fixed;right:1rem;bottom:1rem;z-index:15;width:min(340px,calc(100vw - 2rem));max-height:50vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:.6rem .8rem}
#uploads[hidden]{display:none}
.up{padding:.35rem 0;font-size:.88rem}
.up .t{display:flex;justify-content:space-between;gap:.5rem}
.up .t b{font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.up .bar2{height:4px;margin-top:.3rem;border-radius:2px;background:var(--line);overflow:hidden}
.up .bar2 i{display:block;height:100%;width:0;background:var(--accent)}
.up.bad .bar2 i{background:var(--danger)}
.up .msg{color:var(--danger)}
#toast{position:fixed;left:50%;bottom:1.2rem;transform:translateX(-50%);z-index:30;padding:.55rem 1rem;border-radius:8px;background:var(--ink);color:var(--bg);display:none}
#toast.bad{background:var(--danger);color:#fff}
.login{min-height:100vh;display:grid;place-items:center;padding:1rem}
.login form{width:min(340px,100%);padding:1.5rem;background:var(--panel);border:1px solid var(--line);border-radius:12px}
.login h1{margin:0 0 1rem;font-size:1.2rem}
.login .err{color:var(--danger);margin:.6rem 0 0}
.login .btn{margin-top:.9rem;width:100%;justify-content:center}
@media (max-width:640px){th.m,td.m{display:none}.bar{padding:.7rem .9rem}main{padding:0 .6rem}.tools input[type=search]{width:100%}.tools{width:100%}}
CSS;

// ---- Auth ------------------------------------------------------------
$action = (string)($_GET['a'] ?? '');
$authed = APP_PASSWORD === '' || !empty($_SESSION['ok']);

if ($action === 'logout') {
    if (hash_equals($_SESSION['csrf'], (string)($_GET['t'] ?? ''))) { $_SESSION = []; session_destroy(); }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (!$authed) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals(APP_PASSWORD, (string)$_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        sleep(1);
        $error = 'Wrong password. Try again.';
    } elseif ($action !== '') {
        http_response_code(401);
        exit('Sign in required.');
    }
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in</title><style><?= $CSS ?></style></head>
<body><div class="login"><form method="post">
<h1>Librify Backup Remote File Server</h1>
<h5>Sign in to your files</h5>
<label for="pw">Password</label>
<input id="pw" type="password" name="password" autocomplete="current-password" autofocus required>
<?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
<button class="btn primary">Sign in</button>
</form></div></body></html>
<?php
    exit;
}

// ---- Actions (POST, JSON) --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        fail('Your session expired. Reload the page and try again.', 403);
    }
    switch ($action) {
        case 'upload':
            $curRel = clean_rel((string)($_POST['p'] ?? ''));
            $sub    = clean_rel((string)($_POST['rel'] ?? ''));
            if ($curRel === null || $sub === null) fail('Invalid folder.');
            if (empty($_FILES['file'])) fail('File is larger than the server limit (' . fmt_size(upload_limit()) . ').');
            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) fail(upload_error((int)$f['error']));
            $name = trim(basename(str_replace('\\', '/', (string)$f['name'])));
            if (!valid_name($name, true)) fail("“{$name}” has a name or file type that isn’t allowed.");
            $targetRel = trim($curRel . '/' . $sub, '/');
            if ($sub !== '') {
                foreach (explode('/', $sub) as $seg) { if (!valid_name($seg, false)) fail('Invalid folder name in the upload.'); }
                $full = root() . '/' . $targetRel;
                if (!is_dir($full) && !@mkdir($full, 0755, true)) fail('Could not create the folder.');
            }
            $dir = resolve($targetRel);
            if ($dir === null || !is_dir($dir)) fail('Folder not found.', 404);
            if (!move_uploaded_file($f['tmp_name'], unique_path($dir, $name))) fail('Could not save the file. Check folder permissions.', 500);
            json_out(['ok' => true]);

        case 'mkdir':
            $dir  = resolve((string)($_POST['p'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($dir === null || !is_dir($dir)) fail('Folder not found.', 404);
            if (!valid_name($name, false)) fail('That folder name isn’t allowed. Avoid / \ : * ? " < > | and a leading dot.');
            if (file_exists("$dir/$name")) fail('A file or folder with that name already exists.');
            if (!@mkdir("$dir/$name", 0755)) fail('Could not create the folder.', 500);
            json_out(['ok' => true]);

        case 'rename':
            $rel  = clean_rel((string)($_POST['p'] ?? ''));
            $item = ($rel !== null && $rel !== "") ? resolve($rel) : null;
            $name = trim((string)($_POST['name'] ?? ''));
            if ($item === null) fail('Item not found.', 404);
            if (!valid_name($name, is_file($item))) fail('That name isn’t allowed.');
            $new = dirname($item) . '/' . $name;
            if (file_exists($new) && strcasecmp(basename($item), $name) !== 0) fail('A file or folder with that name already exists.');
            if (!@rename($item, $new)) fail('Could not rename the item.', 500);
            json_out(['ok' => true]);

        case 'delete':
            $rel  = clean_rel((string)($_POST['p'] ?? ''));
            $item = ($rel !== null && $rel !== "") ? resolve($rel) : null;
            if ($item === null) fail('Item not found.', 404);
            $ok = is_dir($item) ? rrmdir($item) : @unlink($item);
            if (!$ok) fail('Could not delete the item.', 500);
            json_out(['ok' => true]);

        case 'save':
            $rel  = clean_rel((string)($_POST['p'] ?? ''));
            $item = ($rel !== null && $rel !== "") ? resolve($rel) : null;
            $text = (string)($_POST['content'] ?? '');
            if ($item === null || !is_file($item) || kind_of($item) !== 'text') fail('That file can’t be edited.');
            if (strlen($text) > MAX_EDIT_SIZE) fail('The file is too large to save here.');
            if (file_put_contents($item, $text, LOCK_EX) === false) fail('Could not save the file.', 500);
            json_out(['ok' => true]);

        default:
            fail('Unknown action.');
    }
}

// ---- Actions (GET: stream / download) --------------------------------
if (in_array($action, ['raw', 'download', 'zip'], true)) {
    $item = resolve((string)($_GET['p'] ?? ''));
    if ($item === null) { http_response_code(404); exit('Not found.'); }
    if ($action === 'zip') {
        if (!is_dir($item)) { http_response_code(404); exit('Not a folder.'); }
        send_zip($item);
    }
    if (!is_file($item)) { http_response_code(404); exit('Not found.'); }
    if ($action === 'raw' && kind_of($item) === 'text' && filesize($item) > MAX_EDIT_SIZE * 4) { http_response_code(413); exit('File too large to preview.'); }
    send_file($item, $action === 'download');
}

// ---- Browse ----------------------------------------------------------
$notice = '';
$rel = clean_rel((string)($_GET['p'] ?? ''));
$dir = $rel === null ? null : resolve($rel);
if ($dir === null || !is_dir($dir)) {
    if (isset($_GET['p']) && $_GET['p'] !== '') $notice = 'That folder doesn’t exist, so you’re back at the top level.';
    $rel = '';
    $dir = root();
}

$items = [];
foreach (scandir($dir) ?: [] as $e) {
    if ($e[0] === '.') continue;
    $full = "$dir/$e";
    if (is_link($full)) continue;
    $isDir = is_dir($full);
    $items[] = [
        'name'  => $e,
        'rel'   => ltrim($rel . '/' . $e, '/'),
        'dir'   => $isDir,
        'size'  => $isDir ? count(array_filter(scandir($full) ?: [], fn($x) => $x[0] !== '.')) : (int)filesize($full),
        'mtime' => (int)filemtime($full),
        'kind'  => $isDir ? 'folder' : kind_of($e),
        'ext'   => $isDir ? '' : ext_of($e),
    ];
}
usort($items, fn($a, $b) => [$b['dir'], 0] <=> [$a['dir'], 0] ?: strnatcasecmp($a['name'], $b['name']));

$crumbs = [];
$acc = '';
foreach ($rel === '' ? [] : explode('/', $rel) as $seg) { $acc = ltrim("$acc/$seg", '/'); $crumbs[] = [$seg, $acc]; }
$parent = $rel === '' ? null : (strpos($rel, '/') === false ? '' : substr($rel, 0, (int)strrpos($rel, '/')));

function file_icon(string $ext): string {
    return '<svg class="fi" viewBox="0 0 32 40" aria-hidden="true"><path class="pg" d="M4 1h17l7 7v31H4z"/><path class="fold" d="M21 1v7h7"/><text x="16" y="31" text-anchor="middle">' . h(strtoupper(substr($ext, 0, 4))) . '</text></svg>';
}
const FOLDER_SVG = '<svg class="fo" viewBox="0 0 32 28" aria-hidden="true"><path d="M2 3.5C2 2.7 2.7 2 3.5 2h8.2c.5 0 1 .2 1.300.6L15 5h13.500c.8 0 1.500.7 1.500 1.500v17c0 .8-.7 1.500-1.500 1.500h-25C2.700 25 2 24.300 2 23.500z"/></svg>';

$limit = upload_limit();
$PREVIEWABLE = ['image', 'video', 'audio', 'pdf', 'text'];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Files<?= $rel !== '' ? ' — ' . h($rel) : '' ?></title>
<style><?= $CSS ?></style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 20h16"/></symbol>
  <symbol id="i-upload" viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4 4 4M4 20h16"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
  <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M4 20l4-1 11-11-3-3L5 16zM14 6l3 3"/></symbol>
  <symbol id="i-rename" viewBox="0 0 24 24"><path d="M9 4h6M12 4v16M9 20h6"/></symbol>
  <symbol id="i-folder-plus" viewBox="0 0 24 24"><path d="M3 6h6l2 2h10v11H3zM12 11v5M9.500 13.500h5"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="i-prev" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
  <symbol id="i-next" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
  <symbol id="i-up" viewBox="0 0 24 24"><path d="M5 12l7-7 7 7M12 5v15"/></symbol>
</svg>

<div class="bar">
  <nav class="crumbs" aria-label="Folder path">
    <?php if ($rel === ''): ?><span class="cur">Storage</span>
    <?php else: ?><a href="?p=">Storage</a>
      <?php foreach ($crumbs as $i => [$seg, $to]): ?>
        <span class="sep">/</span>
        <?php if ($i === count($crumbs) - 1): ?><span class="cur" aria-current="page"><?= h($seg) ?></span>
        <?php else: ?><a href="<?= h(browse_url($to)) ?>"><?= h($seg) ?></a><?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>
  <div class="tools">
    <input type="search" id="filter" placeholder="Filter this folder" aria-label="Filter this folder">
    <button class="btn" data-act="newfolder"><svg class="i"><use href="#i-folder-plus"/></svg>New folder</button>
    <button class="btn primary" data-act="upload"><svg class="i"><use href="#i-upload"/></svg>Upload files</button>
    <button class="btn" data-act="uploaddir"><svg class="i"><use href="#i-upload"/></svg>Upload folder</button>
    <?php if (APP_PASSWORD !== ''): ?><a class="btn" href="?a=logout&amp;t=<?= h($_SESSION['csrf']) ?>">Sign out</a><?php endif; ?>
  </div>
</div>

<main>
  <?php if ($notice): ?><p class="note"><?= h($notice) ?></p><?php endif; ?>
  <div class="panel">
    <table>
      <thead><tr><th>Name</th><th>Size</th><th class="m">Modified</th><th></th></tr></thead>
      <tbody id="rows">
        <?php if ($parent !== null): ?>
        <tr>
          <td class="n"><a href="<?= h(browse_url($parent)) ?>"><svg class="i" style="margin:0 .45rem 0 .3rem;color:var(--muted)"><use href="#i-up"/></svg><span>Parent folder</span></a></td>
          <td></td><td class="m"></td><td></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($items as $it): $prev = in_array($it['kind'], $PREVIEWABLE, true); ?>
        <tr data-name="<?= h($it['name']) ?>" data-rel="<?= h($it['rel']) ?>" data-kind="<?= h($it['kind']) ?>">
          <td class="n">
            <?php if ($it['dir']): ?>
              <a href="<?= h(browse_url($it['rel'])) ?>"><?= FOLDER_SVG ?><span><?= h($it['name']) ?></span></a>
            <?php else: ?>
              <a href="<?= h(act_url($prev ? 'raw' : 'download', $it['rel'])) ?>"<?= $prev ? ' data-open' : '' ?>><?= file_icon($it['ext']) ?><span><?= h($it['name']) ?></span></a>
            <?php endif; ?>
          </td>
          <td class="s"><?= $it['dir'] ? $it['size'] . ($it['size'] === 1 ? ' item' : ' items') : h(fmt_size($it['size'])) ?></td>
          <td class="m"><?= h(date('M j, Y H:i', $it['mtime'])) ?></td>
          <td class="a">
            <button class="icon-btn" data-act="download" title="<?= $it['dir'] ? 'Download as ZIP' : 'Download' ?>" aria-label="Download <?= h($it['name']) ?>"><svg class="i"><use href="#i-download"/></svg></button>
            <button class="icon-btn" data-act="rename" title="Rename" aria-label="Rename <?= h($it['name']) ?>"><svg class="i"><use href="#i-rename"/></svg></button>
            <?php if ($it['kind'] === 'text'): ?>
            <button class="icon-btn" data-act="edit" title="Edit" aria-label="Edit <?= h($it['name']) ?>"><svg class="i"><use href="#i-pencil"/></svg></button>
            <?php endif; ?>
            <button class="icon-btn del" data-act="delete" title="Delete" aria-label="Delete <?= h($it['name']) ?>"><svg class="i"><use href="#i-trash"/></svg></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$items): ?><div class="empty">This folder is empty. Drop files anywhere on the page or choose Upload files.</div><?php endif; ?>
  </div>
  <p class="foot">Upload limit per file: <?= $limit ? h(fmt_size($limit)) : 'none set' ?>. Drag files or whole folders onto the page to upload.</p>
</main>

<input type="file" id="pickFiles" multiple hidden>
<input type="file" id="pickDir" webkitdirectory multiple hidden>

<div id="drop"><div>Drop to upload to <?= h('/' . $rel) ?></div></div>
<div id="uploads" hidden aria-live="polite"></div>
<div id="toast" role="status"></div>

<!-- Prompt / confirm dialog -->
<dialog id="ask">
  <form method="dialog" class="dlg">
    <h2></h2>
    <p></p>
    <input type="text" id="askInput" autocomplete="off" spellcheck="false">
    <div class="row">
      <button type="button" class="btn" id="askCancel">Cancel</button>
      <button class="btn primary" value="ok" id="askOk">OK</button>
    </div>
  </form>
</dialog>

<!-- Preview dialog -->
<dialog id="pv" class="big" aria-labelledby="pvTitle">
  <header>
    <button class="icon-btn" id="pvPrev" title="Previous (←)" aria-label="Previous file"><svg class="i"><use href="#i-prev"/></svg></button>
    <button class="icon-btn" id="pvNext" title="Next (→)" aria-label="Next file"><svg class="i"><use href="#i-next"/></svg></button>
    <h2 id="pvTitle"></h2>
    <button class="btn" id="pvEdit" hidden><svg class="i"><use href="#i-pencil"/></svg>Edit</button>
    <a class="btn" id="pvDl"><svg class="i"><use href="#i-download"/></svg>Download</a>
    <button class="icon-btn" id="pvClose" title="Close (Esc)" aria-label="Close preview"><svg class="i"><use href="#i-close"/></svg></button>
  </header>
  <div id="stage"></div>
</dialog>

<!-- Editor dialog -->
<dialog id="ed" class="big" aria-labelledby="edTitle">
  <header>
    <h2 id="edTitle"></h2>
    <button class="icon-btn" id="edClose" title="Close" aria-label="Close editor"><svg class="i"><use href="#i-close"/></svg></button>
  </header>
  <textarea id="code" spellcheck="false" aria-label="File contents"></textarea>
  <footer>
    <span id="edStatus">Ctrl+S saves</span>
    <button class="btn primary" id="edSave">Save changes</button>
  </footer>
</dialog>

<script>
const CSRF = <?= json_encode($_SESSION['csrf'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const CUR  = <?= json_encode($rel, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $  = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const actUrl = (a, p) => `?a=${a}&p=${encodeURIComponent(p)}`;
const PREVIEWABLE = new Set(['image','video','audio','pdf','text']);

/* ---------- helpers ---------- */
let toastTimer;
function toast(msg, bad = false) {
  const t = $('#toast');
  t.textContent = msg; t.className = bad ? 'bad' : ''; t.style.display = 'block';
  clearTimeout(toastTimer); toastTimer = setTimeout(() => t.style.display = 'none', 3500);
}
async function post(action, data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch('?a=' + action, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': CSRF } });
  let j;
  try { j = await r.json(); } catch { throw new Error(r.status === 401 ? 'Your session expired. Reload and sign in again.' : `Unexpected server response (${r.status}).`); }
  if (!j.ok) throw new Error(j.error || 'Something went wrong.');
  return j;
}
function ask({ title, text = '', value, ok = 'OK', danger = false }) {
  return new Promise(resolve => {
    const d = $('#ask'), input = $('#askInput'), btn = $('#askOk');
    $('h2', d).textContent = title;
    $('p', d).textContent = text; $('p', d).hidden = !text;
    input.hidden = value === undefined; input.value = value ?? '';
    btn.textContent = ok; btn.className = 'btn ' + (danger ? 'danger' : 'primary');
    d.returnValue = '';
    d.onclose = () => resolve(d.returnValue === 'ok' ? (value === undefined ? true : input.value.trim()) : null);
    $('#askCancel').onclick = () => d.close();
    d.showModal();
    if (value !== undefined) { input.focus(); input.setSelectionRange(0, value.lastIndexOf('.') > 0 ? value.lastIndexOf('.') : value.length); }
  });
}

/* ---------- toolbar + row actions ---------- */
document.addEventListener('click', async e => {
  const open = e.target.closest('a[data-open]');
  if (open && !e.ctrlKey && !e.metaKey && !e.shiftKey && e.button === 0) {
    e.preventDefault(); openPreview(open.closest('tr').dataset.rel); return;
  }
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const tr = b.closest('tr');
  const rel = tr?.dataset.rel, name = tr?.dataset.name, isDir = tr?.dataset.kind === 'folder';
  try {
    switch (b.dataset.act) {
      case 'upload':    $('#pickFiles').click(); break;
      case 'uploaddir': $('#pickDir').click(); break;
      case 'newfolder': {
        const n = await ask({ title: 'New folder', text: 'It will be created in this folder.', value: '', ok: 'Create folder' });
        if (n) { await post('mkdir', { p: CUR, name: n }); location.reload(); }
        break;
      }
      case 'rename': {
        const n = await ask({ title: 'Rename', value: name, ok: 'Rename' });
        if (n && n !== name) { await post('rename', { p: rel, name: n }); location.reload(); }
        break;
      }
      case 'delete': {
        const ok = await ask({ title: `Delete “${name}”?`, text: isDir ? 'The folder and everything inside it will be deleted. This can’t be undone.' : 'This can’t be undone.', ok: 'Delete', danger: true });
        if (ok) { await post('delete', { p: rel }); location.reload(); }
        break;
      }
      case 'download': location.href = actUrl(isDir ? 'zip' : 'download', rel); break;
      case 'edit': openEditor(rel, name); break;
    }
  } catch (err) { toast(err.message, true); }
});

$('#filter').addEventListener('input', e => {
  const q = e.target.value.toLowerCase();
  $$('#rows tr[data-name]').forEach(tr => tr.hidden = !tr.dataset.name.toLowerCase().includes(q));
});

/* ---------- preview ---------- */
let plist = [], pidx = 0;
function openPreview(rel) {
  plist = $$('#rows tr[data-kind]').filter(r => PREVIEWABLE.has(r.dataset.kind) && !r.hidden);
  pidx = Math.max(0, plist.findIndex(r => r.dataset.rel === rel));
  showPreview();
  $('#pv').showModal();
}
async function showPreview() {
  const tr = plist[pidx], kind = tr.dataset.kind, rel = tr.dataset.rel, src = actUrl('raw', rel);
  const stage = $('#stage'); stage.replaceChildren();
  $('#pvTitle').textContent = tr.dataset.name;
  $('#pvDl').href = actUrl('download', rel);
  $('#pvEdit').hidden = kind !== 'text';
  $('#pvPrev').disabled = pidx === 0; $('#pvNext').disabled = pidx === plist.length - 1;
  let n;
  if (kind === 'image') { n = new Image(); n.src = src; n.alt = tr.dataset.name; }
  else if (kind === 'video' || kind === 'audio') {
    n = document.createElement(kind); n.controls = true; n.autoplay = true; n.src = src;
    if (kind === 'video') n.playsInline = true;
    n.onerror = () => toast('Your browser can’t play this format. Use Download instead.', true);
  }
  else if (kind === 'pdf') { n = document.createElement('iframe'); n.src = src; n.title = tr.dataset.name; }
  else {
    n = document.createElement('pre'); n.textContent = 'Loading…';
    fetch(src).then(r => r.ok ? r.text() : Promise.reject()).then(t => { n.textContent = t || '(empty file)'; }).catch(() => { n.textContent = 'Could not load this file.'; });
  }
  stage.append(n);
}
const step = d => { const i = pidx + d; if (i >= 0 && i < plist.length) { pidx = i; showPreview(); } };
$('#pvPrev').onclick = () => step(-1);
$('#pvNext').onclick = () => step(1);
$('#pvClose').onclick = () => $('#pv').close();
$('#pv').addEventListener('close', () => $('#stage').replaceChildren());
$('#pvEdit').onclick = () => { const tr = plist[pidx]; $('#pv').close(); openEditor(tr.dataset.rel, tr.dataset.name); };
document.addEventListener('keydown', e => {
  if (!$('#pv').open || ['VIDEO','AUDIO','INPUT'].includes(e.target.tagName)) return;
  if (e.key === 'ArrowLeft') step(-1);
  if (e.key === 'ArrowRight') step(1);
});

/* ---------- editor ---------- */
let edRel = '', edDirty = false, edSaved = false;
async function openEditor(rel, name) {
  try {
    const r = await fetch(actUrl('raw', rel));
    if (!r.ok) throw new Error('Could not open this file.');
    $('#code').value = await r.text();
  } catch (err) { toast(err.message, true); return; }
  edRel = rel; edDirty = false; edSaved = false;
  $('#edTitle').textContent = name; $('#edStatus').textContent = 'Ctrl+S saves';
  $('#ed').showModal(); $('#code').focus();
}
async function saveEditor() {
  try {
    await post('save', { p: edRel, content: $('#code').value });
    edDirty = false; edSaved = true; $('#edStatus').textContent = 'Saved'; toast('Saved');
  } catch (err) { toast(err.message, true); }
}
$('#code').addEventListener('input', () => { edDirty = true; $('#edStatus').textContent = 'Unsaved changes'; });
$('#edSave').onclick = saveEditor;
$('#edClose').onclick = () => $('#ed').close();
$('#ed').addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveEditor(); } });
$('#ed').addEventListener('cancel', e => { if (edDirty && !confirm('Close without saving your changes?')) e.preventDefault(); });
$('#ed').addEventListener('close', () => { if (edSaved) location.reload(); });
$('#edClose').addEventListener('click', e => { if (edDirty && !confirm('Close without saving your changes?')) { e.stopImmediatePropagation(); } }, true);

/* ---------- uploads ---------- */
function xhrUpload({ file, rel }, onProgress) {
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();
    x.open('POST', '?a=upload');
    x.setRequestHeader('X-CSRF-Token', CSRF);
    x.upload.onprogress = e => e.lengthComputable && onProgress(e.loaded / e.total * 100);
    x.onload = () => {
      let j; try { j = JSON.parse(x.responseText); } catch { return reject(new Error(`Server error (${x.status}).`)); }
      j.ok ? resolve() : reject(new Error(j.error));
    };
    x.onerror = () => reject(new Error('Network error.'));
    const fd = new FormData();
    fd.append('p', CUR); fd.append('rel', rel || ''); fd.append('file', file);
    x.send(fd);
  });
}
let uploading = false;
async function uploadAll(list) {
  if (!list.length || uploading) return;
  uploading = true;
  const box = $('#uploads'); box.hidden = false; box.replaceChildren();
  let failed = 0;
  for (const it of list) {
    const row = document.createElement('div'); row.className = 'up';
    row.innerHTML = '<div class="t"><b></b><span></span></div><div class="bar2"><i></i></div><div class="msg"></div>';
    $('b', row).textContent = (it.rel ? it.rel + '/' : '') + it.file.name;
    box.append(row); row.scrollIntoView({ block: 'nearest' });
    try {
      await xhrUpload(it, p => { $('i', row).style.width = p + '%'; $('span', row).textContent = Math.round(p) + '%'; });
      $('span', row).textContent = 'Done';
    } catch (err) { failed++; row.classList.add('bad'); $('.msg', row).textContent = err.message; $('span', row).textContent = 'Failed'; }
  }
  uploading = false;
  if (!failed) location.reload();
  else { const b = document.createElement('button'); b.className = 'btn'; b.textContent = 'Reload folder'; b.onclick = () => location.reload(); b.style.marginTop = '.5rem'; box.append(b); }
}
$('#pickFiles').addEventListener('change', e => { uploadAll([...e.target.files].map(file => ({ file, rel: '' }))); e.target.value = ''; });
$('#pickDir').addEventListener('change', e => {
  uploadAll([...e.target.files].map(file => ({ file, rel: file.webkitRelativePath.split('/').slice(0, -1).join('/') })));
  e.target.value = '';
});

/* drag & drop (files and folders) */
async function walk(entry, parent = '') {
  if (entry.isFile) return [{ file: await new Promise((res, rej) => entry.file(res, rej)), rel: parent }];
  const here = parent ? parent + '/' + entry.name : entry.name, out = [], reader = entry.createReader();
  for (;;) {
    const batch = await new Promise((res, rej) => reader.readEntries(res, rej));
    if (!batch.length) break;
    for (const c of batch) out.push(...await walk(c, here));
  }
  return out;
}
let dragDepth = 0;
const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
addEventListener('dragenter', e => { if (hasFiles(e)) { dragDepth++; document.body.classList.add('dragging'); } });
addEventListener('dragleave', e => { if (hasFiles(e) && --dragDepth <= 0) { dragDepth = 0; document.body.classList.remove('dragging'); } });
addEventListener('dragover', e => { if (hasFiles(e)) e.preventDefault(); });
addEventListener('drop', async e => {
  if (!hasFiles(e)) return;
  e.preventDefault(); dragDepth = 0; document.body.classList.remove('dragging');
  const entries = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry?.()).filter(Boolean);
  try {
    const list = entries.length ? (await Promise.all(entries.map(en => walk(en)))).flat() : [...e.dataTransfer.files].map(file => ({ file, rel: '' }));
    uploadAll(list);
  } catch { toast('Could not read the dropped items.', true); }
});
</script>
</body>
</html>