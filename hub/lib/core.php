<?php
// Core: config, encryption, DB, auth helpers, platforms, publishers, OAuth, autopilot, engine.

define('HUB_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', HUB_ROOT . '/uploads');

function cfg(string $k = null) {
    static $c;
    if ($c === null) {
        $f = HUB_ROOT . '/config.php';
        $c = is_file($f) ? require $f : [];
    }
    return $k === null ? $c : ($c[$k] ?? null);
}

// Keep the database outside the web root if you can:  'data_dir' => '/home/USER/hub_data'  in config.php
define('DATA_DIR', rtrim((string)cfg('data_dir'), '/\\') ?: HUB_ROOT . '/data');

function base_url(): string {
    if ($u = cfg('base_url')) return rtrim($u, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
}

function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
function uid(): int { return (int)($_SESSION['uid'] ?? 0); }

// ---------- Encryption at rest (tokens/keys) ----------
function secret_key(): string {
    static $k;
    if ($k) return $k;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $f = DATA_DIR . '/secret.key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
    return $k = hex2bin(trim(file_get_contents($f)));
}
function enc(string $s): string {
    $iv = random_bytes(12);
    $c = openssl_encrypt($s, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}
function dec(string $s): string {
    if (!str_starts_with($s, 'v1:')) return $s;
    $r = base64_decode(substr($s, 3));
    $p = openssl_decrypt(substr($r, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($r, 0, 12), substr($r, 12, 16));
    if ($p === false) throw new RuntimeException('Cannot decrypt stored secret (was data/secret.key changed?)');
    return $p;
}

// ---------- DB ----------
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $pdo = new PDO('sqlite:' . DATA_DIR . '/hub.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, pass TEXT NOT NULL,
        gemini_key TEXT, created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS attempts(k TEXT NOT NULL, ts INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS brands(
        id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        name TEXT NOT NULL, website TEXT, logo TEXT,
        ap_enabled INTEGER NOT NULL DEFAULT 0, ap_mode TEXT NOT NULL DEFAULT 'review',
        ap_days TEXT NOT NULL DEFAULT '1,2,3,4,5', ap_time TEXT NOT NULL DEFAULT '09:00', ap_tz TEXT NOT NULL DEFAULT 'UTC',
        ap_about TEXT NOT NULL DEFAULT '', ap_tone TEXT NOT NULL DEFAULT 'friendly, professional',
        ap_topics TEXT NOT NULL DEFAULT '', ap_lang TEXT NOT NULL DEFAULT 'English', ap_image INTEGER NOT NULL DEFAULT 0,
        ap_last TEXT, ap_retry INTEGER NOT NULL DEFAULT 0, ap_msg TEXT);
    CREATE TABLE IF NOT EXISTS accounts(
        id INTEGER PRIMARY KEY, brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
        platform TEXT NOT NULL, label TEXT, creds TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS posts(
        id INTEGER PRIMARY KEY, brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
        body TEXT NOT NULL DEFAULT '', link TEXT, media TEXT, origin TEXT NOT NULL DEFAULT 'manual',
        scheduled_at INTEGER, status TEXT NOT NULL DEFAULT 'draft', created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS deliveries(
        id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
        next_try INTEGER NOT NULL DEFAULT 0, result TEXT, sent_at INTEGER);
    CREATE TABLE IF NOT EXISTS sources(
        id INTEGER PRIMARY KEY, brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
        url TEXT NOT NULL, mode TEXT NOT NULL DEFAULT 'draft', last_check INTEGER NOT NULL DEFAULT 0,
        initialized INTEGER NOT NULL DEFAULT 0, last_msg TEXT);
    CREATE TABLE IF NOT EXISTS source_items(source_id INTEGER NOT NULL, guid TEXT NOT NULL, PRIMARY KEY(source_id, guid));
    CREATE INDEX IF NOT EXISTS idx_posts_due ON posts(status, scheduled_at);
    ");
    foreach (['ai_provider', 'ai_model', 'ai_base', 'ai2_provider', 'ai2_model', 'ai2_base', 'ai2_key'] as $col) {   // upgrade older databases
        try { $pdo->exec("ALTER TABLE users ADD COLUMN $col TEXT"); } catch (Throwable $e) {}
    }
    studio_migrate($pdo);
    return $pdo;
}

// ---------- Ownership helpers (every API call goes through these) ----------
function one(string $sql, array $args): array {
    $s = db()->prepare($sql); $s->execute($args);
    $r = $s->fetch();
    if (!$r) throw new HttpError('Not found', 404);
    return $r;
}
class HttpError extends RuntimeException {}
function own_brand(int $id): array { return one('SELECT * FROM brands WHERE id=? AND user_id=?', [$id, uid()]); }
function own_account(int $id): array {
    return one('SELECT a.* FROM accounts a JOIN brands b ON b.id=a.brand_id WHERE a.id=? AND b.user_id=?', [$id, uid()]);
}
function own_post(int $id): array {
    return one('SELECT p.* FROM posts p JOIN brands b ON b.id=p.brand_id WHERE p.id=? AND b.user_id=?', [$id, uid()]);
}
function own_source(int $id): array {
    return one('SELECT s.* FROM sources s JOIN brands b ON b.id=s.brand_id WHERE s.id=? AND b.user_id=?', [$id, uid()]);
}

// ---------- Credentials ----------
function creds_of(array $acc): array {
    return $acc['creds'] === '' ? [] : (json_decode(dec($acc['creds']), true) ?: []);
}
function set_creds(int $accId, array $c): void {
    db()->prepare('UPDATE accounts SET creds=? WHERE id=?')->execute([enc(json_encode($c)), $accId]);
}

/** Platform definitions drive the manual-key form and readiness checks. */
function platforms(): array {
    return [
        'telegram' => ['label' => 'Telegram channel/group', 'fields' => [
            ['bot_token', 'Bot token (from @BotFather)', true], ['chat_id', 'Chat ID or @channelname', false]]],
        'facebook' => ['label' => 'Facebook Page', 'fields' => [
            ['page_id', 'Page ID', false], ['page_token', 'Page access token', true]]],
        'instagram' => ['label' => 'Instagram (image required)', 'fields' => [
            ['ig_user_id', 'Instagram business account ID', false], ['token', 'Access token', true]]],
        'linkedin' => ['label' => 'LinkedIn (text + link)', 'fields' => [
            ['access_token', 'Access token', true], ['author', 'Author URN (urn:li:person:… / urn:li:organization:…)', false]]],
        'x' => ['label' => 'X / Twitter (text only)', 'fields' => [
            ['api_key', 'API key', true], ['api_secret', 'API secret', true],
            ['access_token', 'Access token', true], ['access_secret', 'Access token secret', true]]],
        'discord' => ['label' => 'Discord webhook', 'fields' => [['webhook', 'Webhook URL', true]]],
    ];
}

function acc_state(array $acc): string {
    $pf = platforms()[$acc['platform']] ?? null;
    if (!$pf) return 'keys';
    $c = creds_of($acc);
    if (!empty($c['expires_at']) && $c['expires_at'] < time()) return 'expired';
    if ($acc['platform'] === 'x' && !empty($c['x_access'])) return 'ready';
    foreach ($pf['fields'] as [$k]) if (trim($c[$k] ?? '') === '') return 'keys';
    return 'ready';
}
function acc_ready(array $acc): bool { return acc_state($acc) === 'ready'; }

// ---------- HTTP ----------
function http(string $method, string $url, array $headers = [], $body = null, bool $follow = true): array {
    if (isset($GLOBALS['HTTP_MOCK'])) return $GLOBALS['HTTP_MOCK']($method, $url, $headers, $body); // test hook
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => $follow,
    ]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) throw new RuntimeException("Network error: $err");
    return ['code' => $code, 'json' => json_decode($res, true), 'raw' => $res];
}

function must_ok(array $r, string $what): array {
    if ($r['code'] < 200 || $r['code'] >= 300) {
        $j = $r['json'];
        $msg = $j['error']['message'] ?? (is_string($j['error'] ?? null) ? $j['error'] : null)
            ?? $j['error_description'] ?? $j['description'] ?? $j['message'] ?? $j['detail'] ?? substr($r['raw'], 0, 300);
        throw new RuntimeException("$what failed (HTTP {$r['code']}): $msg");
    }
    return $r['json'] ?? [];
}

// ---------- Publishers: each returns a short result string or throws ----------
function pub_telegram(array $c, array $p): string {
    $api = "https://api.telegram.org/bot{$c['bot_token']}/";
    if ($p['file']) {
        $r = http('POST', $api . 'sendPhoto', [], ['chat_id' => $c['chat_id'], 'caption' => mb_substr(trim($p['text']), 0, 1024),
            'photo' => new CURLFile($p['file'])]);
    } else {
        $r = http('POST', $api . 'sendMessage', [], ['chat_id' => $c['chat_id'], 'text' => $p['text']]);
    }
    $j = must_ok($r, 'Telegram');
    return 'message ' . ($j['result']['message_id'] ?? '?');
}

function graph(): string { return 'https://graph.facebook.com/' . (cfg('graph_version') ?: 'v21.0') . '/'; }

function pub_facebook(array $c, array $p): string {
    $g = graph() . "{$c['page_id']}/";
    if ($p['file']) {
        $r = http('POST', $g . 'photos', [], ['caption' => $p['text'], 'source' => new CURLFile($p['file']), 'access_token' => $c['page_token']]);
    } else {
        $f = ['message' => $p['text'], 'access_token' => $c['page_token']];
        if ($p['link']) $f['link'] = $p['link'];
        $r = http('POST', $g . 'feed', [], $f);
    }
    $j = must_ok($r, 'Facebook');
    return 'post ' . ($j['post_id'] ?? $j['id'] ?? '?');
}

function pub_instagram(array $c, array $p): string {
    if (!$p['url']) throw new RuntimeException('Instagram needs an image.');
    $g = graph() . "{$c['ig_user_id']}/";
    $j = must_ok(http('POST', $g . 'media', [], ['image_url' => $p['url'], 'caption' => $p['text'], 'access_token' => $c['token']]), 'Instagram container');
    $cid = $j['id'];
    for ($i = 0; $i < 10; $i++) {
        $s = http('GET', graph() . "$cid?fields=status_code&access_token=" . urlencode($c['token']));
        $st = $s['json']['status_code'] ?? 'FINISHED';
        if ($st === 'FINISHED') break;
        if ($st === 'ERROR') throw new RuntimeException('Instagram rejected the image (use JPG, ratio 4:5 to 1.91:1).');
        if (!isset($GLOBALS['HTTP_MOCK'])) sleep(2);
    }
    $j = must_ok(http('POST', $g . 'media_publish', [], ['creation_id' => $cid, 'access_token' => $c['token']]), 'Instagram publish');
    return 'media ' . ($j['id'] ?? '?');
}

function pub_linkedin(array $c, array $p): string {
    $content = ['shareCommentary' => ['text' => $p['text']], 'shareMediaCategory' => 'NONE'];
    $auth = 'Authorization: Bearer ' . $c['access_token'];
    if ($p['file']) {        // image post: register an upload, PUT the bytes, then attach the asset
        $reg = must_ok(http('POST', 'https://api.linkedin.com/v2/assets?action=registerUpload', [$auth, 'Content-Type: application/json',
            'X-Restli-Protocol-Version: 2.0.0'], json_encode(['registerUploadRequest' => ['recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
            'owner' => $c['author'], 'serviceRelationships' => [['relationshipType' => 'OWNER', 'identifier' => 'urn:li:userGeneratedContent']]]])), 'LinkedIn upload');
        $up = $reg['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? '';
        $asset = $reg['value']['asset'] ?? '';
        if (!$up || !$asset) throw new RuntimeException('LinkedIn did not return an upload URL');
        must_ok(http('PUT', $up, [$auth, 'Content-Type: application/octet-stream'], file_get_contents($p['file'])), 'LinkedIn image');
        $content['shareMediaCategory'] = 'IMAGE';
        $content['media'] = [['status' => 'READY', 'media' => $asset]];
    } elseif ($p['link']) {
        $content['shareMediaCategory'] = 'ARTICLE';
        $content['media'] = [['status' => 'READY', 'originalUrl' => $p['link']]];
    }
    $body = ['author' => $c['author'], 'lifecycleState' => 'PUBLISHED',
        'specificContent' => ['com.linkedin.ugc.ShareContent' => $content],
        'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC']];
    must_ok(http('POST', 'https://api.linkedin.com/v2/ugcPosts', [
        'Authorization: Bearer ' . $c['access_token'], 'Content-Type: application/json',
        'X-Restli-Protocol-Version: 2.0.0'], json_encode($body)), 'LinkedIn');
    return 'posted';
}

function x_token_request(array $f): array {
    $id = cfg('x_client_id'); $sec = cfg('x_client_secret');
    $h = ['Content-Type: application/x-www-form-urlencoded'];
    $f['client_id'] = $id;
    if ($sec) $h[] = 'Authorization: Basic ' . base64_encode("$id:$sec");
    return must_ok(http('POST', 'https://api.twitter.com/2/oauth2/token', $h, http_build_query($f)), 'X token');
}

function x_access_token(array $c, int $accId): string {
    if (($c['x_expires'] ?? 0) > time() + 60) return $c['x_access'];
    if (empty($c['x_refresh'])) throw new RuntimeException('X connection expired — reconnect the account.');
    $t = x_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $c['x_refresh']]);
    $c['x_access'] = $t['access_token'];
    $c['x_refresh'] = $t['refresh_token'] ?? $c['x_refresh'];
    $c['x_expires'] = time() + (int)($t['expires_in'] ?? 7200);
    set_creds($accId, $c);   // X rotates refresh tokens, so persist immediately
    return $c['x_access'];
}

function pub_x(array $c, array $p): string {
    $url = 'https://api.twitter.com/2/tweets';
    $text = $p['text'] . ($p['link'] ? "\n" . $p['link'] : '');
    if (mb_strlen($text) > 280) $text = mb_substr($text, 0, 277) . '...';
    if (!empty($c['x_access'])) {                       // OAuth 2.0 (Connect button)
        $h = ['Authorization: Bearer ' . x_access_token($c, $p['acc']), 'Content-Type: application/json'];
    } else {                                            // OAuth 1.0a (manual keys)
        $o = ['oauth_consumer_key' => $c['api_key'], 'oauth_nonce' => bin2hex(random_bytes(12)),
            'oauth_signature_method' => 'HMAC-SHA1', 'oauth_timestamp' => (string)time(),
            'oauth_token' => $c['access_token'], 'oauth_version' => '1.0'];
        $enc = fn($s) => rawurlencode((string)$s);
        ksort($o);
        $pairs = [];
        foreach ($o as $k => $v) $pairs[] = $enc($k) . '=' . $enc($v);
        $base = 'POST&' . $enc($url) . '&' . $enc(implode('&', $pairs));
        $o['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $enc($c['api_secret']) . '&' . $enc($c['access_secret']), true));
        $hdr = [];
        foreach ($o as $k => $v) $hdr[] = $enc($k) . '="' . $enc($v) . '"';
        $h = ['Authorization: OAuth ' . implode(', ', $hdr), 'Content-Type: application/json'];
    }
    $j = must_ok(http('POST', $url, $h, json_encode(['text' => $text])), 'X');
    return 'tweet ' . ($j['data']['id'] ?? '?');
}

function pub_discord(array $c, array $p): string {
    $text = $p['text'] . ($p['link'] ? "\n" . $p['link'] : '');
    if ($p['file']) $r = http('POST', $c['webhook'], [], ['content' => $text, 'file' => new CURLFile($p['file'])]);
    else $r = http('POST', $c['webhook'], ['Content-Type: application/json'], json_encode(['content' => $text]));
    must_ok($r, 'Discord');
    return 'posted';
}

// ---------- OAuth "Connect" flows ----------
function oauth_providers(): array {
    $o = [];
    if (cfg('meta_app_id') && cfg('meta_app_secret')) $o['meta'] = 'Facebook & Instagram';
    if (cfg('linkedin_client_id') && cfg('linkedin_client_secret')) $o['linkedin'] = 'LinkedIn';
    if (cfg('x_client_id')) $o['x'] = 'X (Twitter)';
    return $o;
}
function oauth_redirect(): string { return base_url() . '/oauth.php'; }   // register this exact URL in every provider

function oauth_start(string $p, int $brandId): string {
    own_brand($brandId);
    if (!isset(oauth_providers()[$p])) throw new RuntimeException('That provider is not configured on this server.');
    $state = bin2hex(random_bytes(16));
    $ver = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $_SESSION['oauth'] = ['state' => $state, 'p' => $p, 'brand' => $brandId, 'ver' => $ver];
    $ru = oauth_redirect();
    if ($p === 'meta') return 'https://www.facebook.com/' . (cfg('graph_version') ?: 'v21.0') . '/dialog/oauth?' . http_build_query([
        'client_id' => cfg('meta_app_id'), 'redirect_uri' => $ru, 'state' => $state,
        'scope' => 'pages_show_list,pages_manage_posts,pages_read_engagement,instagram_basic,instagram_content_publish']);
    if ($p === 'linkedin') return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query([
        'response_type' => 'code', 'client_id' => cfg('linkedin_client_id'), 'redirect_uri' => $ru, 'state' => $state,
        'scope' => 'openid profile w_member_social' . (cfg('linkedin_org') ? ' r_organization_admin w_organization_social' : '')]);
    return 'https://twitter.com/i/oauth2/authorize?' . http_build_query([
        'response_type' => 'code', 'client_id' => cfg('x_client_id'), 'redirect_uri' => $ru, 'state' => $state,
        'scope' => 'tweet.read tweet.write users.read offline.access',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $ver, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']);
}

/** Create or update an account row. Re-connecting refreshes tokens instead of duplicating. */
function upsert_account(int $brand, string $platform, string $label, array $creds, string $matchKey): void {
    $q = db()->prepare('SELECT * FROM accounts WHERE brand_id=? AND platform=?');
    $q->execute([$brand, $platform]);
    $rows = $q->fetchAll();
    foreach ($rows as $a) {
        $old = creds_of($a);
        if (($old[$matchKey] ?? null) === $creds[$matchKey]) {
            set_creds($a['id'], array_merge($old, $creds));
            db()->prepare('UPDATE accounts SET label=? WHERE id=?')->execute([$label, $a['id']]);
            return;
        }
    }
    foreach ($rows as $a) {        // fill an empty placeholder made by Quick start
        if (acc_state($a) === 'keys' && !array_filter(creds_of($a), fn($v, $k) => $k !== 'chat_id' && $v !== '', ARRAY_FILTER_USE_BOTH)) {
            set_creds($a['id'], $creds);
            db()->prepare('UPDATE accounts SET label=? WHERE id=?')->execute([$label, $a['id']]);
            return;
        }
    }
    db()->prepare('INSERT INTO accounts(brand_id,platform,label,creds) VALUES(?,?,?,?)')
        ->execute([$brand, $platform, $label, enc(json_encode($creds))]);
}

function oauth_finish(string $code): string {
    $o = $_SESSION['oauth'] ?? null;
    if (!$o) throw new RuntimeException('Connection session expired. Start again.');
    unset($_SESSION['oauth']);
    $brand = own_brand($o['brand'])['id'];
    $ru = oauth_redirect();
    $names = [];
    if ($o['p'] === 'meta') {
        $v = cfg('graph_version') ?: 'v21.0';
        $t = must_ok(http('GET', "https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
            'client_id' => cfg('meta_app_id'), 'client_secret' => cfg('meta_app_secret'), 'redirect_uri' => $ru, 'code' => $code])), 'Meta token');
        $l = must_ok(http('GET', "https://graph.facebook.com/$v/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token', 'client_id' => cfg('meta_app_id'), 'client_secret' => cfg('meta_app_secret'),
            'fb_exchange_token' => $t['access_token']])), 'Meta long-lived token');
        $pages = must_ok(http('GET', "https://graph.facebook.com/$v/me/accounts?" . http_build_query([
            'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100,
            'access_token' => $l['access_token']])), 'Meta pages')['data'] ?? [];
        foreach ($pages as $pg) {   // page tokens derived from a long-lived user token do not expire
            upsert_account($brand, 'facebook', $pg['name'], ['page_id' => $pg['id'], 'page_token' => $pg['access_token']], 'page_id');
            $names[] = 'Facebook: ' . $pg['name'];
            if (!empty($pg['instagram_business_account']['id'])) {
                $ig = $pg['instagram_business_account'];
                upsert_account($brand, 'instagram', '@' . ($ig['username'] ?? $ig['id']), ['ig_user_id' => $ig['id'], 'token' => $pg['access_token']], 'ig_user_id');
                $names[] = 'Instagram: @' . ($ig['username'] ?? $ig['id']);
            }
        }
        if (!$pages) throw new RuntimeException('No Facebook Pages found for that login (you must manage a Page).');
    } elseif ($o['p'] === 'linkedin') {
        $t = must_ok(http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $ru,
                'client_id' => cfg('linkedin_client_id'), 'client_secret' => cfg('linkedin_client_secret')])), 'LinkedIn token');
        $auth = ['Authorization: Bearer ' . $t['access_token']];
        $me = must_ok(http('GET', 'https://api.linkedin.com/v2/userinfo', $auth), 'LinkedIn profile');
        $exp = time() + (int)($t['expires_in'] ?? 5184000);
        upsert_account($brand, 'linkedin', $me['name'] ?? 'LinkedIn', ['access_token' => $t['access_token'],
            'author' => 'urn:li:person:' . $me['sub'], 'expires_at' => $exp], 'author');
        $names[] = 'LinkedIn: ' . ($me['name'] ?? 'profile');
        if (cfg('linkedin_org')) {  // company pages (needs LinkedIn Community Management API access)
            try {
                $orgs = http('GET', 'https://api.linkedin.com/v2/organizationAcls?q=roleAssignee&role=ADMINISTRATOR&state=APPROVED', $auth + [1 => 'X-Restli-Protocol-Version: 2.0.0'])['json']['elements'] ?? [];
                foreach ($orgs as $e) {
                    $urn = $e['organization'];
                    upsert_account($brand, 'linkedin', 'Company ' . basename($urn), ['access_token' => $t['access_token'], 'author' => $urn, 'expires_at' => $exp], 'author');
                    $names[] = 'LinkedIn company ' . basename($urn);
                }
            } catch (Throwable $e) {}
        }
    } else { // x
        $t = x_token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $ru, 'code_verifier' => $o['ver']]);
        $me = must_ok(http('GET', 'https://api.twitter.com/2/users/me', ['Authorization: Bearer ' . $t['access_token']]), 'X profile');
        $u = $me['data']['username'] ?? 'x';
        upsert_account($brand, 'x', '@' . $u, ['x_access' => $t['access_token'], 'x_refresh' => $t['refresh_token'] ?? '',
            'x_expires' => time() + (int)($t['expires_in'] ?? 7200), 'x_user' => $u], 'x_user');
        $names[] = 'X: @' . $u;
    }
    return implode(', ', $names);
}

// ---------- Delivery engine ----------
function deliver(array $d, array $post): array {
    $acc = db()->prepare('SELECT * FROM accounts WHERE id=?');
    $acc->execute([$d['account_id']]);
    $acc = $acc->fetch();
    if (!$acc) return [false, 'Account was deleted'];
    $fn = 'pub_' . $acc['platform'];
    if (!function_exists($fn)) return [false, 'Unknown platform'];
    $v = json_decode((string)($post['variants'] ?? ''), true) ?: [];
    $text = trim((string)($v[$acc['platform']] ?? '')) ?: $post['body'];
    $link = $post['link'] ?: null;
    $file = $post['media'] ? UPLOAD_DIR . '/' . $post['media'] : null;
    $studio = in_array($post['origin'] ?? '', ['campaign', 'youtube']);
    if ($link && $acc['platform'] === 'instagram' && $studio) $link = null;    // not clickable on Instagram; captions say "link in bio"
    elseif ($link && (in_array($acc['platform'], ['telegram', 'instagram']) || ($file && in_array($acc['platform'], ['facebook', 'linkedin'])))) {
        if (!str_contains($text, $link)) $text .= "\n\n" . $link;          // photo posts have no link field, so put it in the text
        $link = null;
    }
    $payload = ['text' => $text, 'link' => $link, 'file' => ($file && is_file($file)) ? $file : null, 'acc' => (int)$acc['id'],
        'url' => $post['media'] ? base_url() . '/uploads/' . rawurlencode($post['media']) : null];
    try {
        return [true, $fn(creds_of($acc), $payload)];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

function run_due(?int $onlyPost = null): int {
    $pdo = db();
    $now = time();
    $sql = "SELECT d.*, p.id AS pid FROM deliveries d JOIN posts p ON p.id=d.post_id
            WHERE d.status='pending' AND d.next_try<=? AND p.status IN('scheduled','publishing')
            AND p.scheduled_at<=?" . ($onlyPost ? ' AND p.id=' . (int)$onlyPost : '') . ' ORDER BY p.scheduled_at LIMIT 50';
    $st = $pdo->prepare($sql);
    $st->execute([$now, $now]);
    $rows = $st->fetchAll();
    $touched = [];
    foreach ($rows as $d) {
        $post = $pdo->query('SELECT * FROM posts WHERE id=' . (int)$d['pid'])->fetch();
        $pdo->prepare("UPDATE posts SET status='publishing' WHERE id=?")->execute([$post['id']]);
        [$ok, $msg] = deliver($d, $post);
        $att = $d['attempts'] + 1;
        if ($ok) {
            $pdo->prepare("UPDATE deliveries SET status='sent',attempts=?,result=?,sent_at=? WHERE id=?")->execute([$att, $msg, time(), $d['id']]);
        } elseif ($att >= (int)(cfg('max_attempts') ?: 3)) {
            $pdo->prepare("UPDATE deliveries SET status='failed',attempts=?,result=? WHERE id=?")->execute([$att, $msg, $d['id']]);
        } else {
            $pdo->prepare("UPDATE deliveries SET attempts=?,result=?,next_try=? WHERE id=?")
                ->execute([$att, $msg, time() + 60 * (int)(cfg('retry_minutes') ?: 5), $d['id']]);
        }
        $touched[$post['id']] = true;
    }
    foreach (array_keys($touched) as $pid) refresh_post_status($pid);
    return count($rows);
}

function refresh_post_status(int $pid): void {
    $pdo = db();
    $c = $pdo->query("SELECT status, COUNT(*) n FROM deliveries WHERE post_id=$pid GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = $c['pending'] ?? 0; $sent = $c['sent'] ?? 0; $failed = $c['failed'] ?? 0;
    $s = $pending ? 'scheduled' : ($failed && $sent ? 'partial' : ($failed ? 'failed' : 'done'));
    $pdo->prepare('UPDATE posts SET status=? WHERE id=?')->execute([$s, $pid]);
}

// ---------- AI + Autopilot ----------
/** Providers. 'compat' = OpenAI-compatible /chat/completions. Model names change often: users can edit them in Settings. */
function ai_providers(): array {
    return [
        'gemini'     => ['label' => 'Google Gemini (free tier)', 'model' => cfg('gemini_model') ?: 'gemini-2.5-flash'],
        'claude'     => ['label' => 'Anthropic Claude (paid API)', 'model' => cfg('claude_model') ?: 'claude-haiku-5-5'],
        'openai'     => ['label' => 'OpenAI (paid)', 'model' => 'gpt-4o-mini', 'base' => 'https://api.openai.com/v1'],
        'groq'       => ['label' => 'Groq (free tier, very fast)', 'model' => 'llama-3.3-70b-versatile', 'base' => 'https://api.groq.com/openai/v1'],
        'openrouter' => ['label' => 'OpenRouter (many models, some free)', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'base' => 'https://openrouter.ai/api/v1'],
        'mistral'    => ['label' => 'Mistral (free tier)', 'model' => 'mistral-small-latest', 'base' => 'https://api.mistral.ai/v1'],
        'deepseek'   => ['label' => 'DeepSeek (cheap)', 'model' => 'deepseek-chat', 'base' => 'https://api.deepseek.com/v1'],
        'custom'     => ['label' => 'Custom OpenAI-compatible URL', 'model' => '', 'base' => ''],
    ];
}

/** Effective AI settings for a user: their own, else the server key. Returns [] if none. */
function ai_conf(int $userId): array {
    $q = db()->prepare('SELECT gemini_key, ai_provider, ai_model, ai_base FROM users WHERE id=?'); $q->execute([$userId]);
    $u = $q->fetch() ?: [];
    $key = !empty($u['gemini_key']) ? dec($u['gemini_key']) : (string)cfg('gemini_key');
    if ($key === '') return [];
    $prov = $u['ai_provider'] ?? '';
    if (!isset(ai_providers()[$prov])) $prov = str_starts_with($key, 'sk-ant-') ? 'claude' : 'gemini';   // auto-detect
    $d = ai_providers()[$prov];
    return ['provider' => $prov, 'key' => $key, 'model' => ($u['ai_model'] ?? '') ?: $d['model'],
            'base' => rtrim(($u['ai_base'] ?? '') ?: ($d['base'] ?? ''), '/')];
}
function ai_key(int $userId): string { return ai_conf($userId)['key'] ?? ''; }

/** The user's optional backup provider (used when the main one errors or hits its limit). */
function ai_conf2(int $userId): array {
    $q = db()->prepare('SELECT ai2_provider, ai2_model, ai2_base, ai2_key FROM users WHERE id=?'); $q->execute([$userId]);
    $u = $q->fetch() ?: [];
    if (empty($u['ai2_key']) || !isset(ai_providers()[$u['ai2_provider'] ?? ''])) return [];
    $d = ai_providers()[$u['ai2_provider']];
    return ['provider' => $u['ai2_provider'], 'key' => dec($u['ai2_key']), 'model' => ($u['ai2_model'] ?? '') ?: $d['model'],
            'base' => rtrim(($u['ai2_base'] ?? '') ?: ($d['base'] ?? ''), '/')];
}

/** Run a prompt on the main AI; if it fails (rate limit, outage, bad key, empty reply) fall back to the backup. */
function ai_run(int $userId, string $prompt, bool $json = false, int $max = 700): string {
    $errs = [];
    foreach ([ai_conf($userId), ai_conf2($userId)] as $i => $c) {
        if (!$c) continue;
        try {
            $t = ai_text($prompt, $c, $json, $max);
            if ($t === '') throw new RuntimeException('empty reply');
            return $t;
        } catch (Throwable $e) {
            $errs[] = ($i ? 'Backup' : 'Main') . ' AI: ' . $e->getMessage();
        }
    }
    throw new RuntimeException($errs ? implode(' | ', $errs) : 'No AI key. Add one in Settings (Gemini and Groq have free tiers).');
}

function ai_text(string $prompt, array $c, bool $json = false, int $max = 700): string {
    if (!$c) throw new RuntimeException('No AI key. Add one in Settings (Gemini and Groq have free tiers).');
    if ($c['provider'] === 'claude') {
        $j = must_ok(http('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $c['key'], 'anthropic-version: 2023-06-01', 'content-type: application/json'],
            json_encode(['model' => $c['model'], 'max_tokens' => $max, 'messages' => [['role' => 'user', 'content' => $prompt]]])), 'Claude');
        return trim($j['content'][0]['text'] ?? '');
    }
    if ($c['provider'] === 'gemini') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $c['model'] . ':generateContent';
        $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
        $body['generationConfig'] = ['maxOutputTokens' => max($max, 2048)];   // room for 2.5 models' thinking tokens
        if ($json) $body['generationConfig']['responseMimeType'] = 'application/json';
        $j = must_ok(http('POST', $url, ['Content-Type: application/json', 'x-goog-api-key: ' . $c['key']], json_encode($body)), 'Gemini');
        return trim($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
    if (!preg_match('#^https://#', $c['base'])) throw new RuntimeException('AI base URL must start with https://');
    if ($c['model'] === '') throw new RuntimeException('Set a model name in Settings.');
    $j = must_ok(http('POST', $c['base'] . '/chat/completions', ['Authorization: Bearer ' . $c['key'], 'Content-Type: application/json'],
        json_encode(['model' => $c['model'], 'max_tokens' => $max, 'messages' => [['role' => 'user', 'content' => $prompt]]])), ai_providers()[$c['provider']]['label']);
    return trim($j['choices'][0]['message']['content'] ?? '');
}

function ai_caption(int $userId, string $brand, string $idea, string $tone): string {
    return ai_run($userId, "Write one social media post for the business \"$brand\". Tone: $tone. Topic/idea: $idea\n"
        . "Return only the post text with a few relevant hashtags. No preamble.");
}

function ai_image(string $prompt): ?string {   // free, keyless image generator; failure just means a text-only post
    try {
        $r = http('GET', 'https://image.pollinations.ai/prompt/' . rawurlencode(mb_substr($prompt, 0, 400)) . '?width=1080&height=1080&nologo=true');
        $i = $r['code'] === 200 ? @getimagesizefromstring($r['raw']) : false;
        if (!$i || !in_array($i['mime'], ['image/jpeg', 'image/png', 'image/webp'])) return null;
        $raw = $r['raw'];
        if ($i['mime'] !== 'image/jpeg' && function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($raw))) {
            ob_start(); imagejpeg($im, null, 90); $raw = ob_get_clean();   // Instagram only accepts JPEG
        } elseif ($i['mime'] !== 'image/jpeg') return null;
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
        $name = bin2hex(random_bytes(10)) . '.jpg';
        file_put_contents(UPLOAD_DIR . '/' . $name, $raw);
        return $name;
    } catch (Throwable $e) { return null; }
}

/** Generate one post for a brand. Returns the new post id. */
function autopilot_generate(array $b): int {
    $pdo = db();
    $recent = $pdo->prepare('SELECT body FROM posts WHERE brand_id=? ORDER BY id DESC LIMIT 8');
    $recent->execute([$b['id']]);
    $prev = implode("\n---\n", array_map(fn($r) => mb_substr($r['body'], 0, 160), $recent->fetchAll()));
    $prompt = "You are the social media manager for the business \"{$b['name']}\".\n"
        . "About the business: " . ($b['ap_about'] ?: ($b['website'] ?: 'n/a')) . "\n"
        . "Topics to rotate through: " . ($b['ap_topics'] ?: 'whatever suits the business') . "\n"
        . "Tone: {$b['ap_tone']}. Language: {$b['ap_lang']}. Today is " . date('l, j F Y') . ".\n"
        . "Recent posts (do NOT repeat these ideas):\n" . ($prev ?: '(none)') . "\n\n"
        . "Write ONE new post, max 260 characters including 2-3 hashtags, no emojis overload, no placeholders. "
        . "Reply as JSON: {\"post\": \"...\", \"image_prompt\": \"a vivid, photographic scene description, no text in image\"}";
    $raw = ai_run((int)$b['user_id'], $prompt, true);
    $j = json_decode(preg_replace('/^```(?:json)?|```$/m', '', $raw), true);
    $text = trim($j['post'] ?? '');
    if ($text === '') throw new RuntimeException('AI returned no post');
    $media = $b['ap_image'] && !empty($j['image_prompt']) ? ai_image($j['image_prompt']) : null;
    $auto = $b['ap_mode'] === 'auto';
    $pdo->prepare("INSERT INTO posts(brand_id,body,media,origin,scheduled_at,status,created_at) VALUES(?,?,?,?,?,?,?)")
        ->execute([$b['id'], $text, $media, 'autopilot', time(), $auto ? 'scheduled' : 'draft', time()]);
    $pid = (int)$pdo->lastInsertId();
    attach_ready_accounts($pid, (int)$b['id'], (bool)$media);
    return $pid;
}

function attach_ready_accounts(int $pid, int $brandId, bool $hasImage): void {
    $q = db()->prepare('SELECT * FROM accounts WHERE brand_id=?'); $q->execute([$brandId]);
    $ins = db()->prepare('INSERT INTO deliveries(post_id,account_id) VALUES(?,?)');
    foreach ($q->fetchAll() as $a) {
        if (!acc_ready($a)) continue;
        if ($a['platform'] === 'instagram' && !$hasImage) continue;
        $ins->execute([$pid, $a['id']]);
    }
}

function run_autopilot(): int {
    $made = 0;
    foreach (db()->query("SELECT * FROM brands WHERE ap_enabled=1 AND ap_retry<=" . time())->fetchAll() as $b) {
        try { $tz = new DateTimeZone($b['ap_tz']); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        $now = new DateTime('now', $tz);
        if (!in_array($now->format('w'), explode(',', $b['ap_days']))) continue;
        if ($now->format('H:i') < $b['ap_time']) continue;
        if ($b['ap_last'] === $now->format('Y-m-d')) continue;
        $quota = (int)(cfg('max_ai_per_user_day') ?: 6);
        $n = db()->prepare("SELECT COUNT(*) FROM posts p JOIN brands x ON x.id=p.brand_id WHERE x.user_id=? AND p.origin='autopilot' AND p.created_at>?");
        $n->execute([$b['user_id'], time() - 86400]);
        if ($n->fetchColumn() >= $quota) continue;
        try {
            autopilot_generate($b);
            db()->prepare('UPDATE brands SET ap_last=?, ap_msg=? WHERE id=?')->execute([$now->format('Y-m-d'), 'OK ' . $now->format('c'), $b['id']]);
            $made++;
        } catch (Throwable $e) {
            db()->prepare('UPDATE brands SET ap_retry=?, ap_msg=? WHERE id=?')->execute([time() + 600, $e->getMessage(), $b['id']]);
        }
    }
    return $made;
}

// ---------- Site discovery (Quick start) & RSS ----------
function safe_fetch(string $url): string {
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https']) || empty($p['host'])) throw new RuntimeException('Invalid URL');
    $ip = gethostbyname($p['host']);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('URL not allowed');
    $r = http('GET', $url, ['User-Agent: ContentHub/1.0', 'Accept: text/html,application/xml,application/rss+xml']);
    if ($r['code'] >= 400) throw new RuntimeException("Site returned HTTP {$r['code']}");
    return $r['raw'];
}

function abs_url(string $href, string $base): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    $root = $p['scheme'] . '://' . $p['host'];
    if (str_starts_with($href, '//')) return $p['scheme'] . ':' . $href;
    if (str_starts_with($href, '/')) return $root . $href;
    return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $href;
}

/** ":: Home [Acme Co] ::" -> "Acme Co";  "Acme | Home" -> "Acme" */
function clean_title(string $t): string {
    if (preg_match('/\[([^\]]{3,})\]/u', $t, $m)) $t = $m[1];
    $trim = '/^[\s:|\-–—]+|[\s:|\-–—]+$/u';
    $t = preg_replace($trim, '', $t);
    $t = preg_replace('/^(home|welcome(\s+to)?)\s*[:|\-–—]*\s*/iu', '', $t);
    $parts = preg_split('/\s[\|\-–—]\s/u', $t);
    return trim(preg_replace($trim, '', $parts[0])) ?: trim($t);
}

function discover(string $url): array {
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    $html = safe_fetch($url);
    $meta = function ($prop) use ($html) {
        if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)) return html_entity_decode($m[1]);
        return null;
    };
    $name = $meta('og:site_name');
    if (preg_match('#youtube\.com#i', $url)) $name = $meta('og:title') ?: $name;   // YouTube's site_name is just YouTube
    if (!$name && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $name = clean_title(html_entity_decode($m[1]));
    $logo = $meta('og:image');
    $about = $meta('og:description') ?: $meta('description');
    $feed = null;
    if (preg_match_all('/<link[^>]+>/i', $html, $links)) foreach ($links[0] as $l)
        if (preg_match('/type=["\']application\/(rss|atom)\+xml["\']/i', $l) && preg_match('/href=["\']([^"\']+)["\']/i', $l, $h)) { $feed = abs_url($h[1], $url); break; }
    if (!$feed) foreach (['/feed', '/rss.xml', '/feed.xml', '/blog/feed'] as $path) {
        try { $x = safe_fetch(abs_url($path, $url)); if (stripos($x, '<rss') !== false || stripos($x, '<feed') !== false) { $feed = abs_url($path, $url); break; } } catch (Throwable $e) {}
    }
    $social = [];
    $rules = ['facebook' => '#facebook\.com/(?!sharer|share|tr\?|plugins|dialog)([\w.\-]+)#i', 'instagram' => '#instagram\.com/(?!p/|explore)([\w.\-]+)#i',
        'linkedin' => '#linkedin\.com/((?:company|in)/[\w.\-%]+)#i', 'x' => '#(?:twitter|x)\.com/(?!intent|share|home)([\w]+)#i', 'telegram' => '#t\.me/([\w]+)#i'];
    foreach ($rules as $pl => $re) if (preg_match($re, $html, $m)) $social[$pl] = $m[1];
    return ['url' => $url, 'name' => $name ?: parse_url($url, PHP_URL_HOST), 'logo' => $logo ? abs_url($logo, $url) : null,
            'about' => $about, 'feed' => $feed, 'social' => $social];
}

function run_sources(): int {
    $pdo = db(); $created = 0;
    foreach ($pdo->query("SELECT * FROM sources WHERE mode!='off' AND last_check < " . (time() - 900))->fetchAll() as $s) {
        $pdo->prepare('UPDATE sources SET last_check=? WHERE id=?')->execute([time(), $s['id']]);
        if (($s['kind'] ?? 'rss') === 'youtube') {
            try { $created += youtube_sync($s); }
            catch (Throwable $e) { $pdo->prepare('UPDATE sources SET last_msg=? WHERE id=?')->execute([$e->getMessage(), $s['id']]); }
            continue;
        }
        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string(safe_fetch($s['url']), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if (!$xml) throw new RuntimeException('Feed not readable');
            $items = [];
            if (isset($xml->channel->item)) foreach ($xml->channel->item as $i)
                $items[] = [(string)($i->guid ?: $i->link), (string)$i->title, (string)$i->description, (string)$i->link];
            elseif (isset($xml->entry)) foreach ($xml->entry as $i) {
                $href = ''; foreach ($i->link as $l) { $href = (string)$l['href']; break; }
                $items[] = [(string)($i->id ?: $href), (string)$i->title, (string)($i->summary ?: $i->content), $href];
            }
            $seen = $pdo->prepare('SELECT 1 FROM source_items WHERE source_id=? AND guid=?');
            $mark = $pdo->prepare('INSERT OR IGNORE INTO source_items(source_id,guid) VALUES(?,?)');
            $n = 0;
            foreach (array_reverse(array_slice($items, 0, 20)) as [$guid, $title, $desc, $link]) {
                if ($guid === '') continue;
                $seen->execute([$s['id'], $guid]);
                if ($seen->fetch()) continue;
                $mark->execute([$s['id'], $guid]);
                if (!$s['initialized']) continue;
                if ($n++ >= 5) continue;
                $text = trim($title . "\n\n" . mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($desc)))), 0, 200));
                $pub = $s['mode'] === 'publish';
                $pdo->prepare("INSERT INTO posts(brand_id,body,link,origin,scheduled_at,status,created_at) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$s['brand_id'], $text, $link ?: null, 'rss', $pub ? time() : null, $pub ? 'scheduled' : 'draft', time()]);
                attach_ready_accounts((int)$pdo->lastInsertId(), (int)$s['brand_id'], false);
                $created++;
            }
            $pdo->prepare('UPDATE sources SET initialized=1,last_msg=? WHERE id=?')->execute(['OK ' . date('c') . ", $n new", $s['id']]);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE sources SET last_msg=? WHERE id=?')->execute([$e->getMessage(), $s['id']]);
        }
    }
    return $created;
}

require __DIR__ . '/studio.php';
