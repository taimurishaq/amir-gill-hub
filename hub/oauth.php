<?php
// OAuth entry + callback for Facebook/Instagram, LinkedIn and X. Register this exact URL as the redirect URI.
require __DIR__ . '/lib/core.php';
boot_session();

function back(string $k, string $v) { header('Location: ./?' . $k . '=' . urlencode($v)); exit; }

if (!uid()) back('oerr', 'Please sign in first.');
try {
    if (isset($_GET['start'])) {
        header('Location: ' . oauth_start((string)$_GET['start'], (int)($_GET['brand'] ?? 0)));
        exit;
    }
    if (isset($_GET['error'])) back('oerr', (string)($_GET['error_description'] ?? $_GET['error']));
    $o = $_SESSION['oauth'] ?? null;
    if (!$o || !hash_equals($o['state'], (string)($_GET['state'] ?? ''))) back('oerr', 'Invalid or expired state. Try again.');
    if (empty($_GET['code'])) back('oerr', 'No authorization code returned.');
    back('connected', oauth_finish((string)$_GET['code']));
} catch (Throwable $e) {
    back('oerr', $e->getMessage());
}
