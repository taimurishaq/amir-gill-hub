<?php
// Run every minute. cPanel cron:   * * * * * /usr/local/bin/php /home/USER/public_html/hub/cron.php
// Or free web-cron (cron-job.org): https://yourdomain.com/hub/cron.php?key=YOUR_CRON_KEY
require __DIR__ . '/lib/core.php';

if (PHP_SAPI !== 'cli') {
    $k = (string)cfg('cron_key');
    if ($k === '' || !hash_equals($k, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('forbidden'); }
}
$lock = fopen(DATA_DIR . '/cron.lock', 'c');       // prevent overlapping runs
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("busy\n");

set_time_limit(300);
$d = run_discord();      // approvals first, so an "ok" in Discord posts within a minute
$c = run_campaigns();
$a = run_autopilot();
$s = run_sources();
$n = run_due();
echo date('c') . " discord $d, timeline $c, autopilot $a, feeds $s, delivered $n\n";
