<?php
if (!defined('WISETV_BUILD')) {
  define('WISETV_BUILD', '2026-03-25-hostinger-fix-1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-WiseTV-Build: ' . WISETV_BUILD);

function json_flags()
{
  $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
  if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
  }
  return $flags;
}

function json_encode_payload($payload)
{
  return json_encode($payload, json_flags());
}

function media_base_url()
{
  $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/player/playlist.php';
  $scriptDir = str_replace('\\', '/', dirname($scriptName));
  $parentDir = str_replace('\\', '/', dirname($scriptDir));

  if ($parentDir === '/' || $parentDir === '\\' || $parentDir === '.') {
    return '';
  }
  return rtrim($parentDir, '/');
}

function normalize_playlist_media_url($url)
{
  $value = trim((string)$url);
  if ($value === '') {
    return '';
  }
  if (preg_match('/^(https?:|data:|blob:)/i', $value)) {
    return $value;
  }

  $path = parse_url($value, PHP_URL_PATH);
  if (!is_string($path) || $path === '') {
    $path = $value;
  }
  $path = str_replace('\\', '/', $path);

  if (strpos($path, '/midias/') !== false || strpos($path, 'midias/') === 0) {
    $filename = basename($path);
    if ($filename !== '' && $filename !== '.' && $filename !== '..') {
      return media_base_url() . '/midias/' . rawurlencode(rawurldecode($filename));
    }
  }

  return $value;
}

function normalize_playlist_payload($payload)
{
  if (!is_array($payload) || !isset($payload['playlist']) || !is_array($payload['playlist'])) {
    return array('playlist' => array());
  }

  $normalized = array();
  foreach ($payload['playlist'] as $item) {
    if (!is_array($item)) {
      continue;
    }
    if (isset($item['url'])) {
      $item['url'] = normalize_playlist_media_url($item['url']);
    }
    $normalized[] = $item;
  }

  $payload['playlist'] = $normalized;
  return $payload;
}

$device = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['device'] ?? 'tv01');
$path = __DIR__ . "/playlists/{$device}.json";

if (!file_exists($path)) {
  $path = __DIR__ . "/playlists/tv01.json"; // fallback
}

// Fallback de presenca online: toda vez que a TV pedir playlist, atualiza last_seen.
$statusCandidates = array(
  __DIR__ . "/status",
  __DIR__ . "/playlists/status",
  __DIR__ . "/playlists"
);
$statusDir = null;
foreach ($statusCandidates as $dir) {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  if (is_dir($dir) && is_writable($dir)) {
    $statusDir = $dir;
    break;
  }
}

if ($statusDir !== null && $device !== '') {
  $now = time();
  $statusPayload = array(
    'device' => $device,
    'last_seen_unix' => $now,
    'last_seen_iso' => gmdate('c', $now),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'source' => 'playlist'
  );
  $statusJson = json_encode_payload($statusPayload);
  if ($statusJson !== false) {
    @file_put_contents($statusDir . "/{$device}.json", $statusJson . PHP_EOL);
  }
}

$rawPlaylist = file_get_contents($path);
if ($rawPlaylist === false) {
  echo '{"playlist":[]}';
  exit;
}

$decoded = json_decode($rawPlaylist, true);
$encodedPlaylist = json_encode_payload(normalize_playlist_payload($decoded));
echo $encodedPlaylist !== false ? $encodedPlaylist : '{"playlist":[]}';
