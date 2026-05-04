<?php
if (!defined('WISETV_BUILD')) {
    define('WISETV_BUILD', '2026-03-25-upload-fix-3');
}

ob_start();
@ini_set('max_execution_time', '0');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-WiseTV-Build: ' . WISETV_BUILD);

if (!defined('PLAYLISTS_DIR')) {
    define('PLAYLISTS_DIR', __DIR__ . '/playlists');
}
if (!defined('MIDIAS_DIR')) {
    define('MIDIAS_DIR', dirname(__DIR__) . '/midias');
}
if (!defined('STATUS_DIR')) {
    define('STATUS_DIR', __DIR__ . '/status');
}
if (!defined('DEVICE_REGISTRY_FILE')) {
    define('DEVICE_REGISTRY_FILE', __DIR__ . '/devices.json');
}
if (!defined('MAX_UPLOAD_BYTES')) {
    define('MAX_UPLOAD_BYTES', 200 * 1024 * 1024);
}
if (!defined('MAX_VIDEO_WIDTH')) {
    define('MAX_VIDEO_WIDTH', 1920);
}
if (!defined('MAX_VIDEO_HEIGHT')) {
    define('MAX_VIDEO_HEIGHT', 1080);
}

function ini_size_to_bytes($value)
{
    if (is_int($value) || is_float($value)) {
        return max(0, (int)$value);
    }

    $text = trim((string)$value);
    if ($text === '') {
        return 0;
    }

    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?)(b)?\s*$/i', $text, $parts)) {
        return max(0, (int)$text);
    }

    $number = (float)$parts[1];
    $unit = isset($parts[2]) ? strtolower($parts[2]) : '';
    $powers = array(
        '' => 0,
        'k' => 1,
        'm' => 2,
        'g' => 3,
        't' => 4,
        'p' => 5
    );

    $power = isset($powers[$unit]) ? $powers[$unit] : 0;
    return (int)round($number * pow(1024, $power));
}

function upload_error_message($errorCode)
{
    $phpUploadMax = ini_size_to_bytes(ini_get('upload_max_filesize'));
    $phpPostMax = ini_size_to_bytes(ini_get('post_max_size'));
    $limits = array_filter(array($phpUploadMax, $phpPostMax), function ($value) {
        return (int)$value > 0;
    });
    $limitBytes = !empty($limits) ? min($limits) : 0;
    $limitLabel = $limitBytes > 0 ? round($limitBytes / 1048576, 2) . ' MB' : 'o limite do servidor';

    switch ((int)$errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'O arquivo excede o limite de upload deste servidor (' . $limitLabel . ').';
        case UPLOAD_ERR_PARTIAL:
            return 'O upload foi interrompido antes de terminar.';
        case UPLOAD_ERR_NO_FILE:
            return 'Nenhum arquivo foi enviado.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'O servidor nao possui pasta temporaria para receber uploads.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'O servidor nao conseguiu gravar o arquivo enviado.';
        case UPLOAD_ERR_EXTENSION:
            return 'Uma extensao do PHP bloqueou este upload.';
        default:
            return 'Falha no upload (codigo ' . (int)$errorCode . ').';
    }
}

function json_flags($extraFlags = 0)
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (int)$extraFlags;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

function json_encode_payload($payload, $extraFlags = 0)
{
    return json_encode($payload, json_flags($extraFlags));
}

function flush_api_output()
{
    $levels = ob_get_level();
    if ($levels <= 0) {
        return '';
    }

    $buffer = '';
    while (ob_get_level() > 0) {
        $buffer = ob_get_clean() . $buffer;
    }
    return $buffer;
}

function handle_api_shutdown()
{
    $buffer = flush_api_output();
    $error = error_get_last();
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    $isFatal = is_array($error) && isset($error['type']) && in_array((int)$error['type'], $fatalTypes, true);

    if ($isFatal) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-WiseTV-Build: ' . WISETV_BUILD);
            http_response_code(500);
        }

        $message = 'Erro fatal no servidor durante a requisicao.';
        if (isset($error['message']) && is_string($error['message']) && trim($error['message']) !== '') {
            $message .= ' Detalhe: ' . trim($error['message']);
        }

        $json = json_encode_payload(array('ok' => false, 'error' => $message));
        echo $json !== false ? $json : '{"ok":false,"error":"Erro fatal no servidor durante a requisicao."}';
        return;
    }

    if (trim((string)$buffer) === '') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('X-WiseTV-Build: ' . WISETV_BUILD);
            http_response_code(500);
        }

        $json = json_encode_payload(array(
            'ok' => false,
            'error' => 'O servidor encerrou a requisicao sem devolver JSON. Isso normalmente indica timeout, limite da hospedagem ou falha interna no PHP.'
        ));
        echo $json !== false ? $json : '{"ok":false,"error":"O servidor encerrou a requisicao sem devolver JSON."}';
        return;
    }

    echo $buffer;
}

register_shutdown_function('handle_api_shutdown');

function json_response($payload, $statusCode)
{
    $json = json_encode_payload($payload);
    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"error":"Falha ao gerar JSON"}';
        exit;
    }

    http_response_code((int)$statusCode);
    echo $json;
    exit;
}

function json_ok($payload)
{
    json_response($payload, 200);
}

function json_error($message, $statusCode)
{
    json_response(array('ok' => false, 'error' => $message), $statusCode);
}

function read_json_body()
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        json_error('Body invalido', 400);
    }

    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        json_error('JSON invalido', 400);
    }

    return $body;
}

function sanitize_device($value)
{
    $device = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
    if ($device === null) {
        $device = '';
    }
    return $device;
}

function sanitize_label($value)
{
    $label = trim((string)$value);
    $label = preg_replace('/\s+/u', ' ', $label);
    if ($label === null) {
        $label = '';
    }
    return trim($label);
}

function sanitize_upload_filename($filename)
{
    $filename = basename((string)$filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    if ($filename === null || $filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }
    return $filename;
}

function is_supported_media($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowed = array('mp4', 'webm', 'mov', 'm4v', 'jpg', 'jpeg', 'png', 'gif', 'webp');
    return in_array($ext, $allowed, true);
}

function media_type_from_filename($filename)
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $imageExt = array('jpg', 'jpeg', 'png', 'gif', 'webp');
    $videoExt = array('mp4', 'webm', 'mov', 'm4v');

    if (in_array($ext, $imageExt, true)) {
        return 'image';
    }
    if (in_array($ext, $videoExt, true)) {
        return 'video';
    }

    return null;
}

function is_windows_host()
{
    return DIRECTORY_SEPARATOR === '\\';
}

function shell_exec_available()
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = ini_get('disable_functions');
    if (!is_string($disabled) || trim($disabled) === '') {
        return true;
    }

    $functions = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $functions, true);
}

function find_binary_path($binaryName)
{
    static $cache = array();
    $cacheKey = (string)$binaryName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $binaryName = trim((string)$binaryName);
    if ($binaryName === '') {
        $cache[$cacheKey] = null;
        return null;
    }

    if (!shell_exec_available()) {
        $cache[$cacheKey] = null;
        return null;
    }

    $command = is_windows_host()
        ? 'where ' . escapeshellcmd($binaryName) . ' 2>NUL'
        : 'command -v ' . escapeshellarg($binaryName) . ' 2>/dev/null';

    $output = @shell_exec($command);
    if (!is_string($output) || trim($output) === '') {
        $cache[$cacheKey] = null;
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($output));
    $path = isset($lines[0]) ? trim((string)$lines[0]) : '';
    $cache[$cacheKey] = $path !== '' ? $path : null;
    return $cache[$cacheKey];
}

function ffmpeg_binary_path()
{
    return find_binary_path(is_windows_host() ? 'ffmpeg.exe' : 'ffmpeg');
}

function ffprobe_binary_path()
{
    return find_binary_path(is_windows_host() ? 'ffprobe.exe' : 'ffprobe');
}

function upload_capabilities()
{
    static $capabilities = null;
    if ($capabilities !== null) {
        return $capabilities;
    }

    $ffmpegPath = ffmpeg_binary_path();
    $ffprobePath = ffprobe_binary_path();
    $capabilities = array(
        'ffmpeg_available' => is_string($ffmpegPath) && $ffmpegPath !== '',
        'ffprobe_available' => is_string($ffprobePath) && $ffprobePath !== '',
        'auto_convert_above_hd' => (is_string($ffmpegPath) && $ffmpegPath !== '') && (is_string($ffprobePath) && $ffprobePath !== ''),
        'video_max_width' => MAX_VIDEO_WIDTH,
        'video_max_height' => MAX_VIDEO_HEIGHT,
        'can_probe_video_metadata' => shell_exec_available() && is_string($ffprobePath) && $ffprobePath !== ''
    );

    return $capabilities;
}

function video_metadata_from_file($path)
{
    if (!shell_exec_available()) {
        return null;
    }

    $ffprobePath = ffprobe_binary_path();
    if (!is_string($ffprobePath) || $ffprobePath === '' || !file_exists($path)) {
        return null;
    }

    $command = escapeshellarg($ffprobePath)
        . ' -v error -select_streams v:0 -show_entries stream=width,height'
        . ' -of json ' . escapeshellarg($path);
    $output = @shell_exec($command);
    if (!is_string($output) || trim($output) === '') {
        return null;
    }

    $decoded = json_decode($output, true);
    if (
        !is_array($decoded) ||
        !isset($decoded['streams'][0]) ||
        !is_array($decoded['streams'][0])
    ) {
        return null;
    }

    $stream = $decoded['streams'][0];
    $width = isset($stream['width']) ? (int)$stream['width'] : 0;
    $height = isset($stream['height']) ? (int)$stream['height'] : 0;
    if ($width <= 0 || $height <= 0) {
        return null;
    }

    return array(
        'width' => $width,
        'height' => $height
    );
}

function video_exceeds_hd($metadata)
{
    return is_array($metadata)
        && isset($metadata['width'], $metadata['height'])
        && (((int)$metadata['width']) > MAX_VIDEO_WIDTH || ((int)$metadata['height']) > MAX_VIDEO_HEIGHT);
}

function unique_media_name($filename)
{
    $safeName = sanitize_upload_filename($filename);
    if ($safeName === '') {
        return '';
    }

    $target = MIDIAS_DIR . '/' . $safeName;
    if (!file_exists($target)) {
        return $safeName;
    }

    $nameOnly = pathinfo($safeName, PATHINFO_FILENAME);
    $extOnly = pathinfo($safeName, PATHINFO_EXTENSION);
    return $nameOnly . '_' . date('Ymd_His') . ($extOnly !== '' ? '.' . $extOnly : '');
}

function convert_video_to_hd($sourcePath, $targetPath)
{
    if (!shell_exec_available()) {
        return false;
    }

    $ffmpegPath = ffmpeg_binary_path();
    if (!is_string($ffmpegPath) || $ffmpegPath === '') {
        return false;
    }

    $command = escapeshellarg($ffmpegPath)
        . ' -y -i ' . escapeshellarg($sourcePath)
        . ' -vf "scale=w=' . MAX_VIDEO_WIDTH . ':h=' . MAX_VIDEO_HEIGHT . ':force_original_aspect_ratio=decrease:force_divisible_by=2"'
        . ' -c:v libx264 -preset fast -crf 23 -c:a aac -movflags +faststart '
        . escapeshellarg($targetPath)
        . (is_windows_host() ? ' 2>NUL' : ' 2>/dev/null');

    @shell_exec($command);
    return file_exists($targetPath) && filesize($targetPath) > 0;
}

function ensure_directories()
{
    if (!is_dir(PLAYLISTS_DIR)) {
        @mkdir(PLAYLISTS_DIR, 0775, true);
    }
    if (!is_dir(STATUS_DIR)) {
        @mkdir(STATUS_DIR, 0775, true);
    }
}

function ends_with($value, $suffix)
{
    $len = strlen($suffix);
    if ($len === 0) {
        return true;
    }
    return substr($value, -$len) === $suffix;
}

function media_base_url()
{
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/player/admin_api.php';
    $scriptDir = str_replace('\\', '/', dirname($scriptName));
    $parentDir = str_replace('\\', '/', dirname($scriptDir));

    if ($parentDir === '/' || $parentDir === '\\' || $parentDir === '.') {
        return '';
    }
    return rtrim($parentDir, '/');
}

function get_status_storage_dir()
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = array(
        STATUS_DIR,
        PLAYLISTS_DIR . '/status',
        PLAYLISTS_DIR
    );

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $resolved = $dir;
            return $resolved;
        }
    }

    $resolved = PLAYLISTS_DIR;
    return $resolved;
}

function write_device_status($device, $meta)
{
    $path = get_status_storage_dir() . '/' . $device . '.json';
    $json = json_encode_payload($meta);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . PHP_EOL) !== false;
}

function read_device_status($device)
{
    $path = get_status_storage_dir() . '/' . $device . '.json';
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function load_json_file($path)
{
    if (!file_exists($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function save_json_file($path, $payload)
{
    $json = json_encode_payload($payload, JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL) !== false;
}

function load_device_registry()
{
    $decoded = load_json_file(DEVICE_REGISTRY_FILE);
    if (!is_array($decoded) || !isset($decoded['devices']) || !is_array($decoded['devices'])) {
        return array('devices' => array());
    }
    return $decoded;
}

function save_device_registry($registry)
{
    if (!is_array($registry)) {
        $registry = array();
    }
    if (!isset($registry['devices']) || !is_array($registry['devices'])) {
        $registry['devices'] = array();
    }

    return save_json_file(DEVICE_REGISTRY_FILE, $registry);
}

function list_playlist_device_ids()
{
    $devices = array();
    $files = is_dir(PLAYLISTS_DIR) ? scandir(PLAYLISTS_DIR) : false;
    if ($files === false) {
        return $devices;
    }

    foreach ($files as $file) {
        if (!ends_with($file, '.json')) {
            continue;
        }
        $devices[] = pathinfo($file, PATHINFO_FILENAME);
    }

    return array_values(array_unique($devices));
}

function compare_device_ids($left, $right)
{
    $leftMatch = preg_match('/^tv(\d+)$/i', (string)$left, $leftParts);
    $rightMatch = preg_match('/^tv(\d+)$/i', (string)$right, $rightParts);

    if ($leftMatch && $rightMatch) {
        return ((int)$leftParts[1]) <=> ((int)$rightParts[1]);
    }
    if ($leftMatch) {
        return -1;
    }
    if ($rightMatch) {
        return 1;
    }

    return strnatcasecmp((string)$left, (string)$right);
}

function list_all_device_ids($registry)
{
    $ids = array_merge(
        list_playlist_device_ids(),
        array_keys(isset($registry['devices']) && is_array($registry['devices']) ? $registry['devices'] : array())
    );
    $ids = array_values(array_unique(array_filter($ids, function ($value) {
        return sanitize_device($value) !== '';
    })));

    usort($ids, 'compare_device_ids');
    return $ids;
}

function normalize_registry_entry($device, $registry)
{
    $entry = array();
    if (
        isset($registry['devices']) &&
        is_array($registry['devices']) &&
        isset($registry['devices'][$device]) &&
        is_array($registry['devices'][$device])
    ) {
        $entry = $registry['devices'][$device];
    }

    $friendlyName = sanitize_label(isset($entry['friendly_name']) ? $entry['friendly_name'] : '');
    if ($friendlyName === '') {
        $friendlyName = $device;
    }

    $createdAt = isset($entry['created_at']) && is_string($entry['created_at']) ? trim($entry['created_at']) : '';
    if ($createdAt === '') {
        $playlistPath = PLAYLISTS_DIR . '/' . $device . '.json';
        if (file_exists($playlistPath)) {
            $createdAt = gmdate('c', (int)filemtime($playlistPath));
        } else {
            $createdAt = gmdate('c');
        }
    }

    return array(
        'friendly_name' => $friendlyName,
        'created_at' => $createdAt
    );
}

function build_device_payload($device, $registry)
{
    $entry = normalize_registry_entry($device, $registry);

    return array(
        'id' => $device,
        'friendly_name' => $entry['friendly_name'],
        'created_at' => $entry['created_at']
    );
}

function next_device_id($ids)
{
    $used = array();
    foreach ($ids as $id) {
        if (preg_match('/^tv(\d+)$/i', (string)$id, $parts)) {
            $used[(int)$parts[1]] = true;
        }
    }

    $counter = 1;
    while (isset($used[$counter])) {
        $counter++;
    }

    return 'tv' . str_pad((string)$counter, 2, '0', STR_PAD_LEFT);
}

function ensure_playlist_file($device)
{
    $path = PLAYLISTS_DIR . '/' . $device . '.json';
    if (file_exists($path)) {
        return true;
    }

    return save_json_file($path, array('playlist' => array()));
}

function media_url_for_name($name)
{
    return media_base_url() . '/midias/' . rawurlencode($name);
}

function extract_media_filename($url)
{
    $value = trim((string)$url);
    if ($value === '') {
        return '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $value;
    }

    $path = str_replace('\\', '/', $path);
    if (strpos($path, '/midias/') === false && strpos($path, 'midias/') !== 0) {
        return '';
    }

    $filename = basename($path);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }

    return rawurldecode($filename);
}

function playlist_item_matches_media($item, $filename)
{
    if (!is_array($item)) {
        return false;
    }

    $type = isset($item['type']) ? (string)$item['type'] : '';
    if ($type !== 'image' && $type !== 'video') {
        return false;
    }

    $currentName = extract_media_filename(isset($item['url']) ? $item['url'] : '');
    if ($currentName === '') {
        return false;
    }

    return strcasecmp($currentName, $filename) === 0;
}

function update_playlists_for_media($oldFilename, $newFilename)
{
    $result = array(
        'files_updated' => 0,
        'items_updated' => 0
    );

    $files = is_dir(PLAYLISTS_DIR) ? scandir(PLAYLISTS_DIR) : false;
    if ($files === false) {
        return $result;
    }

    foreach ($files as $file) {
        if (!ends_with($file, '.json')) {
            continue;
        }

        $path = PLAYLISTS_DIR . '/' . $file;
        $payload = load_json_file($path);
        if (!is_array($payload) || !isset($payload['playlist']) || !is_array($payload['playlist'])) {
            continue;
        }

        $changed = false;
        $newPlaylist = array();

        foreach ($payload['playlist'] as $item) {
            if (playlist_item_matches_media($item, $oldFilename)) {
                $changed = true;
                $result['items_updated']++;

                if ($newFilename === null) {
                    continue;
                }

                $item['url'] = media_url_for_name($newFilename);
            }

            $newPlaylist[] = $item;
        }

        if (!$changed) {
            continue;
        }

        $payload['playlist'] = $newPlaylist;
        if (!save_json_file($path, $payload)) {
            json_error('Falha ao atualizar playlists apos alterar midia', 500);
        }

        $result['files_updated']++;
    }

    return $result;
}

ensure_directories();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method === 'GET' && $action === 'list_media') {
    $media = array();
    if (is_dir(MIDIAS_DIR)) {
        $files = scandir(MIDIAS_DIR);
        if ($files === false) {
            $files = array();
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = MIDIAS_DIR . '/' . $file;
            if (!is_file($fullPath) || !is_supported_media($file)) {
                continue;
            }

            $type = media_type_from_filename($file);
            if ($type === null) {
                continue;
            }

            $modifiedUnix = (int)@filemtime($fullPath);
            $media[] = array(
                'name' => $file,
                'url' => media_url_for_name($file),
                'type' => $type,
                'modified_unix' => $modifiedUnix
            );
        }
    }

    usort($media, function ($left, $right) {
        $timeCompare = ((int)$right['modified_unix']) <=> ((int)$left['modified_unix']);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return strnatcasecmp((string)$left['name'], (string)$right['name']);
    });

    json_ok(array(
        'ok' => true,
        'media' => $media,
        'capabilities' => upload_capabilities()
    ));
}

if ($method === 'GET' && $action === 'list_tvs') {
    $registry = load_device_registry();
    $devices = array_map(function ($device) use ($registry) {
        return build_device_payload($device, $registry);
    }, list_all_device_ids($registry));

    json_ok(array('ok' => true, 'devices' => $devices));
}

if (($method === 'GET' || $method === 'POST') && $action === 'heartbeat') {
    $device = sanitize_device(isset($_REQUEST['device']) ? $_REQUEST['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $now = time();
    $meta = array(
        'device' => $device,
        'last_seen_unix' => $now,
        'last_seen_iso' => gmdate('c', $now),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : ''
    );

    if (!write_device_status($device, $meta)) {
        json_error('Falha ao salvar status', 500);
    }

    json_ok(array('ok' => true, 'device' => $device, 'last_seen_unix' => $now));
}

if ($method === 'GET' && $action === 'list_device_status') {
    $onlineWindow = isset($_GET['online_window']) ? (int)$_GET['online_window'] : 180;
    if ($onlineWindow <= 0) {
        $onlineWindow = 180;
    }

    $registry = load_device_registry();
    $now = time();
    $rows = array();

    foreach (list_all_device_ids($registry) as $device) {
        $status = read_device_status($device);
        $lastSeen = is_array($status) && isset($status['last_seen_unix']) ? (int)$status['last_seen_unix'] : 0;
        $online = ($lastSeen > 0) && (($now - $lastSeen) <= $onlineWindow);
        $rows[] = array(
            'device' => $device,
            'friendly_name' => normalize_registry_entry($device, $registry)['friendly_name'],
            'online' => $online,
            'last_seen_unix' => $lastSeen,
            'last_seen_iso' => $lastSeen > 0 ? gmdate('c', $lastSeen) : null
        );
    }

    usort($rows, function ($left, $right) {
        if ((bool)$left['online'] !== (bool)$right['online']) {
            return $left['online'] ? -1 : 1;
        }
        return compare_device_ids($left['device'], $right['device']);
    });

    json_ok(array(
        'ok' => true,
        'online_window' => $onlineWindow,
        'server_time_unix' => $now,
        'devices' => $rows
    ));
}

if ($method === 'GET' && $action === 'get_playlist') {
    $device = sanitize_device(isset($_GET['device']) ? $_GET['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $path = PLAYLISTS_DIR . '/' . $device . '.json';
    if (!file_exists($path)) {
        json_ok(array('ok' => true, 'device' => $device, 'playlist' => array()));
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        json_error('Falha ao ler playlist', 500);
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['playlist']) || !is_array($decoded['playlist'])) {
        json_error('Playlist invalida no arquivo', 500);
    }

    json_ok(array('ok' => true, 'device' => $device, 'playlist' => $decoded['playlist']));
}

if ($method === 'POST' && $action === 'save_playlist') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    $playlist = isset($body['playlist']) ? $body['playlist'] : null;

    if ($device === '' || !is_array($playlist)) {
        json_error('Dados incompletos', 400);
    }

    $normalized = array();
    foreach ($playlist as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = isset($item['type']) ? $item['type'] : '';
        $url = trim((string)(isset($item['url']) ? $item['url'] : ''));
        $duration = (int)(isset($item['duration']) ? $item['duration'] : 0);

        if (($type !== 'image' && $type !== 'video' && $type !== 'embed' && $type !== 'youtube') || $url === '') {
            continue;
        }

        $entry = array('type' => $type, 'url' => $url);
        if ($type === 'image') {
            $entry['duration'] = $duration > 0 ? $duration : 10;
        } elseif ($type === 'embed') {
            $entry['duration'] = $duration >= 15 ? $duration : 60;
        } elseif ($duration > 0) {
            $entry['duration'] = $duration;
        }

        $normalized[] = $entry;
    }

    $path = PLAYLISTS_DIR . '/' . $device . '.json';
    if (!save_json_file($path, array('playlist' => $normalized))) {
        json_error('Nao foi possivel salvar arquivo', 500);
    }

    json_ok(array('ok' => true, 'device' => $device, 'count' => count($normalized)));
}

if ($method === 'POST' && $action === 'create_device') {
    $body = read_json_body();
    $friendlyName = sanitize_label(isset($body['friendly_name']) ? $body['friendly_name'] : '');
    $registry = load_device_registry();
    $device = next_device_id(list_all_device_ids($registry));

    if (!ensure_playlist_file($device)) {
        json_error('Nao foi possivel criar a playlist da nova TV', 500);
    }

    $registry['devices'][$device] = array(
        'friendly_name' => $friendlyName !== '' ? $friendlyName : $device,
        'created_at' => gmdate('c')
    );

    if (!save_device_registry($registry)) {
        json_error('Nao foi possivel salvar os dados da TV', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry)
    ));
}

if ($method === 'POST' && $action === 'update_device') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $registry = load_device_registry();
    $knownDevices = list_all_device_ids($registry);
    if (!in_array($device, $knownDevices, true)) {
        json_error('TV nao encontrada', 404);
    }

    $entry = normalize_registry_entry($device, $registry);
    $friendlyName = sanitize_label(isset($body['friendly_name']) ? $body['friendly_name'] : '');
    $registry['devices'][$device] = array(
        'friendly_name' => $friendlyName !== '' ? $friendlyName : $device,
        'created_at' => $entry['created_at']
    );

    if (!save_device_registry($registry)) {
        json_error('Nao foi possivel atualizar os dados da TV', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry)
    ));
}

if ($method === 'POST' && $action === 'delete_device') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $registry = load_device_registry();
    $playlistPath = PLAYLISTS_DIR . '/' . $device . '.json';
    $statusPath = get_status_storage_dir() . '/' . $device . '.json';
    $exists = file_exists($playlistPath) || file_exists($statusPath) || isset($registry['devices'][$device]);
    if (!$exists) {
        json_error('TV nao encontrada', 404);
    }

    if (file_exists($playlistPath) && !@unlink($playlistPath)) {
        json_error('Nao foi possivel remover a playlist da TV', 500);
    }

    if (file_exists($statusPath)) {
        @unlink($statusPath);
    }

    if (isset($registry['devices'][$device])) {
        unset($registry['devices'][$device]);
        if (!save_device_registry($registry)) {
            json_error('Nao foi possivel atualizar o cadastro das TVs', 500);
        }
    }

    json_ok(array('ok' => true, 'device' => $device));
}

if ($method === 'POST' && $action === 'rename_media') {
    $body = read_json_body();
    $oldName = sanitize_upload_filename(isset($body['old_name']) ? $body['old_name'] : '');
    $requestedName = sanitize_upload_filename(isset($body['new_name']) ? $body['new_name'] : '');

    if ($oldName === '' || $requestedName === '') {
        json_error('Informe a midia atual e o novo nome', 400);
    }

    $sourcePath = MIDIAS_DIR . '/' . $oldName;
    if (!file_exists($sourcePath) || !is_file($sourcePath)) {
        json_error('Midia nao encontrada', 404);
    }

    $oldExtOriginal = pathinfo($oldName, PATHINFO_EXTENSION);
    $oldExt = strtolower($oldExtOriginal);
    if ($oldExt === '' || !is_supported_media($oldName)) {
        json_error('Tipo de midia nao suportado', 400);
    }

    $requestedExt = strtolower(pathinfo($requestedName, PATHINFO_EXTENSION));
    if ($requestedExt === '') {
        $requestedBase = pathinfo($requestedName, PATHINFO_FILENAME);
        $requestedName = $requestedBase . '.' . $oldExtOriginal;
    } elseif ($requestedExt !== $oldExt) {
        json_error('A renomeacao deve manter a mesma extensao do arquivo', 400);
    }

    $newName = sanitize_upload_filename($requestedName);
    if ($newName === '' || !is_supported_media($newName)) {
        json_error('Novo nome de midia invalido', 400);
    }

    if (strcasecmp($oldName, $newName) === 0) {
        json_ok(array(
            'ok' => true,
            'name' => $oldName,
            'updated_playlists' => 0,
            'updated_items' => 0
        ));
    }

    $targetPath = MIDIAS_DIR . '/' . $newName;
    if (file_exists($targetPath)) {
        json_error('Ja existe uma midia com esse nome', 409);
    }

    if (!@rename($sourcePath, $targetPath)) {
        json_error('Nao foi possivel renomear a midia', 500);
    }

    $stats = update_playlists_for_media($oldName, $newName);

    json_ok(array(
        'ok' => true,
        'name' => $newName,
        'updated_playlists' => $stats['files_updated'],
        'updated_items' => $stats['items_updated']
    ));
}

if ($method === 'POST' && $action === 'delete_media') {
    $body = read_json_body();
    $name = sanitize_upload_filename(isset($body['name']) ? $body['name'] : '');
    if ($name === '') {
        json_error('Midia invalida', 400);
    }

    $path = MIDIAS_DIR . '/' . $name;
    if (!file_exists($path) || !is_file($path)) {
        json_error('Midia nao encontrada', 404);
    }

    if (!@unlink($path)) {
        json_error('Nao foi possivel excluir a midia', 500);
    }

    $stats = update_playlists_for_media($name, null);

    json_ok(array(
        'ok' => true,
        'name' => $name,
        'updated_playlists' => $stats['files_updated'],
        'updated_items' => $stats['items_updated']
    ));
}

if ($method === 'POST' && $action === 'upload_media') {
    if (!is_dir(MIDIAS_DIR)) {
        @mkdir(MIDIAS_DIR, 0775, true);
    }

    if (!is_dir(MIDIAS_DIR) || !is_writable(MIDIAS_DIR)) {
        json_error('A pasta /midias nao esta gravavel neste servidor.', 500);
    }

    if (!isset($_FILES['media_file'])) {
        json_error('Arquivo nao enviado', 400);
    }

    $file = $_FILES['media_file'];
    if (!is_array($file)) {
        json_error('Upload invalido', 400);
    }

    $errorCode = isset($file['error']) ? (int)$file['error'] : 0;
    if ($errorCode !== UPLOAD_ERR_OK) {
        json_error(upload_error_message($errorCode), 400);
    }

    $tmpName = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    $originalName = isset($file['name']) ? $file['name'] : '';
    $size = isset($file['size']) ? (int)$file['size'] : 0;

    if ($size <= 0) {
        json_error('Arquivo vazio ou invalido.', 400);
    }

    $safeName = sanitize_upload_filename($originalName);
    if ($safeName === '' || !is_supported_media($safeName)) {
        json_error('Extensao nao permitida', 400);
    }

    $type = media_type_from_filename($safeName);
    if ($type === null) {
        json_error('Tipo de arquivo nao suportado', 400);
    }

    $safeName = unique_media_name($safeName);
    $target = MIDIAS_DIR . '/' . $safeName;

    if (!@move_uploaded_file($tmpName, $target)) {
        json_error('Nao foi possivel salvar o upload', 500);
    }

    $capabilities = upload_capabilities();
    $videoMetadata = null;
    $convertedToHd = false;

    if ($type === 'video') {
        $videoMetadata = video_metadata_from_file($target);

        if (video_exceeds_hd($videoMetadata)) {
            if (!$capabilities['auto_convert_above_hd']) {
                @unlink($target);
                json_error('Este video passa de HD (1920x1080) e o servidor atual nao tem ffmpeg para converter automaticamente.', 400);
            }

            $convertedExt = 'mp4';
            $convertedBase = pathinfo($safeName, PATHINFO_FILENAME);
            if (strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) === 'mp4') {
                $convertedBase .= '__hd';
            }

            $convertedName = unique_media_name($convertedBase . '.' . $convertedExt);
            $convertedPath = MIDIAS_DIR . '/' . $convertedName;

            if (!convert_video_to_hd($target, $convertedPath)) {
                @unlink($target);
                json_error('Nao foi possivel converter o video para HD', 500);
            }

            @unlink($target);
            $safeName = $convertedName;
            $target = $convertedPath;
            $convertedToHd = true;
            $videoMetadata = video_metadata_from_file($target);
        }
    }

    json_ok(array(
        'ok' => true,
        'name' => $safeName,
        'type' => $type,
        'url' => media_url_for_name($safeName),
        'converted_to_hd' => $convertedToHd,
        'video_metadata' => $videoMetadata,
        'capabilities' => $capabilities
    ));
}

json_error('Acao nao suportada', 404);
