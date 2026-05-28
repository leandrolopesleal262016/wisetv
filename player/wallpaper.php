<?php

declare(strict_types=1);

function env_config_path_wallpaper($name, $defaultPath)
{
    $value = getenv($name);
    if (!is_string($value)) {
        return $defaultPath;
    }

    $value = trim($value);
    return $value !== '' ? $value : $defaultPath;
}

function sanitize_device_wallpaper($value)
{
    $device = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)$value));
    if ($device === null) {
        return '';
    }
    return $device;
}

function sanitize_upload_filename_wallpaper($filename)
{
    $filename = basename((string)$filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    if ($filename === null || $filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }
    return $filename;
}

function media_type_from_filename_wallpaper($filename)
{
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
        return 'image';
    }
    if (in_array($ext, array('mp4', 'webm', 'mov', 'm4v'), true)) {
        return 'video';
    }
    return null;
}

function sanitize_wallpaper_media_name_wallpaper($value)
{
    $name = sanitize_upload_filename_wallpaper($value);
    if ($name === '') {
        return '';
    }

    return media_type_from_filename_wallpaper($name) === 'image' ? $name : '';
}

$deviceRegistry = env_config_path_wallpaper('WISETV_PLAYER_DEVICE_REGISTRY_FILE', __DIR__ . '/devices.json');
$mediaDir = env_config_path_wallpaper('WISETV_MEDIA_DIR', dirname(__DIR__) . '/midias');
$device = sanitize_device_wallpaper(isset($_GET['device']) ? $_GET['device'] : '');

$wallpaperMedia = '';
if ($device !== '' && is_file($deviceRegistry)) {
    $decoded = json_decode((string)@file_get_contents($deviceRegistry), true);
    if (
        is_array($decoded)
        && isset($decoded['devices'])
        && is_array($decoded['devices'])
        && isset($decoded['devices'][$device])
        && is_array($decoded['devices'][$device])
    ) {
        $wallpaperMedia = sanitize_wallpaper_media_name_wallpaper(isset($decoded['devices'][$device]['fully_wallpaper_media']) ? $decoded['devices'][$device]['fully_wallpaper_media'] : '');
    }
}

$wallpaperUrl = '';
if ($wallpaperMedia !== '' && is_file($mediaDir . '/' . $wallpaperMedia)) {
    $wallpaperUrl = '../midias/' . rawurlencode($wallpaperMedia) . '?v=' . (int)@filemtime($mediaDir . '/' . $wallpaperMedia);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>WiseTV Wallpaper</title>
  <style>
    html, body {
      margin: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      background: #000;
    }

    body {
      background-color: #000;
      background-image: <?= $wallpaperUrl !== '' ? 'url(' . json_encode($wallpaperUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ')' : 'none' ?>;
      background-position: center center;
      background-repeat: no-repeat;
      background-size: cover;
    }
  </style>
</head>
<body></body>
</html>
