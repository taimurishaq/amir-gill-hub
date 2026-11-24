<?php
require __DIR__ . '/lib/core.php';
@set_time_limit(120);   // AI writing (Start timeline, Write now) can take 30-40 seconds
boot_session();

function out($d, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($d);
    exit;
}
function throttle_check(string $k): void {
    db()->prepare('DELETE FROM attempts WHERE ts<?')->execute([time() - 900]);
    $n = db()->prepare('SELECT COUNT(*) FROM attempts WHERE k=?'); $n->execute([$k]);
    if ($n->fetchColumn() >= 8) throw new HttpError('Too many failed attempts. Wait 15 minutes.', 429);
}
function throttle_fail(string $k): void { db()->prepare('INSERT INTO attempts(k,ts) VALUES(?,?)')->execute([$k, time()]); }

const AP_FIELDS = ['ap_enabled', 'ap_mode', 'ap_days', 'ap_time', 'ap_tz', 'ap_about', 'ap_tone', 'ap_topics', 'ap_lang', 'ap_image'];

// ---------------- API ----------------
if (isset($_GET['api'])) {
    $a = $_GET['api'];
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        $pdo = db();
        if ($a === 'public') {
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $m = $users === 0 ? 'open' : (cfg('signup') ?: 'closed');
            out(['signup' => $m !== 'closed', 'invite' => $m === 'invite' && $users > 0]);
        }
        if ($a === 'signup' || $a === 'login') {
            $email = strtolower(trim($in['email'] ?? ''));
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            throttle_check("$a|$ip"); throttle_check("$a|$email");
            if ($a === 'signup') {
                $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $m = $users === 0 ? 'open' : (cfg('signup') ?: 'closed');
                if ($m === 'closed') throw new HttpError('Sign-ups are closed.', 403);
                if ($m === 'invite' && !hash_equals((string)cfg('invite_code'), (string)($in['invite'] ?? ''))) {
                    throttle_fail("$a|$ip"); throw new HttpError('Wrong invite code.', 403);
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError('Enter a valid email.', 400);
                if (strlen($in['password'] ?? '') < 8) throw new HttpError('Password must be at least 8 characters.', 400);
                $ex = $pdo->prepare('SELECT 1 FROM users WHERE email=?'); $ex->execute([$email]);
                if ($ex->fetch()) throw new HttpError('That email already has an account.', 409);
                $pdo->prepare('INSERT INTO users(email,pass,created_at) VALUES(?,?,?)')
                    ->execute([$email, password_hash($in['password'], PASSWORD_DEFAULT), time()]);
                $id = (int)$pdo->lastInsertId();
            } else {
                usleep(300000);
                $u = $pdo->prepare('SELECT * FROM users WHERE email=?'); $u->execute([$email]);
                $u = $u->fetch();
                if (!$u || !password_verify((string)($in['password'] ?? ''), $u['pass'])) {
                    throttle_fail("$a|$ip"); throttle_fail("$a|$email");
                    throw new HttpError('Wrong email or password.', 401);
                }
                $id = (int)$u['id'];
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = $id;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
        }
        if (!uid()) out(['error' => 'auth'], 401);
        if ($a === 'session') out(['csrf' => $_SESSION['csrf']]);
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF'] ?? '')) out(['error' => 'csrf'], 403);

        switch ($a) {
            case 'logout': session_destroy(); out(['ok' => true]);

            case 'state':
                $me = one('SELECT email, gemini_key, ai_provider, ai_model, ai_base, ai2_provider, ai2_model, ai2_base, ai2_key FROM users WHERE id=?', [uid()]);
                $brands = $pdo->prepare('SELECT * FROM brands WHERE user_id=? ORDER BY name'); $brands->execute([uid()]);
                $brands = $brands->fetchAll();
                $ids = array_column($brands, 'id') ?: [0];
                $in_ids = implode(',', array_map('intval', $ids));
                $accs = $pdo->query("SELECT * FROM accounts WHERE brand_id IN ($in_ids) ORDER BY brand_id, platform")->fetchAll();
                foreach ($accs as &$x) {
                    $x['state'] = acc_state($x);
                    $x['ready'] = $x['state'] === 'ready';
                    $c = creds_of($x);
                    unset($x['creds']);
                    $x['values'] = [];
                    foreach (platforms()[$x['platform']]['fields'] ?? [] as [$k, , $secret])
                        if (!$secret && isset($c[$k])) $x['values'][$k] = $c[$k];
                    $x['oauth'] = !empty($c['x_access']) || (!empty($c['expires_at']));
                }
                unset($x);
                $posts = $pdo->query("SELECT * FROM posts WHERE brand_id IN ($in_ids) ORDER BY COALESCE(scheduled_at, created_at) DESC LIMIT 200")->fetchAll();
                $dl = $pdo->query("SELECT d.*, a.platform, a.label FROM deliveries d JOIN accounts a ON a.id=d.account_id WHERE a.brand_id IN ($in_ids)")->fetchAll();
                $by = [];
                foreach ($dl as $d) $by[$d['post_id']][] = $d;
                foreach ($posts as &$p) $p['deliveries'] = $by[$p['id']] ?? [];
                unset($p);
                $sources = $pdo->query("SELECT * FROM sources WHERE brand_id IN ($in_ids)")->fetchAll();
                foreach ($brands as &$bb) { $bb['dc_ready'] = !empty($bb['dc_token']) && !empty($bb['dc_channel']); unset($bb['dc_token']); }
                unset($bb);
                $camps = $pdo->query("SELECT * FROM campaigns WHERE brand_id IN ($in_ids) ORDER BY id DESC LIMIT 50")->fetchAll();
                foreach ($camps as &$cc) $cc['plan'] = json_decode((string)$cc['plan'], true) ?: [];
                unset($cc);
                $vids = $pdo->query("SELECT brand_id, COUNT(*) n FROM videos WHERE brand_id IN ($in_ids) GROUP BY brand_id")->fetchAll(PDO::FETCH_KEY_PAIR);
                out(['email' => $me['email'], 'brands' => $brands, 'campaigns' => $camps, 'videos' => $vids, 'accounts' => $accs, 'posts' => $posts, 'sources' => $sources,
                     'platforms' => platforms(), 'oauth' => oauth_providers(), 'now' => time(),
                     'ai' => ai_key(uid()) !== '', 'own_key' => (bool)$me['gemini_key'],
                     'providers' => ai_providers(),
                     'ai2_cfg' => ['provider' => $me['ai2_provider'] ?: '', 'model' => $me['ai2_model'] ?: '', 'base' => $me['ai2_base'] ?: '', 'key_saved' => (bool)$me['ai2_key']],
                     'ai_cfg' => ['provider' => $me['ai_provider'] ?: (ai_conf(uid())['provider'] ?? 'gemini'), 'model' => $me['ai_model'] ?: '', 'base' => $me['ai_base'] ?: '']]);

            case 'settings':
                if (isset($in['gemini_key']) && $in['gemini_key'] !== '')
                    $pdo->prepare('UPDATE users SET gemini_key=? WHERE id=?')->execute([trim($in['gemini_key']) === '' ? null : enc(trim($in['gemini_key'])), uid()]);
                if (isset($in['ai_provider'])) {
                    if (!isset(ai_providers()[$in['ai_provider']])) throw new HttpError('Unknown AI provider', 400);
                    $base = trim((string)($in['ai_base'] ?? ''));
                    if ($base !== '' && !preg_match('#^https://[\w.\-]+(:\d+)?(/[\w.\-/]*)?$#', $base)) throw new HttpError('Base URL must be an https:// address', 400);
                    $pdo->prepare('UPDATE users SET ai_provider=?, ai_model=?, ai_base=? WHERE id=?')
                        ->execute([$in['ai_provider'], mb_substr(trim((string)($in['ai_model'] ?? '')), 0, 120) ?: null, $base ?: null, uid()]);
                }
                if (array_key_exists('ai2_provider', $in)) {      // backup AI ('' = turn off and forget its key)
                    if ($in['ai2_provider'] === '') {
                        $pdo->prepare('UPDATE users SET ai2_provider=NULL, ai2_model=NULL, ai2_base=NULL, ai2_key=NULL WHERE id=?')->execute([uid()]);
                    } else {
                        if (!isset(ai_providers()[$in['ai2_provider']])) throw new HttpError('Unknown backup AI provider', 400);
                        $base = trim((string)($in['ai2_base'] ?? ''));
                        if ($base !== '' && !preg_match('#^https://[\w.\-]+(:\d+)?(/[\w.\-/]*)?$#', $base)) throw new HttpError('Backup base URL must be an https:// address', 400);
                        $pdo->prepare('UPDATE users SET ai2_provider=?, ai2_model=?, ai2_base=? WHERE id=?')
                            ->execute([$in['ai2_provider'], mb_substr(trim((string)($in['ai2_model'] ?? '')), 0, 120) ?: null, $base ?: null, uid()]);
                        $k = trim((string)($in['ai2_key'] ?? ''));
                        if ($k !== '') $pdo->prepare('UPDATE users SET ai2_key=? WHERE id=?')->execute([enc($k), uid()]);
                    }
                }
                if (!empty($in['new_password'])) {
                    $u = one('SELECT pass FROM users WHERE id=?', [uid()]);
                    if (!password_verify((string)($in['old_password'] ?? ''), $u['pass'])) throw new HttpError('Current password is wrong.', 400);
                    if (strlen($in['new_password']) < 8) throw new HttpError('New password must be at least 8 characters.', 400);
                    $pdo->prepare('UPDATE users SET pass=? WHERE id=?')->execute([password_hash($in['new_password'], PASSWORD_DEFAULT), uid()]);
                }
                out(['ok' => true]);

            case 'ai_test':   // proves the saved key/model/provider work before Autopilot depends on them
                $res = [];
                foreach (['main' => ai_conf(uid()), 'backup' => ai_conf2(uid())] as $role => $c) {
                    if (!$c) continue;
                    try { $res[$role] = ['ok' => true, 'provider' => $c['provider'], 'model' => $c['model'], 'reply' => mb_substr(ai_text('Reply with exactly: OK', $c), 0, 60)]; }
                    catch (Throwable $e) { $res[$role] = ['ok' => false, 'provider' => $c['provider'], 'model' => $c['model'], 'error' => $e->getMessage()]; }
                }
                if (!$res) throw new HttpError('Save an AI key first.', 400);
                out(['results' => $res]);

            case 'delete_me':   // removes the user, businesses, tokens and posts
                $u = one('SELECT pass FROM users WHERE id=?', [uid()]);
                if (!password_verify((string)($in['password'] ?? ''), $u['pass'])) throw new HttpError('Wrong password.', 400);
                foreach ($pdo->query('SELECT p.media FROM posts p JOIN brands b ON b.id=p.brand_id WHERE b.user_id=' . uid() . ' AND p.media IS NOT NULL') as $r)
                    @unlink(UPLOAD_DIR . '/' . $r['media']);
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([uid()]);
                session_destroy(); out(['ok' => true]);

            case 'quickstart':
                $url = trim($in['url'] ?? ''); $name = trim($in['name'] ?? '');
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE user_id=?'); $cnt->execute([uid()]);
                if ($cnt->fetchColumn() >= (int)(cfg('max_brands') ?: 5)) throw new HttpError('Business limit reached.', 400);
                $found = ['social' => [], 'feed' => null, 'logo' => null, 'url' => null, 'about' => null];
                if ($url !== '') {
                    try { $found = discover($url); }
                    catch (Throwable $e) { if ($name === '') throw new HttpError('Could not read that site (' . $e->getMessage() . '). Type a name instead.', 400); }
                    if ($name === '') $name = $found['name'];
                }
                if ($name === '') throw new HttpError('Enter a website link or a business name', 400);
                $pdo->prepare('INSERT INTO brands(user_id,name,website,logo,ap_about,ap_tz) VALUES(?,?,?,?,?,?)')
                    ->execute([uid(), $name, $found['url'], $found['logo'], mb_substr((string)$found['about'], 0, 500), $in['tz'] ?? 'UTC']);
                $bid = (int)$pdo->lastInsertId();
                $added = [];
                foreach ($found['social'] as $pl => $handle) {
                    $pdo->prepare('INSERT INTO accounts(brand_id,platform,label,creds) VALUES(?,?,?,?)')->execute([$bid, $pl, '@' . $handle, '']);
                    $added[] = $pl;
                }
                $yt = null;
                if (preg_match('#(?:^|//|\.)youtube\.com/#i', $url)) {   // a YouTube channel link: import its videos instead of a blog feed
                    try { $yt = youtube_add($bid, $url); } catch (Throwable $e) {}
                    if (preg_match('#/(@[\w.\-]+)#', $url, $hm)) $pdo->prepare('UPDATE brands SET handle=? WHERE id=?')->execute([$hm[1], $bid]);
                } elseif ($found['feed']) $pdo->prepare('INSERT INTO sources(brand_id,url,mode) VALUES(?,?,?)')->execute([$bid, $found['feed'], 'draft']);
                out(['ok' => true, 'id' => $bid, 'name' => $name, 'accounts' => $added, 'feed' => $found['feed'], 'youtube' => $yt]);

            case 'brand_save':
                $name = trim($in['name'] ?? '');
                if ($name === '') throw new HttpError('Name required', 400);
                $id = (int)($in['id'] ?? 0);
                if ($id) {
                    own_brand($id);
                    $pdo->prepare('UPDATE brands SET name=? WHERE id=?')->execute([$name, $id]);
                    if (!empty($in['ap']) && is_array($in['ap'])) {
                        $ap = $in['ap'];
                        $row = [
                            'ap_enabled' => empty($ap['ap_enabled']) ? 0 : 1,
                            'ap_mode' => ($ap['ap_mode'] ?? '') === 'auto' ? 'auto' : 'review',
                            'ap_days' => implode(',', array_unique(array_filter(array_map('intval', array_filter(explode(',', (string)($ap['ap_days'] ?? '')), 'ctype_digit')), fn($d) => $d >= 0 && $d <= 6))) ?: '1',
                            'ap_time' => preg_match('/^\d\d:\d\d$/', $ap['ap_time'] ?? '') ? $ap['ap_time'] : '09:00',
                            'ap_tz' => in_array($ap['ap_tz'] ?? '', timezone_identifiers_list()) ? $ap['ap_tz'] : 'UTC',
                            'ap_about' => mb_substr((string)($ap['ap_about'] ?? ''), 0, 1000),
                            'ap_tone' => mb_substr((string)($ap['ap_tone'] ?? ''), 0, 200) ?: 'friendly, professional',
                            'ap_topics' => mb_substr((string)($ap['ap_topics'] ?? ''), 0, 1000),
                            'ap_lang' => mb_substr((string)($ap['ap_lang'] ?? ''), 0, 40) ?: 'English',
                            'ap_image' => empty($ap['ap_image']) ? 0 : 1];
                        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($row)));
                        $pdo->prepare("UPDATE brands SET $set, ap_retry=0 WHERE id=?")->execute([...array_values($row), $id]);
                    }
                } else {
                    $pdo->prepare('INSERT INTO brands(user_id,name) VALUES(?,?)')->execute([uid(), $name]);
                }
                out(['ok' => true]);

            case 'autopilot_now':
                $b = own_brand((int)$in['id']);
                $pid = autopilot_generate($b);
                if ($b['ap_mode'] === 'auto') run_due($pid);
                out(['ok' => true, 'id' => $pid]);

            case 'brand_delete':
                $b = own_brand((int)$in['id']);
                foreach ($pdo->query('SELECT media FROM posts WHERE media IS NOT NULL AND brand_id=' . (int)$b['id']) as $r) @unlink(UPLOAD_DIR . '/' . $r['media']);
                $pdo->prepare('DELETE FROM brands WHERE id=?')->execute([$b['id']]);
                out(['ok' => true]);

            case 'source_mode':
                own_source((int)$in['id']);
                if (!in_array($in['mode'] ?? '', ['off', 'draft', 'publish'])) throw new HttpError('bad mode', 400);
                $pdo->prepare('UPDATE sources SET mode=?, last_check=0 WHERE id=?')->execute([$in['mode'], $in['id']]);
                out(['ok' => true]);

            case 'source_add':
                own_brand((int)$in['brand_id']);
                $pdo->prepare('INSERT INTO sources(brand_id,url,mode) VALUES(?,?,?)')->execute([$in['brand_id'], trim($in['url']), 'draft']);
                out(['ok' => true]);

            case 'source_delete':
                own_source((int)$in['id']);
                $pdo->prepare('DELETE FROM sources WHERE id=?')->execute([$in['id']]);
                out(['ok' => true]);

            case 'account_save':
                $pf = platforms()[$in['platform'] ?? ''] ?? null;
                if (!$pf) throw new HttpError('Bad platform', 400);
                $old = [];
                if (!empty($in['id'])) { $acc = own_account((int)$in['id']); $old = creds_of($acc); }
                else own_brand((int)$in['brand_id']);
                $c = $old;
                foreach ($pf['fields'] as [$k]) {
                    $v = trim($in['creds'][$k] ?? '');
                    if ($v !== '') $c[$k] = $v; elseif (!isset($c[$k])) $c[$k] = '';
                }
                if (!empty($in['id']))
                    $pdo->prepare('UPDATE accounts SET label=?, creds=? WHERE id=?')->execute([$in['label'] ?? '', enc(json_encode($c)), $in['id']]);
                else
                    $pdo->prepare('INSERT INTO accounts(brand_id,platform,label,creds) VALUES(?,?,?,?)')
                        ->execute([$in['brand_id'], $in['platform'], $in['label'] ?? '', enc(json_encode($c))]);
                out(['ok' => true]);

            case 'account_delete':
                own_account((int)$in['id']);
                $pdo->prepare('DELETE FROM accounts WHERE id=?')->execute([$in['id']]);
                out(['ok' => true]);

            case 'post_save': // multipart/form-data
                $brand = own_brand((int)($_POST['brand_id'] ?? 0))['id'];
                $body = trim($_POST['body'] ?? ''); $link = trim($_POST['link'] ?? '');
                $when = (int)($_POST['when'] ?? 0);
                $accs = array_map('intval', (array)($_POST['accounts'] ?? []));
                $mode = $_POST['mode'] ?? 'draft';
                $id = (int)($_POST['id'] ?? 0);
                if ($body === '' && empty($_FILES['media']['name'])) throw new HttpError('Write something or attach an image', 400);
                if ($mode !== 'draft' && !$accs) throw new HttpError('Pick at least one account', 400);
                if ($mode === 'schedule' && $when < time() - 60) throw new HttpError('Pick a future time', 400);
                if ($mode === 'now') $when = time();
                $media = null;
                if (!empty($_FILES['media']['name']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) throw new HttpError('Images only (jpg, png, gif, webp)', 400);
                    if ($_FILES['media']['size'] > 8 * 1024 * 1024) throw new HttpError('Image over 8 MB', 400);
                    if (!@getimagesize($_FILES['media']['tmp_name'])) throw new HttpError('Not a valid image', 400);
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    $media = bin2hex(random_bytes(10)) . '.' . $ext;
                    move_uploaded_file($_FILES['media']['tmp_name'], UPLOAD_DIR . '/' . $media);
                }
                $status = $mode === 'draft' ? 'draft' : 'scheduled';
                if ($id) {
                    $old = own_post($id);
                    if (!in_array($old['status'], ['draft', 'scheduled', 'failed', 'partial'])) throw new HttpError('Already published/publishing', 400);
                    $pdo->prepare('UPDATE posts SET brand_id=?,body=?,link=?,media=COALESCE(?,media),scheduled_at=?,status=? WHERE id=?')
                        ->execute([$brand, $body, $link ?: null, $media, $when ?: null, $status, $id]);
                    $pdo->prepare('DELETE FROM deliveries WHERE post_id=?')->execute([$id]);
                } else {
                    $pdo->prepare('INSERT INTO posts(brand_id,body,link,media,scheduled_at,status,created_at) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$brand, $body, $link ?: null, $media, $when ?: null, $status, time()]);
                    $id = (int)$pdo->lastInsertId();
                }
                $ins = $pdo->prepare('INSERT INTO deliveries(post_id,account_id) SELECT ?, id FROM accounts WHERE id=? AND brand_id=?');
                foreach ($accs as $aid) $ins->execute([$id, $aid, $brand]);
                if ($mode === 'now') run_due($id);
                out(['ok' => true, 'id' => $id]);

            case 'post_approve':   // review drafts (autopilot / RSS / timeline / YouTube): keep a future planned time, else publish now
                $p = own_post((int)$in['id']);
                if ($p['status'] !== 'draft') throw new HttpError('Not a draft', 400);
                $b = own_brand((int)$p['brand_id']);
                $m = studio_approve($p, $b);
                if (str_starts_with($m, '⚠️')) throw new HttpError('No connected accounts for this post. Connect accounts first.', 400);
                if ($p['dc_msg']) dc_notify($b, $m . ' (approved on the website)', $p['dc_msg']);
                out(['ok' => true, 'msg' => $m]);

            case 'post_skip':
                $p = own_post((int)$in['id']);
                if ($p['status'] !== 'draft') throw new HttpError('Not a draft', 400);
                $pdo->prepare("UPDATE posts SET status='skipped' WHERE id=?")->execute([$p['id']]);
                out(['ok' => true]);

            case 'studio_save':    // brand voice, timeline defaults, Discord approval
                $b = own_brand((int)($in['id'] ?? 0));
                $s = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
                $color = preg_match('/^#[0-9a-f]{6}$/i', $in['color'] ?? '') ? $in['color'] : '#12355b';
                $time = preg_match('/^\d\d:\d\d$/', $in['cp_time'] ?? '') ? $in['cp_time'] : '18:00';
                $pdo->prepare('UPDATE brands SET ap_about=?, ap_tone=?, ap_lang=?, ap_tz=?, audience=?, cp_rules=?, cta=?, whatsapp=?, handle=?, color=?,
                    cp_days=?, cp_time=?, cp_lead=?, cp_auto=?, cp_promo=?, cp_queue=? WHERE id=?')->execute([
                    $s('ap_about', 1500), $s('ap_tone', 200) ?: 'friendly, professional', $s('ap_lang', 40) ?: 'English',
                    in_array($in['ap_tz'] ?? '', timezone_identifiers_list()) ? $in['ap_tz'] : $b['ap_tz'],
                    $s('audience', 600), $s('cp_rules', 2000), $s('cta', 600), $s('whatsapp', 40), $s('handle', 60), $color,
                    max(1, min(60, (int)($in['cp_days'] ?? 7))), $time, max(0, min(72, (int)($in['cp_lead'] ?? 12))),
                    empty($in['cp_auto']) ? 0 : 1, max(0, min(30, (int)($in['cp_promo'] ?? 5))), $s('cp_queue', 8000), $b['id']]);
                $chan = trim((string)($in['dc_channel'] ?? ''));
                if ($chan !== '' && !preg_match('/^\d{15,22}$/', $chan)) throw new HttpError('Discord channel ID is a long number (right-click the channel → Copy Channel ID).', 400);
                $tok = (string)($in['dc_token'] ?? '');
                if ($chan !== ($b['dc_channel'] ?? '') || $tok !== '') {
                    $pdo->prepare('UPDATE brands SET dc_channel=?, dc_last=?, dc_ident=0 WHERE id=?')->execute([$chan ?: null, dc_snowflake_now(), $b['id']]);
                    if (trim($tok) === '' && $tok !== '') $pdo->prepare('UPDATE brands SET dc_token=NULL WHERE id=?')->execute([$b['id']]);   // a space removes it
                    elseif (trim($tok) !== '') $pdo->prepare('UPDATE brands SET dc_token=? WHERE id=?')->execute([enc(trim($tok)), $b['id']]);
                }
                out(['ok' => true]);

            case 'dc_test':        // sends a hello + a sample card, proving the bot, channel and card design work
                $b = own_brand((int)($in['id'] ?? 0));
                $d = dc_conf($b);
                if (!$d) throw new HttpError('Save the Discord bot token and channel ID first.', 400);
                $file = studio_card(['kicker' => 'SAMPLE CARD', 'title' => 'This is how your daily posts will look',
                    'lines' => ['A clear title students read in 5 seconds', 'X Wrong ideas are marked like this', 'OK Correct ideas are marked like this',
                        'A) Multiple-choice options look like this'], 'note' => 'Colours and footer come from your Timeline settings.'], $b);
                dc_send($d, "👋 Connected! I will post every draft here for approval.\n\n" . dc_help(),
                    $file ? ['title' => 'Sample card', 'description' => 'Your branding'] : null, $file ? UPLOAD_DIR . '/' . $file : null);
                if ($file) @unlink(UPLOAD_DIR . '/' . $file);
                out(['ok' => true]);

            case 'campaign_start':
                $b = own_brand((int)($in['brand_id'] ?? 0));
                $id = campaign_create($b, (string)($in['topic'] ?? ''), (string)($in['level'] ?? ''), (int)($in['days'] ?? 0),
                    (string)($in['start'] ?? ''), (string)($in['time'] ?? ''));
                $c = one('SELECT * FROM campaigns WHERE id=?', [$id]);
                out(['ok' => true, 'id' => $id, 'status' => $c['status'], 'msg' => $c['msg']]);

            case 'campaign_stop':
            case 'campaign_delete':
                $c = one('SELECT c.* FROM campaigns c JOIN brands b ON b.id=c.brand_id WHERE c.id=? AND b.user_id=?', [(int)($in['id'] ?? 0), uid()]);
                $drafts = $pdo->prepare("SELECT * FROM posts WHERE campaign_id=? AND status='draft'"); $drafts->execute([$c['id']]);
                foreach ($drafts->fetchAll() as $p) {     // unapproved drafts go with it; approved/sent posts stay
                    if ($p['media']) @unlink(UPLOAD_DIR . '/' . $p['media']);
                    $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([$p['id']]);
                }
                if ($a === 'campaign_delete') $pdo->prepare('DELETE FROM campaigns WHERE id=?')->execute([$c['id']]);
                else $pdo->prepare("UPDATE campaigns SET status='stopped' WHERE id=?")->execute([$c['id']]);
                out(['ok' => true]);

            case 'youtube_add':
                own_brand((int)($in['brand_id'] ?? 0));
                out(['ok' => true] + youtube_add((int)$in['brand_id'], (string)($in['channel'] ?? '')));

            case 'post_delete':
                $p = own_post((int)$in['id']);
                $pdo->prepare('DELETE FROM posts WHERE id=?')->execute([$p['id']]);
                if ($p['media'] && is_file(UPLOAD_DIR . '/' . $p['media'])) @unlink(UPLOAD_DIR . '/' . $p['media']);
                out(['ok' => true]);

            case 'post_retry':
                $p = own_post((int)$in['id']);
                $pdo->prepare("UPDATE deliveries SET status='pending',attempts=0,next_try=0 WHERE post_id=? AND status='failed'")->execute([$p['id']]);
                $pdo->prepare("UPDATE posts SET status='scheduled', scheduled_at=? WHERE id=?")->execute([time(), $p['id']]);
                run_due((int)$p['id']);
                out(['ok' => true]);

            case 'ai':
                $b = own_brand((int)($in['brand_id'] ?? 0));
                out(['text' => ai_caption(uid(), $b['name'], $in['idea'] ?? '', $b['ap_tone'])]);

            default: out(['error' => 'Unknown action'], 404);
        }
    } catch (HttpError $e) {
        out(['error' => $e->getMessage()], $e->getCode() ?: 400);
    } catch (Throwable $e) {
        out(['error' => $e->getMessage()], 500);
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Content Hub</title>
<style>
:root{--bg:#efe6d4;--card:#f9f4e8;--ink:#3b2f25;--mute:#7d6e5d;--line:#dccfb6;--acc:#7a4e2a;--ok:#4f7a3a;--bad:#b5402b;--warn:#b37a1a;--node:#fffaf0}
:root[data-theme=dark]{--bg:#231c15;--card:#2c241b;--ink:#f1e6d2;--mute:#b3a48e;--line:#443826;--acc:#d9a66b;--ok:#8fbf72;--bad:#ff8a73;--warn:#e7b25a;--node:#352b20}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 system-ui,Segoe UI,sans-serif}
header{display:flex;gap:12px;align-items:center;padding:12px 16px;background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;flex-wrap:wrap}
header h1{font-size:17px;margin:0 8px 0 0}
nav{display:flex;gap:4px;flex:1;flex-wrap:wrap}nav button{background:none;border:0;padding:8px 12px;border-radius:8px;color:var(--mute);cursor:pointer;font:inherit}
nav button.on{background:var(--bg);color:var(--ink);font-weight:600}
main{max-width:980px;margin:0 auto;padding:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:16px}
label{display:block;font-size:13px;color:var(--mute);margin:10px 0 4px}
input,select,textarea{width:100%;padding:9px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit}
textarea{min-height:110px;resize:vertical}
button.btn{padding:9px 14px;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:8px;cursor:pointer;font:inherit}
button.pri{background:var(--acc);border-color:var(--acc);color:#fffaf0}button.dng{color:var(--bad)}
button:disabled{opacity:.5}
.row{display:flex;gap:10px;flex-wrap:wrap}.row>*{flex:1;min-width:160px}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.chip{display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--line);border-radius:999px;cursor:pointer;font-size:14px;margin:0;color:var(--ink)}
.chip input{width:auto}
.pill{font-size:12px;padding:2px 8px;border-radius:999px;border:1px solid var(--line)}
.done,.sent,.ready{color:var(--ok)}.failed,.expired{color:var(--bad)}.scheduled,.publishing,.pending,.partial,.keys{color:var(--warn)}.draft{color:var(--mute)}
.post{border-top:1px solid var(--line);padding:12px 0}.post:first-child{border-top:0}
.post p{margin:4px 0;white-space:pre-wrap}.meta{color:var(--mute);font-size:13px}
.thumb{max-width:90px;max-height:90px;border-radius:8px;float:right;margin-left:10px}
#toast{position:fixed;bottom:16px;left:50%;transform:translateX(-50%);background:var(--ink);color:var(--bg);padding:10px 16px;border-radius:8px;display:none;z-index:9;max-width:90vw}
.login{max-width:380px;margin:10vh auto}
.hint{font-size:12px;color:var(--mute)}
.flow{overflow:auto;border:1px solid var(--line);border-radius:12px;background:var(--card);padding:8px}
.flow text{fill:var(--ink);font:13px system-ui}.flow .sub{fill:var(--mute);font-size:11px}
.node rect{fill:var(--node);stroke-width:1.6;cursor:pointer}.node:hover rect{filter:brightness(.97)}
.edge{fill:none;stroke:var(--line);stroke-width:2}.edge.live{stroke:var(--ok);stroke-dasharray:6 6;animation:fl 1.2s linear infinite}
@keyframes fl{to{stroke-dashoffset:-12}}
.legend span{margin-right:14px;font-size:12px;color:var(--mute)}
.filters{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}.filters select{width:auto}
a{color:var(--acc)}
details{border-top:1px solid var(--line);margin-top:12px;padding-top:8px}summary{cursor:pointer;font-weight:600}
a.btn{display:inline-block;text-decoration:none;padding:9px 14px;border-radius:8px;background:var(--acc);color:#fffaf0}
.skipped,.stopped{color:var(--mute)}.active,.planning{color:var(--ok)}
.bar{height:6px;background:var(--line);border-radius:6px;overflow:hidden;margin:8px 0}.bar i{display:block;height:100%;background:var(--ok)}
ol.plan{padding-left:22px;margin:8px 0}ol.plan li{margin:8px 0}
.steps{padding-left:20px;margin:6px 0}.steps li{margin:4px 0}
code{background:var(--bg);padding:1px 5px;border-radius:4px}
</style></head><body>
<div id="app"></div><div id="toast"></div>
<script>
let S=null,csrf='',tab='pipeline',filter={brand:'',status:''},editId=0,draft={},PUB={};
function setTheme(t){document.documentElement.dataset.theme=t;try{localStorage.setItem('theme',t)}catch(e){}}
function toggleTheme(){setTheme(document.documentElement.dataset.theme==='dark'?'light':'dark')}
try{document.documentElement.dataset.theme=localStorage.getItem('theme')||'light'}catch(e){document.documentElement.dataset.theme='light'}
const TZ=Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC';
const $=s=>document.querySelector(s),esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function toast(m){const t=$('#toast');t.textContent=m;t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',6000)}
async function api(a,body,form){
  const o={method:'POST',headers:{'X-CSRF':csrf}};
  if(form)o.body=form;else{o.headers['Content-Type']='application/json';o.body=JSON.stringify(body||{})}
  const r=await fetch('?api='+a,o);const j=await r.json();
  if(r.status===401&&!['login','signup'].includes(a)){csrf='';showLogin();throw new Error('Signed out')}
  if(!r.ok)throw new Error(j.error||'Error');return j;
}
async function boot(){
  const q=new URLSearchParams(location.search);
  try{PUB=await (await fetch('?api=public')).json()}catch(e){}
  try{const r=await fetch('?api=session',{method:'POST'});if(r.ok){csrf=(await r.json()).csrf;
    if(q.get('connected')){tab='accounts';setTimeout(()=>toast('Connected: '+q.get('connected')),400)}
    if(q.get('oerr')){tab='accounts';setTimeout(()=>toast('Connection failed: '+q.get('oerr')),400)}
    history.replaceState(null,'',location.pathname);return load()}}catch(e){}
  if(q.get('oerr'))setTimeout(()=>toast(q.get('oerr')),300);
  showLogin();
}
function showLogin(mode='login'){
  const su=mode==='signup';
  $('#app').innerHTML=`<div class="card login"><h2 style="margin-top:0">Content Hub</h2><div class="hint">${su?'Create your account':'Sign in'}</div>
  <label>Email</label><input id="em" type="email" autofocus><label>Password${su?' (8+ characters)':''}</label><input id="pw" type="password" autocomplete="${su?'new-password':'current-password'}">
  ${su?'<label>Confirm password</label><input id="pw2" type="password" autocomplete="new-password">':''}
  ${su&&PUB.invite?'<label>Invite code</label><input id="iv">':''}<br><button class="btn pri" id="go">${su?'Create account':'Sign in'}</button>
  ${PUB.signup?`<p class="hint">${su?'Have an account? <a href="#" id="sw">Sign in</a>':'New here? <a href="#" id="sw">Create an account</a>'}</p>`:''}</div>`;
  const go=async()=>{if(su&&$('#pw').value!==$('#pw2').value){toast('The two passwords do not match.');return}
    try{const j=await api(su?'signup':'login',{email:$('#em').value,password:$('#pw').value,invite:$('#iv')?.value});csrf=j.csrf;load()}catch(e){toast(e.message)}};
  // Enter submits. Use a block body: a handler that returns false cancels the key, which blocked all typing here.
  const enter=e=>{if(e.key==='Enter')go()};
  $('#go').onclick=go;$('#pw').onkeydown=enter;if($('#pw2'))$('#pw2').onkeydown=enter;
  if($('#sw'))$('#sw').onclick=e=>{e.preventDefault();showLogin(su?'login':'signup')};
}
async function load(){S=await api('state');render()}
function render(){
  const T={pipeline:'Pipeline',timeline:'Timeline',compose:'Compose',queue:'Posts',accounts:'Businesses & accounts',settings:'Settings'};
  const need=S.posts.filter(p=>p.status==='draft'&&p.origin!=='manual').length;
  $('#app').innerHTML=`<header><h1>Content Hub</h1><nav>
   ${Object.keys(T).map(t=>`<button class="${tab===t?'on':''}" onclick="tab='${t}';render()">${T[t]}${t==='queue'&&need?` (${need} to review)`:''}</button>`).join('')}
   </nav><button class="btn" title="Light / dark" onclick="toggleTheme()">◐</button><button class="btn" onclick="load()">Refresh</button><button class="btn" onclick="api('logout').then(()=>{csrf='';showLogin()})">Sign out</button></header>
   <main>${{pipeline,timeline,compose,queue,accounts,settings}[tab]()}</main>`;
  if(tab==='compose')bindCompose();
  if(tab==='timeline'&&$('#s_dct'))$('#s_dct').oninput=dcInvite;
  if(tab==='settings'){aiProv();aiProv2()}
}
// ---------- Pipeline (n8n-style graph) ----------
const DAYN=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
function pipeline(){
  const W=220,H=48,X=[24,330,636];let y=20,svg='',nodes='';
  const stat={};S.posts.forEach(p=>p.deliveries.forEach(d=>{const s=stat[d.account_id]||(stat[d.account_id]={sent:0,failed:0});if(d.status==='sent')s.sent++;if(d.status==='failed')s.failed++}));
  const icons={telegram:'✈️',facebook:'📘',instagram:'📷',linkedin:'💼',x:'𝕏',discord:'💬'};
  const node=(x,yy,title,sub,color,click,icon)=>`<g class="node" onclick="${click}" transform="translate(${x},${yy})"><rect width="${W}" height="${H}" rx="12" stroke="${color}"/><text x="14" y="21">${icon||''} ${esc(title.slice(0,24))}</text><text class="sub" x="14" y="38">${esc(sub.slice(0,38))}</text></g>`;
  const edge=(x1,y1,x2,y2,live)=>`<path class="edge ${live?'live':''}" d="M${x1},${y1} C${x1+60},${y1} ${x2-60},${y2} ${x2},${y2}"/>`;
  S.brands.forEach(b=>{
    const srcs=S.sources.filter(s=>s.brand_id==b.id),accs=S.accounts.filter(a=>a.brand_id==b.id);
    const items=[{t:'Compose / AI',sub:'manual posts',c:'var(--acc)',click:"tab='compose';draft={brand:"+b.id+"};render()",icon:'✍️',live:true},
      {t:'Autopilot',sub:b.ap_enabled?b.ap_days.split(',').map(d=>DAYN[d]).join(' ')+' '+b.ap_time+' · '+b.ap_mode:'OFF — tap to set up',c:b.ap_enabled?'var(--ok)':'var(--mute)',click:"tab='accounts';render()",icon:'🤖',live:!!b.ap_enabled},
      ...(()=>{const cs=S.campaigns.filter(c=>c.brand_id==b.id&&['planning','active'].includes(c.status)),go="tab='timeline';tlBrand="+b.id+";render()";
        return cs.length?cs.map(c=>({t:'Timeline: '+c.topic,sub:c.made+'/'+c.days+' days · '+(b.dc_ready?'Discord approval':'approve in Posts'),c:'var(--ok)',click:go,icon:'📅',live:true}))
          :[{t:'Timeline',sub:'tap to start a topic',c:'var(--mute)',click:go,icon:'📅',live:false}]})(),
      ...srcs.map(s=>({t:s.kind==='youtube'?'YouTube channel':'RSS: '+s.url.replace(/^https?:\/\//,''),sub:(s.kind==='youtube'?(S.videos[b.id]||0)+' videos · new → ':'auto → ')+(s.mode==='publish'?'publish':s.mode==='draft'?'review':'OFF')+' · tap to change',c:s.mode==='off'?'var(--mute)':s.mode==='publish'?'var(--ok)':'var(--warn)',click:`cycleSrc(${s.id},'${s.mode}')`,icon:s.kind==='youtube'?'▶️':'📡',live:s.mode!=='off'}))];
    const rows=Math.max(items.length,accs.length,1),bh=rows*62,cy=y+bh/2-H/2;
    svg+=`<rect x="8" y="${y-10}" width="${X[2]+W+16}" height="${bh+4}" rx="14" fill="none" stroke="var(--line)" stroke-dasharray="3 5"/>`;
    items.forEach((it,i)=>{const yy=y+i*62+(bh-items.length*62)/2;nodes+=node(X[0],yy,it.t,it.sub,it.c,it.click,it.icon);svg+=edge(X[0]+W,yy+H/2,X[1],cy+H/2,it.live)});
    nodes+=node(X[1],cy,b.name,(b.website||'').replace(/^https?:\/\//,'')||(accs.length+' accounts'),'var(--acc)',"tab='accounts';render()",'🏢');
    accs.forEach((a,i)=>{const yy=y+i*62+(bh-accs.length*62)/2,st=stat[a.id]||{sent:0,failed:0};
      const c=!a.ready?'var(--warn)':st.failed&&!st.sent?'var(--bad)':'var(--ok)';
      const sub=a.state==='keys'?'not connected — tap to connect':a.state==='expired'?'expired — reconnect':'✓ '+st.sent+' sent'+(st.failed?' · ✗ '+st.failed+' failed':'');
      nodes+=node(X[2],yy,S.platforms[a.platform].label.split(' ')[0]+(a.label?' '+a.label:''),sub,c,"tab='accounts';render()",icons[a.platform]);
      svg+=edge(X[1]+W,cy+H/2,X[2],yy+H/2,a.ready)});
    if(!accs.length)nodes+=`<text class="sub" x="${X[2]}" y="${y+bh/2}">no accounts yet</text>`;
    y+=bh+30;
  });
  const tot=W+X[2]+24;
  return `<div class="card"><h3 style="margin-top:0">Quick start — one input, everything connects</h3>
   <div class="row"><input id="qs" placeholder="Website link (e.g. alhamracommunications.com) — or just a business name"><button class="btn pri" style="flex:0;white-space:nowrap" id="qsb" onclick="quick()">Create &amp; connect</button></div>
   <div class="hint">I read the site's name, logo, description, blog feed and social links. Then press <b>Connect</b> on each network and turn on <b>Autopilot</b> — it writes and posts for you on your schedule.</div></div>
  ${S.brands.length?`<div class="flow"><svg width="${tot}" height="${y+10}" viewBox="0 0 ${tot} ${y+10}">${svg}${nodes}</svg></div>
  <div class="legend" style="margin-top:8px"><span style="color:var(--ok)">● connected</span><span style="color:var(--warn)">● needs connecting / review</span><span style="color:var(--bad)">● failing</span><span>Moving green lines = live flow</span></div>`:'<div class="card hint">No businesses yet. Add your first one above.</div>'}`;
}
async function quick(){const v=$('#qs').value.trim();if(!v)return;const isUrl=/\./.test(v)&&!/\s/.test(v);$('#qsb').disabled=true;
  try{const j=await api('quickstart',isUrl?{url:v,tz:TZ}:{name:v,tz:TZ});toast(j.name+': '+j.accounts.length+' networks detected'+(j.feed?', blog feed connected':'')+'. Now connect your accounts.');tab='accounts';await load()}catch(e){toast(e.message)}$('#qsb').disabled=false}
async function cycleSrc(id,m){const n={draft:'publish',publish:'off',off:'draft'}[m];if(n==='publish'&&!confirm('Auto-PUBLISH new blog posts to all connected accounts without review?'))return;try{await api('source_mode',{id,mode:n});load()}catch(e){toast(e.message)}}
// ---------- Compose ----------
function compose(){
  if(!S.brands.length)return `<div class="card">Add your first business on the <b>Pipeline</b> tab.</div>`;
  const e=editId?S.posts.find(p=>p.id==editId):null;
  if(e&&!draft.loaded){draft={loaded:1,brand:e.brand_id,body:e.body,link:e.link||'',accs:e.deliveries.map(d=>d.account_id),when:e.scheduled_at}}
  const b=draft.brand||S.brands[0].id;
  const accs=S.accounts.filter(a=>a.brand_id==b);
  const dt=draft.when?new Date(draft.when*1000-new Date().getTimezoneOffset()*60000).toISOString().slice(0,16):'';
  return `<div class="card"><h3 style="margin-top:0">${e?'Edit post #'+e.id:'New post'}</h3>
  <div class="row"><div><label>Business</label><select id="brand">${S.brands.map(x=>`<option value="${x.id}" ${x.id==b?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div></div>
  <label>Post text</label><textarea id="body" placeholder="Write your post...">${esc(draft.body||'')}</textarea>
  <div class="hint"><span id="cnt">0</span> characters · X limit 280 · Instagram needs an image</div>
  ${S.ai?`<div class="row" style="margin-top:8px"><input id="idea" placeholder="Idea for AI caption…"><button class="btn" id="aib">✨ Generate</button></div>`:`<div class="hint">Add a Claude or free Gemini key in Settings for AI captions and Autopilot.</div>`}
  <div class="row"><div><label>Link (optional)</label><input id="link" value="${esc(draft.link||'')}" placeholder="https://"></div>
   <div><label>Image (optional)${e&&e.media?' — leave empty to keep existing':''}</label><input id="media" type="file" accept="image/*"></div></div>
  <label>Publish to</label><div class="chips">${accs.length?accs.map(a=>`<label class="chip"><input type="checkbox" class="acc" value="${a.id}" ${a.ready?'':'disabled'} ${(draft.accs||[]).includes(a.id)&&a.ready?'checked':''}>${esc(S.platforms[a.platform].label.split(' (')[0])}${a.label?' · '+esc(a.label):''}${a.ready?'':' (not connected)'}</label>`).join(''):'<span class="hint">No accounts for this business yet.</span>'}</div>
  <label>Schedule for (your local time)</label><input id="when" type="datetime-local" value="${dt}">
  <div class="row" style="margin-top:14px"><button class="btn" data-m="draft">Save draft</button><button class="btn pri" data-m="schedule">Schedule</button><button class="btn pri" data-m="now">Post now</button>${e?'<button class="btn" id="cancel">Cancel edit</button>':''}</div></div>`;
}
function bindCompose(){
  if(!$('#brand'))return;
  const keep=()=>{draft.body=$('#body').value;draft.link=$('#link').value;draft.accs=[...document.querySelectorAll('.acc:checked')].map(x=>+x.value);const w=$('#when').value;draft.when=w?Math.floor(new Date(w).getTime()/1000):null};
  const cnt=()=>$('#cnt').textContent=$('#body').value.length;cnt();$('#body').oninput=cnt;
  $('#brand').onchange=()=>{keep();draft.brand=+$('#brand').value;draft.accs=[];render()};
  if($('#aib'))$('#aib').onclick=async()=>{try{$('#aib').disabled=true;const j=await api('ai',{brand_id:+$('#brand').value,idea:$('#idea').value});$('#body').value=j.text;cnt()}catch(e){toast(e.message)}$('#aib').disabled=false};
  if($('#cancel'))$('#cancel').onclick=()=>{editId=0;draft={};render()};
  document.querySelectorAll('[data-m]').forEach(btn=>btn.onclick=async()=>{
    keep();const f=new FormData();const m=btn.dataset.m;
    f.append('mode',m);f.append('brand_id',$('#brand').value);f.append('body',draft.body);f.append('link',draft.link);
    f.append('when',draft.when||0);draft.accs.forEach(a=>f.append('accounts[]',a));if(editId)f.append('id',editId);
    const file=$('#media').files[0];if(file)f.append('media',file);
    btn.disabled=true;
    try{await api('post_save',null,f);toast(m==='now'?'Sent — check Posts for results':m==='schedule'?'Scheduled':'Draft saved');editId=0;draft={};tab='queue';await load()}
    catch(e){toast(e.message);btn.disabled=false}
  });
}
// ---------- Posts ----------
function queue(){
  const list=S.posts.filter(p=>(!filter.brand||p.brand_id==filter.brand)&&(!filter.status||p.status==filter.status));
  const bn=id=>(S.brands.find(b=>b.id==id)||{}).name||'?';
  return `<div class="filters"><select onchange="filter.brand=this.value;render()"><option value="">All businesses</option>${S.brands.map(b=>`<option value="${b.id}" ${filter.brand==b.id?'selected':''}>${esc(b.name)}</option>`).join('')}</select>
  <select onchange="filter.status=this.value;render()"><option value="">All statuses</option>${['draft','scheduled','done','partial','failed','skipped'].map(s=>`<option ${filter.status==s?'selected':''}>${s}</option>`).join('')}</select></div>
  <div class="card">${list.length?list.map(p=>`<div class="post">
   ${p.media?`<img class="thumb" src="uploads/${esc(p.media)}">`:''}
   <div><b>${esc(bn(p.brand_id))}</b> <span class="pill ${p.status}">${p.status==='draft'&&p.origin!=='manual'?'needs review':p.status}</span>
   ${p.origin==='autopilot'?'<span class="pill">🤖 autopilot</span>':p.origin==='rss'?'<span class="pill">📡 blog</span>':p.origin==='campaign'?`<span class="pill">📅 ${esc((S.campaigns.find(c=>c.id==p.campaign_id)||{}).topic||'timeline')} · day ${p.day}</span>`:p.origin==='youtube'?'<span class="pill">▶️ YouTube</span>':''}
   <span class="meta">${p.scheduled_at?new Date(p.scheduled_at*1000).toLocaleString():'no date'}</span></div>
   <p>${esc(p.body.slice(0,400))}</p>${p.link?`<div class="meta">${esc(p.link)}</div>`:''}
   <div class="meta">${p.deliveries.map(d=>`<span class="${d.status}">● ${esc(d.platform)}${d.label?' ('+esc(d.label)+')':''}: ${d.status}${d.status!=='sent'&&d.result?' — '+esc(d.result):''}</span>`).join('<br>')}</div>
   <div class="row" style="margin-top:8px;justify-content:flex-start">
   ${p.status==='draft'&&p.origin!=='manual'?`<button class="btn pri" style="flex:0;white-space:nowrap" onclick="act('post_approve',${p.id})">${p.scheduled_at>S.now+60?'Approve (goes out '+new Date(p.scheduled_at*1000).toLocaleString([], {weekday:'short',hour:'numeric',minute:'2-digit'})+')':'Approve &amp; post'}</button><button class="btn" style="flex:0" onclick="act('post_skip',${p.id})">Skip</button>`:''}
   ${['draft','scheduled','failed','partial'].includes(p.status)?`<button class="btn" style="flex:0" onclick="editId=${p.id};draft={};tab='compose';render()">Edit</button>`:''}
   ${['failed','partial'].includes(p.status)?`<button class="btn pri" style="flex:0" onclick="act('post_retry',${p.id})">Retry</button>`:''}
   <button class="btn dng" style="flex:0" onclick="confirm('Delete this post?')&&act('post_delete',${p.id})">Delete</button></div></div>`).join(''):'<span class="meta">Nothing here yet.</span>'}</div>`;
}
async function act(a,id){try{const j=await api(a,{id});if(j.msg)toast(j.msg);await load()}catch(e){toast(e.message)}}
// ---------- Timeline (topic → daily posts → Discord approval) ----------
let tlBrand=0;
const LEVELS=['O Level (7707)','IGCSE (0452)','A Level (9706)','AS Level (9706)','O Level & IGCSE','University (B.Com / BBA / ACCA)','All levels'];
function timeline(){
  if(!S.brands.length)return `<div class="card">Add your first business on the <b>Pipeline</b> tab.</div>`;
  const b=S.brands.find(x=>x.id==tlBrand)||S.brands[0];tlBrand=b.id;
  const camps=S.campaigns.filter(c=>c.brand_id==b.id),posts=S.posts.filter(p=>p.brand_id==b.id);
  const yts=S.sources.filter(s=>s.brand_id==b.id&&s.kind==='youtube');
  const conn=S.accounts.filter(a=>a.brand_id==b.id&&a.ready);
  const v=(k,d='')=>esc(b[k]??d);
  return `${S.brands.length>1?`<div class="filters"><select onchange="tlBrand=+this.value;render()">${S.brands.map(x=>`<option value="${x.id}" ${x.id==b.id?'selected':''}>${esc(x.name)}</option>`).join('')}</select></div>`:''}
  <div class="card"><h3 style="margin-top:0">Start a topic</h3>
   <input id="tt" placeholder="Topic, e.g. Depreciation of non-current assets" onkeydown="event.key==='Enter'&&tlStart(${b.id})">
   <div class="row"><div><label>Level</label><input id="tlv" list="lvls" placeholder="O Level (7707)"><datalist id="lvls">${LEVELS.map(l=>`<option value="${esc(l)}">`).join('')}</datalist></div>
    <div><label>Days</label><input id="tdy" type="number" min="1" max="60" value="${b.cp_days}"></div>
    <div><label>Start date (optional)</label><input id="tsd" type="date"></div>
    <div><label>Post time</label><input id="ttm" type="time" value="${v('cp_time','18:00')}"></div></div>
   <div class="row" style="margin-top:12px;justify-content:flex-start"><button class="btn pri" style="flex:0;white-space:nowrap" id="tgo" onclick="tlStart(${b.id})">▶ Start timeline</button></div>
   <div class="hint" style="margin-top:8px">The AI plans one post a day: concepts, worked examples, MCQs, common mistakes, exam tips${+b.cp_promo?`, and a tuition advert every ${b.cp_promo}th day`:''}.
    Each post gets a branded image plus its own Instagram/Facebook, LinkedIn and WhatsApp text.
    ${b.dc_ready?`Each one is sent to Discord for approval ${b.cp_lead} hours before it goes out.`:'Drafts wait in <b>Posts</b> for approval. Connect Discord below to approve from your phone.'}
    ${conn.length?'':'<br><b style="color:var(--warn)">No social accounts are connected yet.</b> Approved posts will wait until you connect them in Businesses &amp; accounts.'}</div></div>
  ${camps.length?`<div class="card"><h3 style="margin-top:0">Timelines</h3>${camps.map(c=>tlCard(c,posts)).join('')}</div>`:''}
  <div class="card"><h3 style="margin-top:0">Up next</h3><textarea id="s_queue" style="min-height:90px" placeholder="One topic per line, e.g.&#10;Bank reconciliation statements | 5 | O Level&#10;Control accounts | 6 | AS Level&#10;Partnership appropriation account">${v('cp_queue')}</textarea>
   <div class="hint">When a timeline ends, the next topic starts the following day. Optional after a topic: <code>| days | level</code>; otherwise it uses your default days and the previous level. Lines starting with <code>#</code> are notes. You can also add topics from Discord with <code>queue: topic</code>.</div>
   <div class="row" style="margin-top:8px;justify-content:flex-start"><button class="btn" style="flex:0;white-space:nowrap" onclick="saveStudio(${b.id})">Save queue</button></div></div>
  <div class="card"><h3 style="margin-top:0">▶️ YouTube channel</h3>
   ${yts.map(s=>`<div class="post">${esc(s.url.replace('https://www.youtube.com/feeds/videos.xml?channel_id=','channel '))} · ${S.videos[b.id]||0} videos in the library
    <span class="pill ${s.mode}">${s.mode==='draft'?'new videos → review':s.mode==='publish'?'new videos → auto-post':'off'}</span> <button class="btn" onclick="cycleSrc(${s.id},'${s.mode}')">Change</button>
    <div class="meta">${esc(s.last_msg||'')}</div></div>`).join('')}
   ${yts.length?'':`<div class="row"><input id="yt" placeholder="https://www.youtube.com/@YourChannel"><button class="btn pri" style="flex:0;white-space:nowrap" onclick="ytAdd(${b.id})">Connect channel</button></div>`}
   <div class="hint">Every new upload (lecture or Short) becomes a branded post with your thumbnail, sent for approval. Timelines also link to your matching videos.</div></div>
  <div class="card"><h3 style="margin-top:0">Voice, branding &amp; approval</h3>
   <div class="row" style="justify-content:flex-start"><button class="btn" style="flex:0;white-space:nowrap" onclick="preset(${b.id})">Fill accounting-teacher preset</button></div>
   <label>About (the AI reads this)</label><textarea id="s_about">${v('ap_about')}</textarea>
   <label>Audience</label><input id="s_aud" value="${v('audience')}" placeholder="Who the posts are for">
   <label>House rules (accuracy, terms, style)</label><textarea id="s_rules" style="min-height:90px">${v('cp_rules')}</textarea>
   <label>Advert / call to action (used on promo days)</label><textarea id="s_cta" style="min-height:70px">${v('cta')}</textarea>
   <div class="row"><div><label>Tone</label><input id="s_tone" value="${v('ap_tone')}"></div><div><label>Language</label><input id="s_lang" value="${v('ap_lang','English')}"></div><div><label>Timezone</label><input id="s_tz" value="${esc(b.ap_tz==='UTC'?TZ:b.ap_tz)}"></div></div>
   <div class="row"><div><label>WhatsApp number (shown on cards)</label><input id="s_wa" value="${v('whatsapp')}" placeholder="+92 3xx xxxxxxx"></div><div><label>Handle (card footer)</label><input id="s_handle" value="${v('handle')}" placeholder="@YourHandle"></div><div><label>Card colour</label><input id="s_color" type="color" value="${v('color','#12355b')||'#12355b'}" style="height:42px"></div></div>
   <div class="row"><div><label>Default days per topic</label><input id="s_days" type="number" min="1" max="60" value="${b.cp_days}"></div><div><label>Default post time</label><input id="s_time" type="time" value="${v('cp_time','18:00')}"></div>
    <div><label>Send for approval (hours before)</label><input id="s_lead" type="number" min="0" max="72" value="${b.cp_lead}"></div><div><label>Advert every N days (0 = never)</label><input id="s_promo" type="number" min="0" max="30" value="${b.cp_promo}"></div></div>
   <label class="chip" style="display:inline-flex;margin-top:10px"><input type="checkbox" id="s_auto" ${+b.cp_auto?'checked':''}> Post anyway if nobody replies by the planned time</label>
   <h4 style="margin-bottom:4px">Discord approval ${b.dc_ready?'<span class="pill ready">connected</span>':''}</h4>
   <details ${b.dc_ready?'':'open'}><summary>How to set up the Discord bot (5 minutes, free)</summary><ol class="steps">
    <li>Open <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">discord.com/developers/applications</a> → <b>New Application</b> → <b>Bot</b> → <b>Reset Token</b>, then copy the token into the box below.</li>
    <li>On the same Bot page, turn on <b>Message Content Intent</b> and save.</li>
    <li>Use the link that appears under the token box to add the bot to your server.</li>
    <li>In Discord: User Settings → Advanced → turn on <b>Developer Mode</b>. Right-click your approvals channel → <b>Copy Channel ID</b>.</li>
    <li>Save, then press <b>Send test</b>.</li></ol></details>
   <div class="row"><div><label>Bot token ${b.dc_ready?'(saved — leave blank to keep; type a space to remove)':''}</label><input id="s_dct" type="password" autocomplete="off"><div class="hint" id="dcinv"></div></div>
    <div><label>Channel ID</label><input id="s_dcc" value="${v('dc_channel')}" placeholder="e.g. 1234567890123456789"></div></div>
   <div class="row" style="margin-top:12px;justify-content:flex-start"><button class="btn pri" style="flex:0" onclick="saveStudio(${b.id})">Save</button><button class="btn" style="flex:0;white-space:nowrap" onclick="dcTest(${b.id})">Send test to Discord</button></div>
   ${b.ap_msg&&/^Discord/.test(b.ap_msg)?`<div class="hint" style="color:var(--bad)">${esc(b.ap_msg)}</div>`:''}</div>`;
}
function tlCard(c,posts){
  const made=posts.filter(p=>p.campaign_id==c.id),pct=Math.round(100*c.made/Math.max(1,c.days));
  const err=c.retry_at>S.now&&c.msg?`<div class="meta" style="color:var(--bad)">⚠️ ${esc(c.msg)} — retrying automatically</div>`:'';
  return `<div class="post"><div class="row" style="align-items:center"><div style="flex:3"><b>${esc(c.topic)}</b> ${c.level?`<span class="pill">${esc(c.level)}</span>`:''} <span class="pill ${c.status}">${c.status}</span>
    <div class="meta">${c.made}/${c.days} written · daily from ${esc(c.start_day)} at ${esc(c.post_time)}</div>${err}</div>
   <span style="flex:0;white-space:nowrap;text-align:right">${['planning','active'].includes(c.status)?`<button class="btn" onclick="cpAct('campaign_stop',${c.id})">Stop</button> `:''}<button class="btn dng" onclick="cpAct('campaign_delete',${c.id})">Delete</button></span></div>
   <div class="bar"><i style="width:${pct}%"></i></div>
   ${c.plan.length?`<details><summary>Plan (${c.plan.length} days)</summary><ol class="plan">${c.plan.map(d=>{const p=made.find(x=>x.day==d.day);
     return `<li><b>${esc(d.title)}</b> <span class="meta">${esc(d.type)}</span> ${p?`<span class="pill ${p.status}">${p.status==='draft'?'awaiting approval':p.status}</span>`:''}<div class="meta">${esc(d.angle)}${d.video?` · <a href="${esc(d.video)}" target="_blank" rel="noopener">video</a>`:''}</div></li>`}).join('')}</ol></details>`:''}</div>`;
}
function studioVals(id){const g=k=>$('#s_'+k);
  return {id,ap_about:g('about').value,audience:g('aud').value,cp_rules:g('rules').value,cta:g('cta').value,ap_tone:g('tone').value,ap_lang:g('lang').value,ap_tz:g('tz').value.trim(),
    whatsapp:g('wa').value,handle:g('handle').value,color:g('color').value,cp_days:+g('days').value,cp_time:g('time').value,cp_lead:+g('lead').value,cp_promo:+g('promo').value,
    cp_auto:g('auto').checked,cp_queue:g('queue').value,dc_token:g('dct').value,dc_channel:g('dcc').value.trim()}}
async function saveStudio(id){try{await api('studio_save',studioVals(id));toast('Saved');await load()}catch(e){toast(e.message)}}
async function dcTest(id){try{await api('studio_save',studioVals(id));toast('Sending a test to Discord…');await api('dc_test',{id});toast('Sent ✓ Check your Discord channel.');await load()}catch(e){toast(e.message)}}
async function tlStart(id){const t=$('#tt').value.trim();if(!t)return toast('Type a topic first');const g=$('#tgo');g.disabled=true;toast('Planning the timeline… this takes 20–60 seconds');
  try{const j=await api('campaign_start',{brand_id:id,topic:t,level:$('#tlv').value,days:+$('#tdy').value,start:$('#tsd').value,time:$('#ttm').value});
    toast(j.status==='planning'?'Saved, but the AI could not plan it yet ('+(j.msg||'error')+'). It retries automatically.':'Timeline planned ✓'+(S.brands.find(b=>b.id==id).dc_ready?' Watch Discord for the first post.':''));await load()}
  catch(e){toast(e.message);g.disabled=false}}
async function cpAct(a,id){if(a==='campaign_delete'&&!confirm('Delete this timeline and its unapproved drafts? Approved and sent posts stay.'))return;try{await api(a,{id});await load()}catch(e){toast(e.message)}}
async function ytAdd(id){const u=$('#yt').value.trim();if(!u)return;toast('Reading the channel…');try{const j=await api('youtube_add',{brand_id:id,channel:u});toast('Connected: '+j.videos+' recent videos found. New uploads will become posts.');await load()}catch(e){toast(e.message)}}
function dcInvite(){const t=$('#s_dct').value.trim();let link='';
  try{const id=atob(t.split('.')[0].replace(/-/g,'+').replace(/_/g,'/'));if(/^\d{15,22}$/.test(id))link='https://discord.com/oauth2/authorize?client_id='+id+'&scope=bot&permissions=117824'}catch(e){}
  $('#dcinv').innerHTML=link?`<a href="${link}" target="_blank" rel="noopener">Add this bot to your server →</a>`:''}
function preset(id){const b=S.brands.find(x=>x.id==id),set=(k,val)=>{const el=$('#s_'+k);if(el&&!el.value.trim())el.value=val};
  set('about',`${b.name}: Cambridge O Level, IGCSE and A Level Accounting lessons, plus university-level accounting. YouTube channel with full lectures, Shorts and exam tips. Offers online tuition.`);
  set('aud','Cambridge O Level (7707), IGCSE (0452) and A Level (9706) Accounting students in Pakistan and abroad, their parents, and university accounting students (B.Com, BBA, ACCA foundation).');
  set('rules','Every accounting fact, double entry, format and number must be correct for the current Cambridge syllabus.\nUse Cambridge/IFRS terms: inventory, trade receivables, trade payables, non-current assets, income statement, statement of financial position, irrecoverable debts.\nWorked examples must add up: check the arithmetic.\nSimple English for 15-18 year olds, short sentences.\nIf unsure about a rule, pick a simpler point you are certain of.');
  set('cta','Online Accounting classes for the May/June 2027 exams (O Level, IGCSE and A Level). Message on WhatsApp for timings and fees.');
  $('#s_tone').value='warm, clear and encouraging teacher; exam-focused';
  toast('Preset filled in empty boxes. Add your own details (experience, city, WhatsApp), then Save.')}
// ---------- Businesses, connections, autopilot ----------
function accounts(){
  const op=Object.entries(S.oauth);
  return `<div class="card"><h3 style="margin-top:0">Add business</h3><div class="row"><input id="bn" placeholder="e.g. Hashmi Media"><button class="btn pri" style="flex:0" onclick="addBrand()">Add</button></div></div>
  ${S.brands.map(b=>{const ac=S.accounts.filter(a=>a.brand_id==b.id),sr=S.sources.filter(s=>s.brand_id==b.id);return `<div class="card"><div class="row" style="align-items:center"><h3 style="margin:0">${esc(b.name)}</h3>
   <span style="text-align:right"><button class="btn" onclick="renBrand(${b.id})">Rename</button> <button class="btn dng" onclick="confirm('Delete business and ALL its posts and accounts?')&&act('brand_delete',${b.id})">Delete</button></span></div>
   <label>Connect a network</label><div class="row" style="justify-content:flex-start">
    ${op.map(([k,v])=>`<a class="btn" style="flex:0;white-space:nowrap" href="oauth.php?start=${k}&brand=${b.id}">Connect ${esc(v)}</a>`).join('')}
    ${op.length?'':'<span class="hint">One-click Connect buttons appear once the server owner adds app keys in config.php. Until then, use manual keys below.</span>'}</div>
   ${ac.map(a=>`<div class="post"><b>${esc(S.platforms[a.platform].label)}</b> ${a.label?'· '+esc(a.label):''} <span class="pill ${a.state}">${{ready:'connected',keys:'not connected',expired:'expired — reconnect'}[a.state]}</span>
    <button class="btn" onclick="accForm(${b.id},'${a.platform}',${a.id})">Manual keys</button> <button class="btn dng" onclick="confirm('Remove account?')&&act('account_delete',${a.id})">Remove</button></div>`).join('')}
   <div class="row" style="margin-top:10px"><select id="pl${b.id}">${Object.entries(S.platforms).map(([k,v])=>`<option value="${k}">${esc(v.label)}</option>`).join('')}</select>
   <button class="btn" style="flex:0;white-space:nowrap" onclick="accForm(${b.id},$('#pl${b.id}').value,0)">+ Add with keys</button></div><div id="af${b.id}"></div>
   <details ${b.ap_enabled?'open':''}><summary>🤖 Autopilot — writes &amp; posts for you ${b.ap_enabled?'<span class="pill ready">on</span>':''}</summary>${apForm(b)}</details>
   <details><summary>📡 Blog feeds (${sr.length})</summary>${sr.map(s=>`<div class="post">${esc(s.url)} <span class="pill ${s.mode}">${s.mode}</span> <span class="meta">${esc(s.last_msg||'')}</span> <button class="btn dng" onclick="act('source_delete',${s.id})">Remove</button></div>`).join('')}
    <div class="row" style="margin-top:8px"><input id="sf${b.id}" placeholder="https://yoursite.com/feed"><button class="btn" style="flex:0" onclick="addSrc(${b.id})">Add</button></div></details></div>`}).join('')}
  <div class="card hint"><b>Scheduler:</b> cPanel → Cron Jobs → every minute: <code>/usr/local/bin/php /home/USER/public_html/hub/cron.php</code> — or free <i>cron-job.org</i> hitting <code>cron.php?key=YOUR_CRON_KEY</code>.</div>`;
}
function apForm(b){
  const d=b.ap_days.split(',');
  return `<div id="ap${b.id}"><label class="chip" style="display:inline-flex"><input type="checkbox" class="ap_enabled" ${b.ap_enabled?'checked':''}> Turn Autopilot on</label>
  <div class="row"><div><label>When ready</label><select class="ap_mode"><option value="review" ${b.ap_mode==='review'?'selected':''}>Save as draft for my approval (safer)</option><option value="auto" ${b.ap_mode==='auto'?'selected':''}>Publish automatically</option></select></div>
  <div><label>Time</label><input class="ap_time" type="time" value="${b.ap_time}"></div>
  <div><label>Timezone</label><input class="ap_tz" value="${esc(b.ap_tz==='UTC'?TZ:b.ap_tz)}"></div></div>
  <label>Days</label><div class="chips">${DAYN.map((n,i)=>`<label class="chip"><input type="checkbox" class="ap_day" value="${i}" ${d.includes(String(i))?'checked':''}>${n}</label>`).join('')}</div>
  <label>About this business (the AI reads this)</label><textarea class="ap_about" placeholder="What you sell, who your customers are, what makes you different…">${esc(b.ap_about)}</textarea>
  <label>Topics to rotate (one per line or comma separated)</label><textarea class="ap_topics" style="min-height:70px" placeholder="tips, behind the scenes, offers, FAQs…">${esc(b.ap_topics)}</textarea>
  <div class="row"><div><label>Tone</label><input class="ap_tone" value="${esc(b.ap_tone)}"></div><div><label>Language</label><input class="ap_lang" value="${esc(b.ap_lang)}"></div></div>
  <label class="chip" style="display:inline-flex;margin-top:10px"><input type="checkbox" class="ap_image" ${b.ap_image?'checked':''}> Also generate a free AI image (needed for Instagram)</label>
  <div class="hint">${S.ai?'':'<b>Add a Claude or Gemini key in Settings first.</b> '}${b.ap_msg?'Last run: '+esc(b.ap_msg):''}</div>
  <div class="row" style="margin-top:12px;justify-content:flex-start"><button class="btn pri" style="flex:0" onclick="saveAp(${b.id})">Save autopilot</button><button class="btn" style="flex:0;white-space:nowrap" onclick="apNow(${b.id})">Generate one now</button></div></div>`;
}
function apVals(id){const r=$('#ap'+id),g=c=>r.querySelector('.'+c);
  return {ap_enabled:g('ap_enabled').checked,ap_mode:g('ap_mode').value,ap_time:g('ap_time').value,ap_tz:g('ap_tz').value.trim(),
    ap_days:[...r.querySelectorAll('.ap_day:checked')].map(x=>x.value).join(','),ap_about:g('ap_about').value,ap_topics:g('ap_topics').value,
    ap_tone:g('ap_tone').value,ap_lang:g('ap_lang').value,ap_image:g('ap_image').checked}}
async function saveAp(id){const b=S.brands.find(x=>x.id==id);try{await api('brand_save',{id,name:b.name,ap:apVals(id)});toast('Autopilot saved');load()}catch(e){toast(e.message)}}
async function apNow(id){const b=S.brands.find(x=>x.id==id);try{await api('brand_save',{id,name:b.name,ap:apVals(id)});toast('Writing a post…');await api('autopilot_now',{id});toast('Done — see Posts');tab='queue';load()}catch(e){toast(e.message)}}
async function addSrc(id){const u=$('#sf'+id).value.trim();if(!u)return;try{await api('source_add',{brand_id:id,url:u});load()}catch(e){toast(e.message)}}
async function addBrand(){const n=$('#bn').value.trim();if(!n)return;try{await api('brand_save',{name:n});load()}catch(e){toast(e.message)}}
async function renBrand(id){const n=prompt('New name',S.brands.find(b=>b.id==id).name);if(n)try{await api('brand_save',{id,name:n});load()}catch(e){toast(e.message)}}
function accForm(bid,pl,id){
  const a=id?S.accounts.find(x=>x.id==id):null,P=S.platforms[pl];
  $('#af'+bid).innerHTML=`<div class="card" style="margin-top:10px"><b>${esc(P.label)}</b><label>Label (optional)</label><input id="lb" value="${esc(a?a.label:'')}">
  ${P.fields.map(([k,l,sec])=>`<label>${esc(l)}</label><input class="cf" data-k="${k}" ${sec?'type="password" placeholder="'+(a?'leave blank to keep current':'')+'"':''} value="${a&&!sec?esc(a.values[k]||''):''}">`).join('')}
  <div class="row" style="margin-top:12px"><button class="btn pri" id="sv">Save</button></div></div>`;
  $('#sv').onclick=async()=>{const creds={};document.querySelectorAll('.cf').forEach(i=>creds[i.dataset.k]=i.value);
    try{await api('account_save',{id,brand_id:bid,platform:pl,label:$('#lb').value,creds});toast('Saved');load()}catch(e){toast(e.message)}};
}
// ---------- Settings ----------
function settings(){
  return `<div class="card"><h3 style="margin-top:0">Settings</h3><div class="meta">Signed in as ${esc(S.email)}</div>
  <h4>AI that writes your posts</h4>
  <div class="row"><div><label>Provider</label><select id="aip" onchange="aiProv()">${Object.entries(S.providers).map(([k,v])=>`<option value="${k}" ${S.ai_cfg.provider===k?'selected':''}>${esc(v.label)}</option>`).join('')}</select></div>
   <div><label>Model <span class="hint" id="aimh"></span></label><input id="aim" value="${esc(S.ai_cfg.model)}"></div></div>
  <div id="aibw" style="display:none"><label>Base URL (OpenAI-compatible, https://…/v1)</label><input id="aib2" value="${esc(S.ai_cfg.base)}"></div>
  <label>API key ${S.own_key?'(saved)':''}</label>
  <input id="gk" type="password" placeholder="${S.own_key?'leave blank to keep; type a space then save to remove':'paste your key for the provider above'}">
  <h4 style="margin-bottom:0">Backup AI <span class="hint">(optional — used automatically if the main one errors or hits its free limit)</span></h4>
  <div class="row"><div><label>Provider</label><select id="aip2" onchange="aiProv2()"><option value="">None</option>${Object.entries(S.providers).map(([k,v])=>`<option value="${k}" ${S.ai2_cfg.provider===k?'selected':''}>${esc(v.label)}</option>`).join('')}</select></div>
   <div><label>Model <span class="hint" id="aimh2"></span></label><input id="aim2" value="${esc(S.ai2_cfg.model)}"></div></div>
  <div id="aibw2" style="display:none"><label>Base URL (https://…/v1)</label><input id="aib22" value="${esc(S.ai2_cfg.base)}"></div>
  <div id="aikw2"><label>Backup API key ${S.ai2_cfg.key_saved?'(saved — leave blank to keep)':''}</label><input id="gk2" type="password"></div>
  <div class="hint">Free tiers: Gemini (aistudio.google.com/apikey), Groq (console.groq.com), Mistral, OpenRouter's :free models. Paid: Claude, OpenAI, DeepSeek. Model names change often — edit the model box if one is retired.</div>
  <div class="row" style="margin-top:12px;justify-content:flex-start"><button class="btn pri" style="flex:0;white-space:nowrap" onclick="saveKey()">Save</button><button class="btn" style="flex:0;white-space:nowrap" onclick="testAi()">Test AI</button></div>
  <h4>Change password</h4><div class="row"><input id="op" type="password" placeholder="Current password"><input id="np" type="password" placeholder="New password (8+)"><button class="btn" style="flex:0" onclick="chPw()">Change</button></div>
  <h4>Danger zone</h4><div class="row"><input id="dp" type="password" placeholder="Your password"><button class="btn dng" style="flex:1 1 220px" onclick="delMe()">Delete my account &amp; all data</button></div></div>`;
}
function aiProv(){const p=S.providers[$('#aip').value];$('#aim').placeholder=p.model||'model name';$('#aimh').textContent=p.model?'(default: '+p.model+')':'';
  $('#aibw').style.display=$('#aip').value==='custom'?'block':'none'}
function aiProv2(){const v=$('#aip2').value,p=S.providers[v];$('#aikw2').style.display=$('#aibw2').style.display=v?'':'none';
  if(v){$('#aim2').placeholder=p.model||'model name';$('#aimh2').textContent=p.model?'(default: '+p.model+')':'';$('#aibw2').style.display=v==='custom'?'block':'none'}}
async function saveKey(){try{const custom=$('#aip').value==='custom',v2=$('#aip2').value;
  await api('settings',{gemini_key:$('#gk').value,ai_provider:$('#aip').value,ai_model:$('#aim').value,ai_base:custom?$('#aib2').value:'',
    ai2_provider:v2,ai2_model:$('#aim2').value,ai2_base:v2==='custom'?$('#aib22').value:'',ai2_key:$('#gk2').value});toast('Saved');await load()}catch(e){toast(e.message)}}
async function testAi(){try{toast('Testing…');const j=await api('ai_test');
  toast(Object.entries(j.results).map(([r,x])=>(r==='main'?'Main':'Backup')+' ('+x.provider+'): '+(x.ok?'works':'FAILED — '+x.error)).join('  |  '))}catch(e){toast(e.message)}}
async function chPw(){try{await api('settings',{old_password:$('#op').value,new_password:$('#np').value});toast('Password changed')}catch(e){toast(e.message)}}
async function delMe(){if(!confirm('Permanently delete your account, businesses, tokens and posts?'))return;try{await api('delete_me',{password:$('#dp').value});csrf='';showLogin()}catch(e){toast(e.message)}}
boot();
</script></body></html>
