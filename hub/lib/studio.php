<?php
// Studio: topic timelines (daily series), branded image cards, Discord approval bot, YouTube channel import.
// Loaded by core.php. Everything here runs from cron.php or the API in index.php.

const DC_API = 'https://discord.com/api/v10';

// ---------- Schema upgrade ----------
function studio_migrate(PDO $pdo): void {
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 2) return;
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS campaigns(
        id INTEGER PRIMARY KEY, brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
        topic TEXT NOT NULL, level TEXT NOT NULL DEFAULT '', days INTEGER NOT NULL DEFAULT 7,
        start_day TEXT NOT NULL, post_time TEXT NOT NULL DEFAULT '18:00', plan TEXT,
        made INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'planning',
        retry_at INTEGER NOT NULL DEFAULT 0, msg TEXT, created_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS videos(
        brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE, vid TEXT NOT NULL,
        title TEXT, url TEXT, short INTEGER NOT NULL DEFAULT 0, published INTEGER, PRIMARY KEY(brand_id, vid));
    ");
    $add = [
        'brands' => ['audience TEXT', 'cp_rules TEXT', 'cta TEXT', 'whatsapp TEXT', 'handle TEXT', 'color TEXT', 'cp_queue TEXT',
            'cp_days INTEGER NOT NULL DEFAULT 7', "cp_time TEXT NOT NULL DEFAULT '18:00'", 'cp_lead INTEGER NOT NULL DEFAULT 12',
            'cp_auto INTEGER NOT NULL DEFAULT 0', 'cp_promo INTEGER NOT NULL DEFAULT 5',
            'dc_token TEXT', 'dc_channel TEXT', 'dc_last TEXT', 'dc_ident INTEGER NOT NULL DEFAULT 0', 'dc_msg TEXT'],
        'posts' => ['campaign_id INTEGER', 'day INTEGER', 'variants TEXT', 'card TEXT', 'dc_msg TEXT'],
        'sources' => ["kind TEXT NOT NULL DEFAULT 'rss'"],
    ];
    foreach ($add as $t => $cols) foreach ($cols as $col) {
        try { $pdo->exec("ALTER TABLE $t ADD COLUMN $col"); } catch (Throwable $e) {}
    }
    $pdo->exec('PRAGMA user_version=2');
}

// ---------- Small helpers ----------
function brand_row(int $id): array { return one('SELECT * FROM brands WHERE id=?', [$id]); }
function brand_tz(array $b): DateTimeZone {
    try { return new DateTimeZone($b['ap_tz'] ?: 'UTC'); } catch (Throwable $e) { return new DateTimeZone('UTC'); }
}
function fmt_when(int $ts, array $b): string {
    return (new DateTime('@' . $ts))->setTimezone(brand_tz($b))->format('D j M, g:i A');
}
function studio_json(string $raw): array {
    $raw = trim(preg_replace('/^```(?:json)?|```$/m', '', $raw));
    $a = strpos($raw, '{'); $z = strrpos($raw, '}');
    $j = ($a !== false && $z !== false) ? json_decode(substr($raw, $a, $z - $a + 1), true) : null;
    if (!is_array($j)) throw new RuntimeException('AI reply was not valid JSON');
    return $j;
}
function studio_quota_ok(int $userId): bool {
    $n = db()->prepare("SELECT COUNT(*) FROM posts p JOIN brands x ON x.id=p.brand_id
        WHERE x.user_id=? AND p.origin IN('autopilot','campaign','youtube') AND p.created_at>?");
    $n->execute([$userId, time() - 86400]);
    return $n->fetchColumn() < (int)(cfg('max_ai_per_user_day') ?: 12);
}
function save_upload(string $raw, string $ext = 'jpg'): string {
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    file_put_contents(UPLOAD_DIR . '/' . $name, $raw);
    return $name;
}

// ---------- AI writing ----------
function studio_voice(array $b): string {
    return "You write social media content for \"{$b['name']}\".\n"
        . 'About: ' . ($b['ap_about'] ?: ($b['website'] ?: 'n/a')) . "\n"
        . 'Audience: ' . ($b['audience'] ?: 'general public') . "\n"
        . "Language: {$b['ap_lang']}. Tone: {$b['ap_tone']}.\n"
        . (trim((string)$b['cp_rules']) !== '' ? "House rules (follow strictly):\n{$b['cp_rules']}\n" : '')
        . "Never invent statistics, results, testimonials, prices or offers. No placeholders like [Name].";
}

/** Writes one post in every format. $task describes what to write; $fix is the reviewer's change request. */
function studio_write(array $b, string $task, string $fix = '', array $old = []): array {
    $wa = trim((string)$b['whatsapp']);
    $prompt = studio_voice($b) . "\n\nTASK:\n$task\n"
        . ($fix !== '' ? "\nThe owner reviewed the previous version and asked for this change (apply it exactly):\n\"$fix\"\n"
            . 'Previous caption: ' . mb_substr($old['caption'] ?? '', 0, 900) . "\n" : '')
        . "\nReply ONLY with JSON in this shape:\n"
        . "{\"card\":{\"kicker\":\"2-3 word label in capitals, max 22 characters\",\"title\":\"max 60 characters\","
        . "\"lines\":[\"3 to 6 short lines, max 70 characters each\"],\"note\":\"optional footnote, max 80 characters\"},"
        . "\"caption\":\"...\",\"linkedin\":\"...\",\"whatsapp\":\"...\"}\n"
        . "card = the text printed on the image: a clean summary someone understands in 5 seconds. "
        . "For a multiple-choice question write lines as \"A) ...\", \"B) ...\". For wrong vs right write lines starting with \"X \" (wrong) and \"OK \" (right).\n"
        . "caption = Instagram/Facebook caption: a hook on the first line, 60-150 words explaining the point, end with a question or call to action, "
        . "then a blank line and 5-8 relevant hashtags. Links are not clickable on Instagram, so say \"link in bio\" instead of pasting a URL. "
        . "For a multiple-choice question ask people to comment their answer, then give the answer with a one-line reason after the hashtags under \"Answer:\".\n"
        . "linkedin = 120-220 words in a professional teacher's voice, useful for students, parents and university learners; may include a URL; 3 hashtags.\n"
        . 'whatsapp = under 70 words of plain text with the key takeaway' . ($wa ? " and the line: WhatsApp $wa" : '') . '.';
    $j = studio_json(ai_run((int)$b['user_id'], $prompt, true, 2500));
    $card = is_array($j['card'] ?? null) ? $j['card'] : [];
    $out = [
        'caption' => trim((string)($j['caption'] ?? '')),
        'linkedin' => trim((string)($j['linkedin'] ?? '')),
        'whatsapp' => trim((string)($j['whatsapp'] ?? '')),
        'card' => ['kicker' => mb_substr(trim((string)($card['kicker'] ?? '')), 0, 30), 'title' => mb_substr(trim((string)($card['title'] ?? '')), 0, 90),
            'lines' => array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim((string)$l), 0, 110), (array)($card['lines'] ?? [])))), 0, 7),
            'note' => mb_substr(trim((string)($card['note'] ?? '')), 0, 110)],
    ];
    if ($out['caption'] === '') throw new RuntimeException('AI returned an empty caption');
    return $out;
}

// ---------- Branded image card (1080x1350, Instagram 4:5) ----------
function studio_font(string $w): string { return __DIR__ . "/fonts/Poppins-$w.ttf"; }
function hex_rgb(?string $hex): array {
    $h = ltrim((string)$hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $h)) $h = '12355b';
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}
function card_clean(string $t): string {   // Poppins has Latin glyphs only; drop emoji and symbols it cannot draw
    $t = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}
function text_w(string $t, string $font, float $size): int { $b = imagettfbbox($size, 0, $font, $t); return $b[2] - $b[0]; }
function wrap_text(string $t, string $font, float $size, int $max): array {
    $lines = []; $cur = '';
    foreach (preg_split('/\s+/u', trim($t)) as $w) {
        $try = $cur === '' ? $w : "$cur $w";
        if ($cur !== '' && text_w($try, $font, $size) > $max) { $lines[] = $cur; $cur = $w; } else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/** Draws the card; returns the upload filename, or null if GD/FreeType is missing. $thumb = path to a 16:9 JPEG (video posts). */
function studio_card(array $card, array $b, ?string $thumb = null): ?string {
    if (!function_exists('imagettftext') || !is_file(studio_font('Bold'))) return null;
    $W = 1080; $H = 1350; $M = 80;
    $im = imagecreatetruecolor($W, $H);
    imageantialias($im, true);
    [$r, $g, $bl] = hex_rgb($b['color'] ?? '');
    $brand = imagecolorallocate($im, $r, $g, $bl);
    $bg = imagecolorallocate($im, 250, 248, 243);
    $ink = imagecolorallocate($im, 28, 32, 40);
    $mute = imagecolorallocate($im, 105, 110, 120);
    $white = imagecolorallocate($im, 255, 255, 255);
    $gold = imagecolorallocate($im, 242, 183, 5);
    $red = imagecolorallocate($im, 200, 55, 45);
    $green = imagecolorallocate($im, 40, 140, 75);
    $tint = fn($c) => (int)min(255, $c + (255 - $c) * 0.88);
    $soft = imagecolorallocate($im, $tint($r), $tint($g), $tint($bl));
    $B = studio_font('Bold'); $Md = studio_font('Medium'); $Rg = studio_font('Regular');
    imagefilledrectangle($im, 0, 0, $W, $H, $bg);

    // header band
    imagefilledrectangle($im, 0, 0, $W, 128, $brand);
    imagettftext($im, 34, 0, $M, 80, $white, $B, card_clean($b['name']));
    // footer band
    imagefilledrectangle($im, 0, $H - 112, $W, $H, $brand);
    $left = card_clean((string)($b['handle'] ?: ($b['website'] ?? '')));
    $right = trim((string)$b['whatsapp']) !== '' ? 'WhatsApp ' . card_clean($b['whatsapp']) : '';
    for ($fs = 26; $fs > 15; $fs--) if (text_w($left, $Md, $fs) + text_w($right, $Md, $fs) + 48 <= $W - 2 * $M) break;
    if ($left !== '') imagettftext($im, $fs, 0, $M, $H - 46, $white, $Md, $left);
    if ($right !== '') imagettftext($im, $fs, 0, $W - $M - text_w($right, $Md, $fs), $H - 46, $gold, $Md, $right);

    $title = card_clean($card['title'] ?? '');
    $lines = array_values(array_filter(array_map('card_clean', $card['lines'] ?? [])));
    $note = card_clean($card['note'] ?? '');
    $kicker = mb_strtoupper(card_clean($card['kicker'] ?? ''));
    $top = 178; $bottom = $H - 112 - 50;
    $thumbFull = 0;
    if ($thumb && is_file($thumb) && ($ti = @imagecreatefromjpeg($thumb))) { $thumbFull = (int)(($W - 2 * $M) * 9 / 16); $lines = array_slice($lines, 0, 3); }

    $measure = function (float $s, array $lines, string $note) use ($title, $kicker, $thumbFull, $B, $Md, $Rg, $W, $M): array {
        $ts = 64 * $s; $ls = 38 * $s; $ns = 27 * $s;
        $thumbH = $thumbFull ? (int)($thumbFull * max(0.6, $s)) : 0;
        $tl = wrap_text($title, $B, $ts, $W - 2 * $M);
        $body = [];
        foreach ($lines as $l) {
            $kind = 'dot'; $txt = $l;
            if (preg_match('/^X\s+(.*)$/u', $l, $m)) { $kind = 'x'; $txt = $m[1]; }
            elseif (preg_match('/^OK\s+(.*)$/u', $l, $m)) { $kind = 'ok'; $txt = $m[1]; }
            elseif (preg_match('/^([A-E])[\)\.:]\s*(.*)$/u', $l, $m)) { $kind = 'opt:' . $m[1]; $txt = $m[2]; }
            $body[] = [$kind, wrap_text($txt, $Md, $ls, $W - 2 * $M - 70)];
        }
        $nl = $note !== '' ? wrap_text($note, $Rg, $ns, $W - 2 * $M) : [];
        $h = ($kicker !== '' ? 80 : 0) + ($thumbH ? $thumbH + 36 : 0) + count($tl) * $ts * 1.36 + 50;
        foreach ($body as [, $wl]) $h += count($wl) * $ls * 1.45 + 22 * $s;
        $h += $nl ? 20 + count($nl) * $ns * 1.5 : 0;
        return compact('h', 'tl', 'body', 'nl', 'ts', 'ls', 'ns', 'thumbH', 's');
    };
    // shrink until it fits; if even the smallest size overflows, drop the footnote, then the last lines
    while (true) {
        for ($s = 1.0; $s >= 0.55; $s -= 0.05) { $L = $measure($s, $lines, $note); if ($top + $L['h'] <= $bottom) break 2; }
        if ($note !== '') $note = '';
        elseif (count($lines) > 1) array_pop($lines);
        else break;
    }
    ['h' => $h, 'tl' => $tl, 'body' => $body, 'nl' => $nl, 'ts' => $ts, 'ls' => $ls, 'ns' => $ns, 'thumbH' => $thumbH, 's' => $s] = $L;
    $k = max(0.7, $s);   // icon scale
    $y = $top + max(0, (int)(($bottom - $top - $h) / 3));   // nudge short cards toward the optical centre

    if ($kicker !== '') {
        $kw = text_w($kicker, $B, 24) + 48;
        imagefilledrectangle($im, $M + 26, $y, $M + $kw - 26, $y + 52, $gold);
        imagefilledellipse($im, $M + 26, $y + 26, 52, 52, $gold);
        imagefilledellipse($im, $M + $kw - 26, $y + 26, 52, 52, $gold);
        imagettftext($im, 24, 0, $M + 24, $y + 37, $ink, $B, $kicker);
        $y += 80;
    }
    if ($thumbH) {
        $tw = (int)($thumbH * 16 / 9);
        imagecopyresampled($im, $ti, $M + (int)(($W - 2 * $M - $tw) / 2), $y, 0, 0, $tw, $thumbH, imagesx($ti), imagesy($ti));
        $y += $thumbH + 36;
    }
    foreach ($tl as $t) { $y += (int)($ts * 1.36); imagettftext($im, $ts, 0, $M, $y - (int)($ts * 0.28), $ink, $B, $t); }
    $y += 18;
    imagefilledrectangle($im, $M, $y, $M + 120, $y + 7, $brand);
    $y += 32;
    foreach ($body as [$kind, $wl]) {
        $cy = $y + (int)($ls * 0.62);
        $cx = $M + 20;
        $q = fn($n) => (int)round($n * $k);
        if ($kind === 'x' || $kind === 'ok') {
            imagefilledellipse($im, $cx, $cy, $q(40), $q(40), $kind === 'x' ? $red : $green);
            imageantialias($im, false);          // GD ignores line thickness while antialiasing is on
            imagesetthickness($im, max(3, $q(5)));
            if ($kind === 'x') { imageline($im, $cx - $q(8), $cy - $q(8), $cx + $q(8), $cy + $q(8), $white); imageline($im, $cx + $q(8), $cy - $q(8), $cx - $q(8), $cy + $q(8), $white); }
            else { imageline($im, $cx - $q(9), $cy + $q(1), $cx - $q(3), $cy + $q(8), $white); imageline($im, $cx - $q(3), $cy + $q(8), $cx + $q(10), $cy - $q(7), $white); }
            imagesetthickness($im, 1);
            imageantialias($im, true);
        } elseif (str_starts_with($kind, 'opt:')) {
            imagefilledellipse($im, $cx, $cy, $q(46), $q(46), $soft);
            $Lt = substr($kind, 4);
            imagettftext($im, 22 * $k, 0, $cx - (int)(text_w($Lt, $B, 22 * $k) / 2) - 1, $cy + $q(9), $brand, $B, $Lt);
        } else {
            imagefilledellipse($im, $cx, $cy, $q(16), $q(16), $brand);
        }
        foreach ($wl as $i => $t) {
            $y += (int)($ls * 1.45);
            imagettftext($im, $ls, 0, $M + 70, $y - (int)($ls * 0.35), $ink, $Md, $t);
        }
        $y += (int)(22 * $s);
    }
    if ($nl) {
        $y += 20;
        foreach ($nl as $t) { $y += (int)($ns * 1.5); imagettftext($im, $ns, 0, $M, $y - (int)($ns * 0.3), $mute, $Rg, $t); }
    }
    ob_start(); imagejpeg($im, null, 90); $raw = ob_get_clean();
    imagedestroy($im);
    return save_upload($raw);
}

// ---------- Timelines (topic → N daily posts) ----------
function campaign_slot(array $c, int $day, DateTimeZone $tz): int {
    $d = new DateTime($c['start_day'] . ' ' . $c['post_time'], $tz);
    if ($day > 1) $d->modify('+' . ($day - 1) . ' days');
    return $d->getTimestamp();
}

/** Creates a timeline, plans it, and makes the first post if it is already due. Returns the campaign id. */
function campaign_create(array $b, string $topic, string $level = '', int $days = 0, string $start = '', string $time = ''): int {
    $topic = trim(preg_replace('/\s+/', ' ', $topic));
    if ($topic === '' || mb_strlen($topic) > 200) throw new HttpError('Enter a topic (max 200 characters).', 400);
    $days = $days ?: (int)$b['cp_days'];
    if ($days < 1 || $days > 60) throw new HttpError('Days must be between 1 and 60.', 400);
    $time = preg_match('/^\d\d:\d\d$/', $time) ? $time : ($b['cp_time'] ?: '18:00');
    $tz = brand_tz($b);
    if (!preg_match('/^\d{4}-\d\d-\d\d$/', $start)) {   // today if today's slot is still 30+ minutes away, else tomorrow
        $today = new DateTime('now', $tz);
        $slot = new DateTime($today->format('Y-m-d') . " $time", $tz);
        $start = $slot->getTimestamp() > time() + 1800 ? $today->format('Y-m-d') : $today->modify('+1 day')->format('Y-m-d');
    }
    db()->prepare('INSERT INTO campaigns(brand_id,topic,level,days,start_day,post_time,status,created_at) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$b['id'], $topic, mb_substr(trim($level), 0, 80), $days, $start, $time, 'planning', time()]);
    $id = (int)db()->lastInsertId();
    try {
        campaign_tick(one('SELECT * FROM campaigns WHERE id=?', [$id]));               // plan
        campaign_tick(one('SELECT * FROM campaigns WHERE id=?', [$id]));               // first post, if due
    } catch (Throwable $e) {
        db()->prepare('UPDATE campaigns SET retry_at=?, msg=? WHERE id=?')->execute([time() + 300, $e->getMessage(), $id]);
    }
    return $id;
}

function studio_videos_for(int $brandId, string $topic, int $limit = 8): array {
    $q = db()->prepare('SELECT title, url, short FROM videos WHERE brand_id=? ORDER BY published DESC LIMIT 300');
    $q->execute([$brandId]);
    $words = array_filter(preg_split('/\W+/u', mb_strtolower($topic)), fn($w) => mb_strlen($w) > 3);
    $scored = [];
    foreach ($q->fetchAll() as $v) {
        $t = mb_strtolower($v['title']); $s = 0;
        foreach ($words as $w) if (str_contains($t, $w)) $s++;
        if ($s) $scored[] = [$s, $v];
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($x) => $x[1], array_slice($scored, 0, $limit));
}

function campaign_plan(array $c, array $b): void {
    $days = (int)$c['days']; $promo = (int)$b['cp_promo'];
    $vids = implode("\n", array_map(fn($v) => '- ' . $v['title'] . ($v['short'] ? ' (short)' : '') . ' — ' . $v['url'], studio_videos_for((int)$b['id'], $c['topic'])));
    $prompt = studio_voice($b) . "\n\nPlan a $days-day series of daily social media posts.\nTopic: {$c['topic']}\nLevel: " . ($c['level'] ?: 'all levels') . "\n"
        . ($vids ? "The owner's existing YouTube videos (attach the most relevant URL to days where one fits, otherwise leave \"video\" empty):\n$vids\n" : '')
        . "Go step by step from the basics to exam level. Mix these post types: concept (one idea explained), example (short worked numerical example), "
        . "mcq (multiple-choice question), mistake (a common exam mistake and the fix), tip (exam technique), recap (use for the last day)."
        . ($promo > 0 && $days >= $promo ? " Every {$promo}th day is type promo: an advert for: " . ($b['cta'] ?: 'the business') . '.' : '')
        . "\nReply ONLY with JSON: {\"posts\":[{\"day\":1,\"type\":\"concept\",\"title\":\"max 60 characters\",\"angle\":\"one sentence: exactly what this post covers\",\"video\":\"\"}]} with exactly $days items.";
    $j = studio_json(ai_run((int)$b['user_id'], $prompt, true, 3000));
    $posts = array_values(array_filter((array)($j['posts'] ?? []), fn($p) => is_array($p) && trim((string)($p['title'] ?? '')) !== ''));
    if (count($posts) < max(1, (int)ceil($days / 2))) throw new RuntimeException('AI returned too few days in the plan');
    $plan = [];
    for ($i = 0; $i < $days; $i++) {
        $p = $posts[$i] ?? ['type' => 'recap', 'title' => 'Recap: ' . $c['topic'], 'angle' => 'Summarise the key points of the series.'];
        $video = (string)($p['video'] ?? '');
        $plan[] = ['day' => $i + 1, 'type' => mb_substr((string)($p['type'] ?? 'concept'), 0, 20), 'title' => mb_substr(trim((string)$p['title']), 0, 90),
            'angle' => mb_substr(trim((string)($p['angle'] ?? '')), 0, 300), 'video' => preg_match('#^https://(www\.)?youtube\.com/|^https://youtu\.be/#', $video) ? $video : ''];
    }
    db()->prepare("UPDATE campaigns SET plan=?, status='active', msg=? WHERE id=?")->execute([json_encode($plan), 'Planned ' . date('c'), $c['id']]);
}

/** Does at most one unit of work for a campaign (plan it, or write the next post when due). Returns true if it wrote a post. */
function campaign_tick(array $c): bool {
    $b = brand_row((int)$c['brand_id']);
    if ($c['status'] === 'planning') { campaign_plan($c, $b); return false; }
    if ($c['status'] !== 'active') return false;
    $plan = json_decode((string)$c['plan'], true) ?: [];
    $day = (int)$c['made'] + 1;
    if ($day > count($plan)) { campaign_finish($c, $b); return false; }
    $slot = campaign_slot($c, $day, brand_tz($b));
    if (time() < $slot - 3600 * (int)$b['cp_lead']) return false;
    if (!studio_quota_ok((int)$b['user_id'])) return false;
    $item = $plan[$day - 1];
    $prev = implode('; ', array_map(fn($p) => "day {$p['day']}: {$p['title']}", array_slice($plan, 0, $day - 1)));
    $task = "Day $day of a {$c['days']}-day series on \"{$c['topic']}\" (level: " . ($c['level'] ?: 'all levels') . ").\n"
        . "Post type: {$item['type']}. Title: {$item['title']}. What to cover: {$item['angle']}\n"
        . ($prev ? "Earlier days covered: $prev. Do not repeat them.\n" : '')
        . ($item['video'] ? "Related YouTube video by the owner: {$item['video']} — mention it (\"full lesson on YouTube\").\n" : '')
        . ($item['type'] === 'promo' ? 'This is the advert day. Promote: ' . ($b['cta'] ?: 'the business') . ' Stay honest and specific; no invented offers.' : 'End with a soft call to follow for more daily lessons.');
    studio_make_post($b, $task, 'campaign', max($slot, time()), $item['video'] ?: null, null, (int)$c['id'], $day);
    db()->prepare('UPDATE campaigns SET made=made+1, msg=? WHERE id=?')->execute(["Day $day written " . date('c'), $c['id']]);
    if ($day >= count($plan)) campaign_finish(one('SELECT * FROM campaigns WHERE id=?', [$c['id']]), $b);
    return true;
}

/** Parses "Topic | 5 | AS Level" (days and level optional, either order). Returns [topic, days, level]. */
function studio_topic_line(string $line): array {
    $parts = array_map('trim', explode('|', $line));
    $topic = array_shift($parts); $days = 0; $level = '';
    foreach ($parts as $pt) { if (preg_match('/^(\d{1,2})\s*(days?)?$/i', $pt, $n)) $days = (int)$n[1]; elseif ($pt !== '') $level = $pt; }
    return [$topic, $days, $level];
}
/** Queue lines, skipping blanks and "# comment" lines. */
function studio_queue(string $q): array {
    return array_values(array_filter(array_map('trim', explode("\n", $q)), fn($l) => $l !== '' && $l[0] !== '#'));
}
/** Short list of queued topic names for Discord (messages are capped at 2000 characters). */
function studio_queue_names(array $q, int $show = 5): string {
    if (!$q) return 'empty';
    $names = array_map(fn($l) => studio_topic_line($l)[0], array_slice($q, 0, $show));
    return implode(', ', $names) . (count($q) > $show ? ' … and ' . (count($q) - $show) . ' more' : '');
}

function campaign_finish(array $c, array $b): void {
    db()->prepare("UPDATE campaigns SET status='done' WHERE id=?")->execute([$c['id']]);
    $queue = studio_queue((string)$b['cp_queue']);
    if (!$queue) return;
    $line = array_shift($queue);
    db()->prepare('UPDATE brands SET cp_queue=? WHERE id=?')->execute([implode("\n", $queue), $b['id']]);
    [$next, $days, $level] = studio_topic_line($line);
    $after = (new DateTime($c['start_day'], brand_tz($b)))->modify('+' . (int)$c['days'] . ' days')->format('Y-m-d');
    $nid = campaign_create(brand_row((int)$b['id']), $next, $level !== '' ? $level : (string)$c['level'], $days ?: (int)$b['cp_days'], $after, $c['post_time']);
    dc_notify($b, "📅 Next topic from the queue started: **$next** (timeline #$nid, from $after). Still queued: " . count($queue) . '.');
}

/** Writes a post, draws its card, saves it as a draft for approval (Discord or website) or schedules it. Returns the post id. */
function studio_make_post(array $b, string $task, string $origin, int $when, ?string $link, ?string $thumb, ?int $campaignId = null, ?int $day = null): int {
    $w = studio_write($b, $task);
    $media = studio_card($w['card'], $b, $thumb);
    $variants = ['instagram' => $w['caption'], 'facebook' => $w['caption'], 'linkedin' => $w['linkedin'] ?: $w['caption'],
        'whatsapp' => $w['whatsapp'], '_task' => $task];
    $card = $w['card'] + ['thumb' => $thumb ? basename($thumb) : null];
    $dc = dc_conf($b);
    $status = ($dc || !$b['cp_auto']) ? 'draft' : 'scheduled';
    db()->prepare('INSERT INTO posts(brand_id,body,link,media,origin,scheduled_at,status,created_at,campaign_id,day,variants,card) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$b['id'], $w['caption'], $link, $media, $origin, $when, $status, time(), $campaignId, $day, json_encode($variants), json_encode($card)]);
    $pid = (int)db()->lastInsertId();
    attach_ready_accounts($pid, (int)$b['id'], (bool)$media);
    if ($dc) {
        try { dc_review(one('SELECT * FROM posts WHERE id=?', [$pid]), $b); }
        catch (Throwable $e) { db()->prepare('UPDATE brands SET ap_msg=? WHERE id=?')->execute(['Discord: ' . $e->getMessage(), $b['id']]); }
    }
    return $pid;
}

/** Approve a draft: keeps its planned time if that is still ahead, else posts now. Returns a human message. */
function studio_approve(array $p, array $b): string {
    $pdo = db();
    $cnt = fn() => (int)$pdo->query('SELECT COUNT(*) FROM deliveries WHERE post_id=' . (int)$p['id'])->fetchColumn();
    if (!$cnt()) attach_ready_accounts((int)$p['id'], (int)$p['brand_id'], (bool)$p['media']);
    if (!$cnt()) return '⚠️ Nothing is connected yet. Connect Instagram, Facebook or LinkedIn in Content Hub, then approve again.';
    $when = max((int)$p['scheduled_at'], time());
    $pdo->prepare("UPDATE posts SET status='scheduled', scheduled_at=? WHERE id=?")->execute([$when, $p['id']]);
    $to = $pdo->query('SELECT a.platform FROM deliveries d JOIN accounts a ON a.id=d.account_id WHERE d.post_id=' . (int)$p['id'])->fetchAll(PDO::FETCH_COLUMN);
    if ($when <= time() + 30) run_due((int)$p['id']);
    return '✅ Approved — ' . ($when <= time() + 30 ? 'posting now' : 'goes out ' . fmt_when($when, $b)) . ' to ' . implode(', ', array_unique($to)) . '.';
}

/** Rewrite a draft with the owner's instruction, redraw the card, and send it for review again. */
function studio_redo(array $p, array $b, string $fix): void {
    $v = json_decode((string)$p['variants'], true) ?: [];
    $card = json_decode((string)$p['card'], true) ?: [];
    $task = $v['_task'] ?? ('Rewrite this post: ' . $p['body']);
    $w = studio_write($b, $task, $fix, ['caption' => $p['body']]);
    $thumb = !empty($card['thumb']) && is_file(UPLOAD_DIR . '/' . $card['thumb']) ? UPLOAD_DIR . '/' . $card['thumb'] : null;
    $media = studio_card($w['card'], $b, $thumb);
    if ($media && $p['media'] && is_file(UPLOAD_DIR . '/' . $p['media'])) @unlink(UPLOAD_DIR . '/' . $p['media']);
    $nv = ['instagram' => $w['caption'], 'facebook' => $w['caption'], 'linkedin' => $w['linkedin'] ?: $w['caption'], 'whatsapp' => $w['whatsapp'], '_task' => $task];
    db()->prepare('UPDATE posts SET body=?, variants=?, card=?, media=COALESCE(?,media) WHERE id=?')
        ->execute([$w['caption'], json_encode($nv), json_encode($w['card'] + ['thumb' => $card['thumb'] ?? null]), $media, $p['id']]);
    if (dc_conf($b)) dc_review(one('SELECT * FROM posts WHERE id=?', [$p['id']]), $b, '🔄 Rewritten as you asked:');
}

function run_campaigns(): int {
    $made = 0;
    foreach (db()->query("SELECT * FROM campaigns WHERE status IN('planning','active') AND retry_at<=" . time() . ' ORDER BY id')->fetchAll() as $c) {
        try {
            if (campaign_tick($c)) $made++;
            db()->prepare('UPDATE campaigns SET retry_at=0 WHERE id=? AND retry_at>0')->execute([$c['id']]);
        } catch (Throwable $e) {
            db()->prepare('UPDATE campaigns SET retry_at=?, msg=? WHERE id=?')->execute([time() + 600, $e->getMessage(), $c['id']]);
        }
    }
    // "Post anyway if no reply": drafts whose planned time has passed
    $q = db()->query("SELECT p.* FROM posts p JOIN brands b ON b.id=p.brand_id WHERE b.cp_auto=1 AND p.status='draft'
        AND p.origin IN('campaign','youtube') AND p.scheduled_at<=" . time() . ' LIMIT 20');
    foreach ($q->fetchAll() as $p) {
        $b = brand_row((int)$p['brand_id']);
        $m = studio_approve($p, $b);
        if ($p['dc_msg']) dc_notify($b, "⏰ No reply by the planned time, so I posted it automatically.\n$m", $p['dc_msg']);
    }
    return $made;
}

// ---------- YouTube ----------
function youtube_channel_id(string $in): string {
    $in = trim($in);
    if (preg_match('/\b(UC[\w-]{22})\b/', $in, $m)) return $m[1];
    if (preg_match('#^@?([\w.\-]{3,})$#', $in, $m)) $url = 'https://www.youtube.com/@' . $m[1];
    elseif (preg_match('#(?:^|//)(?:www\.|m\.)?youtube\.com/#i', $in)) $url = preg_match('#^https?://#i', $in) ? $in : 'https://' . $in;
    else throw new HttpError('Paste a YouTube channel link, e.g. https://www.youtube.com/@YourChannel', 400);
    $html = safe_fetch($url);
    foreach (['#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[\w-]{22})"#', '#"externalId":"(UC[\w-]{22})"#',
                 '#<meta itemprop="(?:identifier|channelId)" content="(UC[\w-]{22})"#', '#"channelId":"(UC[\w-]{22})"#'] as $re)
        if (preg_match($re, $html, $m)) return $m[1];
    throw new HttpError('Could not find the channel ID on that page. Paste the link that looks like youtube.com/channel/UC…', 400);
}

function youtube_add(int $brandId, string $input): array {
    $cid = youtube_channel_id($input);
    $feed = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $cid;
    $ex = db()->prepare('SELECT id FROM sources WHERE brand_id=? AND url=?'); $ex->execute([$brandId, $feed]);
    if (!$ex->fetch()) db()->prepare("INSERT INTO sources(brand_id,url,mode,kind) VALUES(?,?,'draft','youtube')")->execute([$brandId, $feed]);
    $s = one('SELECT * FROM sources WHERE brand_id=? AND url=?', [$brandId, $feed]);
    $n = 0;
    try { youtube_sync($s); $n = (int)db()->query('SELECT COUNT(*) FROM videos WHERE brand_id=' . $brandId)->fetchColumn(); } catch (Throwable $e) {}
    return ['channel_id' => $cid, 'videos' => $n];
}

function youtube_is_short(string $vid): bool {
    try { return http('HEAD', "https://www.youtube.com/shorts/$vid", ['User-Agent: Mozilla/5.0'], null, false)['code'] === 200; }
    catch (Throwable $e) { return false; }
}

function youtube_thumb(string $vid): ?string {
    if (!preg_match('/^[\w-]{6,20}$/', $vid)) return null;
    foreach (['maxresdefault', 'sddefault', 'hqdefault'] as $q) {
        try {
            $r = http('GET', "https://i.ytimg.com/vi/$vid/$q.jpg");
            $i = $r['code'] === 200 ? @getimagesizefromstring($r['raw']) : false;
            if ($i && $i['mime'] === 'image/jpeg' && $i[0] >= 400) return UPLOAD_DIR . '/' . save_upload($r['raw']);
        } catch (Throwable $e) {}
    }
    return null;
}

/** Reads the channel feed, stores every video in the library, and turns new uploads into posts. Returns posts created. */
function youtube_sync(array $s): int {
    $pdo = db();
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string(safe_fetch($s['url']), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    if (!$xml) throw new RuntimeException('YouTube feed not readable');
    $items = [];
    foreach ($xml->entry as $e) {
        $vid = (string)$e->children('http://www.youtube.com/xml/schemas/2015')->videoId;
        if ($vid === '') continue;
        $link = ''; foreach ($e->link as $l) { $link = (string)$l['href']; break; }
        $g = $e->children('http://search.yahoo.com/mrss/')->group;
        $items[] = ['vid' => $vid, 'title' => (string)$e->title, 'desc' => $g ? (string)$g->description : '',
            'url' => $link ?: "https://www.youtube.com/watch?v=$vid", 'ts' => strtotime((string)$e->published) ?: time()];
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO videos(brand_id,vid,title,url,short,published) VALUES(?,?,?,?,?,?)');
    $created = 0;
    foreach (array_reverse($items) as $v) {
        $short = str_contains($v['url'], '/shorts/') ? 1 : 0;
        $ins->execute([$s['brand_id'], $v['vid'], $v['title'], $v['url'], $short, $v['ts']]);
        if (!$ins->rowCount()) continue;               // already known
        if (!$s['initialized'] || $s['mode'] === 'off' || $created >= 3 || $v['ts'] < time() - 7 * 86400) continue;
        if (!$short && youtube_is_short($v['vid'])) { $short = 1; $pdo->prepare('UPDATE videos SET short=1 WHERE brand_id=? AND vid=?')->execute([$s['brand_id'], $v['vid']]); }
        $b = brand_row((int)$s['brand_id']);
        if (!studio_quota_ok((int)$b['user_id'])) continue;
        $kind = $short ? 'Short' : 'lesson';
        $task = "The owner just uploaded a new YouTube $kind. Write posts that make students want to watch it.\n"
            . "Video title: {$v['title']}\nVideo URL: {$v['url']}\nVideo description: " . mb_substr(trim($v['desc']), 0, 900) . "\n"
            . 'Card: kicker "NEW ' . strtoupper($kind === 'Short' ? 'SHORT' : 'LESSON') . '", title = a clean version of the video title, '
            . '2-3 lines on what viewers will learn. Captions must say the video is on YouTube (Instagram: "link in bio"; LinkedIn and Facebook may include the URL).';
        $thumb = youtube_thumb($v['vid']);
        $pid = studio_make_post($b, $task, 'youtube', time(), $v['url'], $thumb);
        if ($s['mode'] === 'publish' && !dc_conf($b)) studio_approve(one('SELECT * FROM posts WHERE id=?', [$pid]), $b);
        $created++;
    }
    $pdo->prepare('UPDATE sources SET initialized=1, last_check=?, last_msg=? WHERE id=?')->execute([time(), 'OK ' . date('c') . ", $created new", $s['id']]);
    return $created;
}

// ---------- Discord approval bot ----------
function dc_conf(array $b): ?array {
    if (empty($b['dc_token']) || empty($b['dc_channel'])) return null;
    return ['token' => dec($b['dc_token']), 'channel' => $b['dc_channel'], 'brand' => (int)$b['id'], 'ident' => (int)$b['dc_ident']];
}
function dc_snowflake_now(): string { return (string)((((int)(microtime(true) * 1000)) - 1420070400000) << 22); }

function dc_call(array $d, string $method, string $path, $body = null): array {
    $h = ['Authorization: Bot ' . $d['token'], 'User-Agent: DiscordBot (https://discord.com, 1.0)'];
    if (is_array($body) && !isset($body['payload_json'])) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
    for ($i = 0; $i < 2; $i++) {
        $r = http($method, DC_API . $path, $h, $body);
        if ($r['code'] !== 429) break;
        if (!isset($GLOBALS['HTTP_MOCK'])) usleep((int)(min(5, (float)($r['json']['retry_after'] ?? 1)) * 1e6));
    }
    return must_ok($r, 'Discord');
}

/** Discord needs a bot to log in to its Gateway once before it may send messages. Minimal WebSocket handshake. */
function dc_identify(string $token): void {
    if (isset($GLOBALS['HTTP_MOCK'])) return;
    $s = @stream_socket_client('tls://gateway.discord.gg:443', $en, $es, 15);
    if (!$s) throw new RuntimeException("Discord gateway unreachable: $es");
    stream_set_timeout($s, 15);
    fwrite($s, "GET /?v=10&encoding=json HTTP/1.1\r\nHost: gateway.discord.gg\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $hdr = '';
    while (!str_contains($hdr, "\r\n\r\n")) $hdr .= ws_read($s, 1);
    if (!preg_match('#^HTTP/1\.1 101#', $hdr)) throw new RuntimeException('Discord gateway refused the connection');
    $send = function (string $t) use ($s) {
        $n = strlen($t); $mask = random_bytes(4);
        $h = chr(0x81) . ($n < 126 ? chr(0x80 | $n) : ($n < 65536 ? chr(0x80 | 126) . pack('n', $n) : chr(0x80 | 127) . pack('J', $n)));
        $m = ''; for ($i = 0; $i < $n; $i++) $m .= $t[$i] ^ $mask[$i % 4];
        fwrite($s, $h . $mask . $m);
    };
    $ok = false; $err = 'no READY from Discord'; $until = time() + 15;
    while (time() < $until) {
        $msg = ''; $op = null;
        do {
            $h = ws_read($s, 2); $fin = ord($h[0]) & 0x80; $o = ord($h[0]) & 0x0f; $len = ord($h[1]) & 0x7f;
            if ($len === 126) $len = unpack('n', ws_read($s, 2))[1]; elseif ($len === 127) $len = unpack('J', ws_read($s, 8))[1];
            $msg .= $len ? ws_read($s, $len) : '';
            $op ??= $o;
        } while (!$fin);
        if ($op === 8) { $code = strlen($msg) >= 2 ? unpack('n', substr($msg, 0, 2))[1] : 0; $err = $code === 4004 ? 'the bot token is wrong' : "gateway closed ($code)"; break; }
        if ($op !== 1) continue;
        $j = json_decode($msg, true);
        if (($j['op'] ?? null) === 10) $send(json_encode(['op' => 2, 'd' => ['token' => $token, 'intents' => 0,
            'properties' => ['os' => 'linux', 'browser' => 'contenthub', 'device' => 'contenthub']]]));
        elseif (($j['op'] ?? null) === 0 && ($j['t'] ?? '') === 'READY') { $ok = true; break; }
        elseif (($j['op'] ?? null) === 9) { $err = 'Discord rejected the session'; break; }
    }
    @fwrite($s, chr(0x88) . chr(0x80) . random_bytes(4));
    fclose($s);
    if (!$ok) throw new RuntimeException("Discord login failed: $err");
}
function ws_read($s, int $n): string {
    $b = '';
    while (strlen($b) < $n) {
        $c = fread($s, $n - strlen($b));
        if ($c === '' || $c === false) throw new RuntimeException('Discord gateway connection dropped' . (stream_get_meta_data($s)['timed_out'] ? ' (timeout)' : ''));
        $b .= $c;
    }
    return $b;
}
function dc_ready(array $d): void {
    if ($d['ident']) return;
    dc_identify($d['token']);
    db()->prepare('UPDATE brands SET dc_ident=1 WHERE id=?')->execute([$d['brand']]);
}

function dc_send(array $d, string $content, ?array $embed = null, ?string $file = null, ?string $replyTo = null): string {
    dc_ready($d);
    $p = ['content' => mb_substr($content, 0, 1990), 'allowed_mentions' => ['parse' => []]];
    if ($replyTo) $p['message_reference'] = ['message_id' => $replyTo, 'fail_if_not_exists' => false];
    if ($embed) { if ($file) $embed['image'] = ['url' => 'attachment://card.jpg']; $p['embeds'] = [$embed]; }
    $path = "/channels/{$d['channel']}/messages";
    $j = $file ? dc_call($d, 'POST', $path, ['payload_json' => json_encode($p), 'files[0]' => new CURLFile($file, 'image/jpeg', 'card.jpg')])
               : dc_call($d, 'POST', $path, $p);
    return (string)($j['id'] ?? '');
}
function dc_notify(array $b, string $text, ?string $replyTo = null): void {
    $d = dc_conf($b); if (!$d) return;
    try { dc_send($d, $text, null, null, $replyTo); } catch (Throwable $e) {}
}

function dc_review(array $p, array $b, string $note = ''): void {
    $d = dc_conf($b); if (!$d) return;
    $v = json_decode((string)$p['variants'], true) ?: [];
    if ($p['origin'] === 'campaign' && $p['campaign_id']) {
        $c = db()->query('SELECT topic, days FROM campaigns WHERE id=' . (int)$p['campaign_id'])->fetch() ?: ['topic' => '?', 'days' => '?'];
        $title = "Day {$p['day']}/{$c['days']} · {$c['topic']}";
    } elseif ($p['origin'] === 'youtube') $title = 'New YouTube video → social posts';
    else $title = 'Post for review';
    $when = (int)$p['scheduled_at'] > time() + 60 ? fmt_when((int)$p['scheduled_at'], $b) : 'as soon as you approve';
    [$r, $g, $bl] = hex_rgb($b['color'] ?? '');
    $embed = ['title' => mb_substr($title, 0, 250), 'description' => mb_substr($p['body'], 0, 4000), 'color' => ($r << 16) + ($g << 8) + $bl,
        'fields' => [], 'footer' => ['text' => "Post #{$p['id']} · goes out $when"]];
    if (!empty($v['linkedin']) && $v['linkedin'] !== $p['body']) $embed['fields'][] = ['name' => 'LinkedIn version', 'value' => mb_substr($v['linkedin'], 0, 1020)];
    if ($p['link']) $embed['fields'][] = ['name' => 'Link', 'value' => mb_substr($p['link'], 0, 1020)];
    $content = ($note ? "$note\n" : '') . '**Reply to this message:** `ok` = approve · `no` = skip · `edit: your caption` = use your text and approve · '
        . 'anything else = I rewrite it with your instruction. Or tap ✅ / ❌.';
    $file = $p['media'] && is_file(UPLOAD_DIR . '/' . $p['media']) ? UPLOAD_DIR . '/' . $p['media'] : null;
    $id = dc_send($d, $content, $embed, $file);
    foreach (['✅', '❌'] as $e) {
        try { dc_call($d, 'PUT', "/channels/{$d['channel']}/messages/$id/reactions/" . rawurlencode($e) . '/@me', ''); } catch (Throwable $x) {}
        if (!isset($GLOBALS['HTTP_MOCK'])) usleep(350000);
    }
    if ($p['dc_msg']) try { dc_send($d, '↪️ Replaced by the new version below.', null, null, $p['dc_msg']); } catch (Throwable $x) {}
    db()->prepare('UPDATE posts SET dc_msg=? WHERE id=?')->execute([$id, $p['id']]);
}

function dc_help(): string {
    return "**How to use me**\n"
        . "• Reply to a post: `ok` approve · `no` skip · `edit: new caption` · or write what to change (e.g. *make it shorter*, *the answer should be B*).\n"
        . "• Tap ✅ or ❌ under a post.\n"
        . "• `topic: Depreciation` — start a new daily timeline. Optional: `topic: Partnership accounts | 10 | A Level` (days, level).\n"
        . "• `queue: Bank reconciliation` — add a topic to run after the current one. Optional: `queue: Control accounts | 5 | AS Level`.\n"
        . "• `status` — what's running and waiting.";
}

/** A reply on a review message. */
function dc_command(array $b, array $d, array $p, string $t, string $msgId): void {
    $t = trim($t);
    if ($t === '') return;
    if ($p['status'] !== 'draft') { dc_send($d, "Post #{$p['id']} is already {$p['status']}.", null, null, $msgId); return; }
    if (preg_match('/^(ok|okay|k|yes|y|approve|approved|post|post it|go|go ahead|done|theek|theek hai|thik hai|haan|han|ji|jee|👍|✅)[\s.!]*$/iu', $t)) {
        $m = studio_approve($p, $b);
        dc_send($d, $m, null, null, $msgId);
        if (!str_starts_with($m, '⚠️')) dc_whatsapp($b, $d, $p);
    } elseif (preg_match('/^(no|n|skip|reject|cancel|nahi|nahin|na|❌|👎)[\s.!]*$/iu', $t)) {
        db()->prepare("UPDATE posts SET status='skipped' WHERE id=?")->execute([$p['id']]);
        dc_send($d, "⏭️ Skipped post #{$p['id']}.", null, null, $msgId);
    } elseif (preg_match('/^edit\s*[:\-]\s*(.+)$/isu', $t, $m)) {
        $v = json_decode((string)$p['variants'], true) ?: [];
        $nv = ['whatsapp' => $v['whatsapp'] ?? '', '_task' => $v['_task'] ?? ''];
        db()->prepare('UPDATE posts SET body=?, variants=? WHERE id=?')->execute([trim($m[1]), json_encode($nv), $p['id']]);
        $p = one('SELECT * FROM posts WHERE id=?', [$p['id']]);
        dc_send($d, '✏️ Caption replaced (all platforms). ' . studio_approve($p, $b), null, null, $msgId);
    } else {
        dc_send($d, '✍️ Rewriting post #' . $p['id'] . '…', null, null, $msgId);
        studio_redo($p, $b, mb_substr($t, 0, 600));
    }
}
function dc_whatsapp(array $b, array $d, array $p): void {
    $v = json_decode((string)$p['variants'], true) ?: [];
    if (empty($v['whatsapp'])) return;
    $text = $v['whatsapp'] . ($p['link'] && !str_contains($v['whatsapp'], $p['link']) ? "\n" . $p['link'] : '');
    try { dc_send($d, "📱 WhatsApp version (long-press → copy → forward to your Channel / Status):\n\n" . $text); } catch (Throwable $e) {}
}

function dc_status(array $b): string {
    $c = db()->prepare("SELECT * FROM campaigns WHERE brand_id=? AND status IN('planning','active') ORDER BY id"); $c->execute([$b['id']]);
    $lines = array_map(fn($x) => "• **{$x['topic']}** — {$x['made']}/{$x['days']} written ({$x['status']})", $c->fetchAll());
    $w = db()->prepare("SELECT COUNT(*) FROM posts WHERE brand_id=? AND status='draft' AND origin!='manual'"); $w->execute([$b['id']]);
    $s = db()->prepare("SELECT COUNT(*) FROM posts WHERE brand_id=? AND status='scheduled'"); $s->execute([$b['id']]);
    $q = studio_queue((string)$b['cp_queue']);
    return "**Timelines**\n" . ($lines ? implode("\n", $lines) : '• none running') . "\n**Waiting for your approval:** " . $w->fetchColumn()
        . "\n**Approved & scheduled:** " . $s->fetchColumn() . "\n**Queue (" . count($q) . "):** " . studio_queue_names($q);
}

/** Read new messages and reactions in the approval channel. */
function dc_poll(array $b): int {
    $d = dc_conf($b); if (!$d) return 0;
    $after = $b['dc_last'];
    if (!$after) {      // first run: start from now, never replay the channel's history
        $after = dc_snowflake_now();
        db()->prepare('UPDATE brands SET dc_last=? WHERE id=?')->execute([$after, $b['id']]);
    }
    $msgs = dc_call($d, 'GET', "/channels/{$d['channel']}/messages?limit=50&after=$after");
    usort($msgs, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
    $done = 0;
    $byMsg = db()->prepare('SELECT * FROM posts WHERE brand_id=? AND dc_msg=?');
    foreach ($msgs as $m) {
        db()->prepare('UPDATE brands SET dc_last=? WHERE id=?')->execute([$m['id'], $b['id']]);
        if (!empty($m['author']['bot'])) continue;
        $t = trim((string)($m['content'] ?? ''));
        $ref = $m['message_reference']['message_id'] ?? null;
        try {
            $p = null;
            if ($ref) { $byMsg->execute([$b['id'], $ref]); $p = $byMsg->fetch() ?: null; }
            if (!$p && preg_match('/^#(\d+)\s+(.+)$/su', $t, $x)) {
                $q = db()->prepare('SELECT * FROM posts WHERE brand_id=? AND id=?'); $q->execute([$b['id'], (int)$x[1]]);
                if ($p = $q->fetch() ?: null) $t = $x[2];
            }
            if ($p) { dc_command($b, $d, $p, $t, $m['id']); $done++; }
            elseif (preg_match('/^(?:topic|timeline)\s*[:\-]\s*(.+)$/isu', $t, $x)) {
                [$topic, $days, $level] = studio_topic_line($x[1]);
                dc_send($d, "🗓️ Planning **$topic**…", null, null, $m['id']);
                $cid = campaign_create(brand_row((int)$b['id']), $topic, $level, $days);
                $c = one('SELECT * FROM campaigns WHERE id=?', [$cid]);
                $plan = json_decode((string)$c['plan'], true) ?: [];
                $first = campaign_slot($c, 1, brand_tz($b));
                dc_send($d, $plan ? "**Timeline #$cid: {$c['topic']}** — {$c['days']} days from " . fmt_when($first, $b) . "\n"
                    . implode("\n", array_map(fn($i) => "{$i['day']}. {$i['title']} *({$i['type']})*", $plan))
                    . "\nEach post comes here for approval " . (int)$b['cp_lead'] . 'h before it goes out.'
                    : '⚠️ Could not plan it yet: ' . ($c['msg'] ?: 'unknown error') . ' — I will retry automatically.', null, null, $m['id']);
                $done++;
            } elseif (preg_match('/^queue\s*[:\-]\s*(.+)$/isu', $t, $x)) {
                $q = trim(((string)brand_row((int)$b['id'])['cp_queue']) . "\n" . trim($x[1]));
                db()->prepare('UPDATE brands SET cp_queue=? WHERE id=?')->execute([$q, $b['id']]);
                dc_send($d, '➕ Added to the queue. Up next: ' . studio_queue_names(studio_queue($q)), null, null, $m['id']);
            } elseif (preg_match('/^(help|commands|\?|hi|hello|salam|assalam.*)$/iu', $t)) dc_send($d, dc_help(), null, null, $m['id']);
            elseif (preg_match('/^status$/iu', $t)) dc_send($d, dc_status(brand_row((int)$b['id'])), null, null, $m['id']);
        } catch (Throwable $e) {
            try { dc_send($d, '⚠️ ' . $e->getMessage(), null, null, $m['id']); } catch (Throwable $x) {}
        }
    }
    // ✅ / ❌ reactions on drafts waiting for review
    $q = db()->prepare("SELECT * FROM posts WHERE brand_id=? AND status='draft' AND dc_msg IS NOT NULL AND created_at>? ORDER BY id DESC LIMIT 10");
    $q->execute([$b['id'], time() - 14 * 86400]);
    foreach ($q->fetchAll() as $p) {
        try { $m = dc_call($d, 'GET', "/channels/{$d['channel']}/messages/{$p['dc_msg']}"); }
        catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) db()->prepare('UPDATE posts SET dc_msg=NULL WHERE id=?')->execute([$p['id']]);
            continue;
        }
        foreach ($m['reactions'] ?? [] as $r) {
            if ((int)$r['count'] - (!empty($r['me']) ? 1 : 0) < 1) continue;
            $e = $r['emoji']['name'] ?? '';
            if ($e === '✅') { $msg = studio_approve($p, $b); dc_send($d, $msg, null, null, $p['dc_msg']); if (!str_starts_with($msg, '⚠️')) dc_whatsapp($b, $d, $p); $done++; break; }
            if ($e === '❌') { db()->prepare("UPDATE posts SET status='skipped' WHERE id=?")->execute([$p['id']]); dc_send($d, "⏭️ Skipped post #{$p['id']}.", null, null, $p['dc_msg']); $done++; break; }
        }
    }
    return $done;
}

function run_discord(): int {
    $n = 0;
    foreach (db()->query("SELECT * FROM brands WHERE dc_token IS NOT NULL AND dc_token!='' AND dc_channel IS NOT NULL AND dc_channel!=''")->fetchAll() as $b) {
        try { $n += dc_poll($b); }
        catch (Throwable $e) { db()->prepare('UPDATE brands SET ap_msg=? WHERE id=?')->execute(['Discord: ' . $e->getMessage(), $b['id']]); }
    }
    return $n;
}
