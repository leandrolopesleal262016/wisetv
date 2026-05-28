<?php
if (!defined('WISETV_BUILD')) {
    define('WISETV_BUILD', '2026-03-25-hostinger-fix-1');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-WiseTV-Build: ' . WISETV_BUILD);

function env_config_path($name, $defaultPath)
{
    $value = getenv($name);
    if (!is_string($value)) {
        return $defaultPath;
    }

    $value = trim($value);
    return $value !== '' ? $value : $defaultPath;
}

if (!defined('PLAYLISTS_DIR')) {
    define('PLAYLISTS_DIR', env_config_path('WISETV_PLAYER_PLAYLISTS_DIR', __DIR__ . '/playlists'));
}
if (!defined('MIDIAS_DIR')) {
    define('MIDIAS_DIR', env_config_path('WISETV_MEDIA_DIR', dirname(__DIR__) . '/midias'));
}
if (!defined('STATUS_DIR')) {
    define('STATUS_DIR', env_config_path('WISETV_PLAYER_STATUS_DIR', __DIR__ . '/status'));
}
if (!defined('PLAYER_LOGS_DIR')) {
    define('PLAYER_LOGS_DIR', env_config_path('WISETV_PLAYER_LOGS_DIR', __DIR__ . '/logs'));
}
if (!defined('DEVICE_REGISTRY_FILE')) {
    define('DEVICE_REGISTRY_FILE', env_config_path('WISETV_PLAYER_DEVICE_REGISTRY_FILE', __DIR__ . '/devices.json'));
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
if (!defined('NORMALIZED_VIDEO_WIDTH')) {
    define('NORMALIZED_VIDEO_WIDTH', 1280);
}
if (!defined('NORMALIZED_VIDEO_HEIGHT')) {
    define('NORMALIZED_VIDEO_HEIGHT', 720);
}
if (!defined('NORMALIZED_VIDEO_FPS')) {
    define('NORMALIZED_VIDEO_FPS', 30);
}
if (!defined('NORMALIZED_VIDEO_CRF')) {
    define('NORMALIZED_VIDEO_CRF', 21);
}
if (!defined('NORMALIZED_VIDEO_MAXRATE')) {
    define('NORMALIZED_VIDEO_MAXRATE', '4M');
}
if (!defined('NORMALIZED_VIDEO_BUFSIZE')) {
    define('NORMALIZED_VIDEO_BUFSIZE', '8M');
}
if (!defined('NORMALIZED_AUDIO_BITRATE')) {
    define('NORMALIZED_AUDIO_BITRATE', '128k');
}
if (!defined('NORMALIZED_VIDEO_EXTENSION')) {
    define('NORMALIZED_VIDEO_EXTENSION', 'mp4');
}
if (!defined('WISETV_ADMIN_USERNAME')) {
    define('WISETV_ADMIN_USERNAME', 'piracast');
}
if (!defined('WISETV_ADMIN_PASSWORD_HASH')) {
    define('WISETV_ADMIN_PASSWORD_HASH', '$2y$12$UTQmjiEPBR6VyDwJIbc65OtCcq66TJNHWkS4KN8n9g8tz.ZnYzVkK');
}
if (!defined('WISETV_ADMIN_SESSION_NAME')) {
    define('WISETV_ADMIN_SESSION_NAME', 'WISETVADMIN');
}

function request_is_https()
{
    $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])) : '';
    if ($forwardedProto !== '') {
        $primaryProto = trim((string)explode(',', $forwardedProto)[0]);
        return $primaryProto === 'https';
    }

    if (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '') {
        return true;
    }

    return isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443';
}

function auth_public_actions()
{
    return array('heartbeat', 'player_event', 'export_fully_settings');
}

function auth_session_actions()
{
    return array('session_status', 'login', 'logout');
}

function admin_action_requires_auth($action)
{
    $normalizedAction = trim((string)$action);
    if ($normalizedAction === '') {
        return true;
    }

    return !in_array($normalizedAction, array_merge(auth_public_actions(), auth_session_actions()), true);
}

function admin_session_should_start($action)
{
    $normalizedAction = trim((string)$action);
    if ($normalizedAction === '') {
        return true;
    }

    return admin_action_requires_auth($normalizedAction)
        || in_array($normalizedAction, auth_session_actions(), true);
}

function start_admin_session()
{
    static $started = false;
    if ($started) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $started = true;
        return;
    }

    session_name(WISETV_ADMIN_SESSION_NAME);
    session_cache_limiter('');

    $secureCookie = request_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax'
        ));
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookie, true);
    }

    @session_start();
    $started = session_status() === PHP_SESSION_ACTIVE;
}

function admin_is_authenticated()
{
    return session_status() === PHP_SESSION_ACTIVE
        && !empty($_SESSION['wisetv_admin_authenticated'])
        && isset($_SESSION['wisetv_admin_username'])
        && (string)$_SESSION['wisetv_admin_username'] === WISETV_ADMIN_USERNAME;
}

function admin_session_username()
{
    if (!admin_is_authenticated()) {
        return '';
    }

    return WISETV_ADMIN_USERNAME;
}

function admin_session_payload()
{
    return array(
        'authenticated' => admin_is_authenticated(),
        'username' => admin_session_username()
    );
}

function admin_login_matches($username, $password)
{
    $normalizedUsername = trim((string)$username);
    if ($normalizedUsername !== WISETV_ADMIN_USERNAME) {
        return false;
    }

    return password_verify((string)$password, WISETV_ADMIN_PASSWORD_HASH);
}

function admin_log_in($username)
{
    start_admin_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        json_error('Nao foi possivel iniciar a sessao do painel.', 500);
    }

    @session_regenerate_id(true);
    $_SESSION['wisetv_admin_authenticated'] = true;
    $_SESSION['wisetv_admin_username'] = trim((string)$username);
    $_SESSION['wisetv_admin_logged_at'] = gmdate('c');
}

function admin_log_out()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = array();

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            isset($params['path']) ? $params['path'] : '/',
            isset($params['domain']) ? $params['domain'] : '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }

    @session_destroy();
}

function require_admin_authentication()
{
    if (admin_is_authenticated()) {
        return;
    }

    json_response(array(
        'ok' => false,
        'authenticated' => false,
        'error' => 'Sessao expirada ou acesso nao autorizado. Entre novamente no painel.'
    ), 401);
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

function sanitize_fully_device_id($value)
{
    $deviceId = trim((string)$value);
    $deviceId = preg_replace('/[^a-zA-Z0-9._:-]/', '', $deviceId);
    if ($deviceId === null) {
        $deviceId = '';
    }
    return trim($deviceId);
}

function sanitize_wallpaper_media_name($value)
{
    $name = sanitize_upload_filename($value);
    if ($name === '') {
        return '';
    }

    if (media_type_from_filename($name) !== 'image') {
        return '';
    }

    return $name;
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

function upload_can_normalize_videos()
{
    $ffmpegPath = ffmpeg_binary_path();
    return shell_exec_available() && is_string($ffmpegPath) && $ffmpegPath !== '';
}

function upload_can_probe_video_metadata()
{
    $ffprobePath = ffprobe_binary_path();
    return shell_exec_available() && is_string($ffprobePath) && $ffprobePath !== '';
}

function upload_normalization_profile()
{
    return array(
        'width' => NORMALIZED_VIDEO_WIDTH,
        'height' => NORMALIZED_VIDEO_HEIGHT,
        'fps' => NORMALIZED_VIDEO_FPS,
        'extension' => NORMALIZED_VIDEO_EXTENSION,
        'video_codec' => 'h264',
        'audio_codec' => 'aac'
    );
}

function upload_normalization_profile_label($removeAudio = false)
{
    return sprintf(
        'MP4/H.264 %dx%d %dfps%s',
        NORMALIZED_VIDEO_WIDTH,
        NORMALIZED_VIDEO_HEIGHT,
        NORMALIZED_VIDEO_FPS,
        $removeAudio ? ' sem audio' : ' com AAC'
    );
}

function upload_capabilities()
{
    static $capabilities = null;
    if ($capabilities !== null) {
        return $capabilities;
    }

    $ffmpegPath = ffmpeg_binary_path();
    $ffprobePath = ffprobe_binary_path();
    $canNormalizeVideos = upload_can_normalize_videos();
    $canProbeVideoMetadata = upload_can_probe_video_metadata();
    $capabilities = array(
        'ffmpeg_available' => is_string($ffmpegPath) && $ffmpegPath !== '',
        'ffprobe_available' => is_string($ffprobePath) && $ffprobePath !== '',
        'auto_convert_above_hd' => $canNormalizeVideos,
        'normalize_videos_on_upload' => $canNormalizeVideos,
        'can_strip_audio' => $canNormalizeVideos,
        'video_max_width' => MAX_VIDEO_WIDTH,
        'video_max_height' => MAX_VIDEO_HEIGHT,
        'can_probe_video_metadata' => $canProbeVideoMetadata,
        'normalized_video_width' => NORMALIZED_VIDEO_WIDTH,
        'normalized_video_height' => NORMALIZED_VIDEO_HEIGHT,
        'normalized_video_fps' => NORMALIZED_VIDEO_FPS,
        'normalized_video_extension' => NORMALIZED_VIDEO_EXTENSION,
        'normalization_profile_label' => upload_normalization_profile_label(false)
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

function unique_media_name_for_path($filename, $ignorePath = null)
{
    $safeName = sanitize_upload_filename($filename);
    if ($safeName === '') {
        return '';
    }

    $target = MIDIAS_DIR . '/' . $safeName;
    $normalizedTarget = str_replace('\\', '/', $target);
    $normalizedIgnore = is_string($ignorePath) ? str_replace('\\', '/', $ignorePath) : null;

    if (!file_exists($target) || ($normalizedIgnore !== null && $normalizedTarget === $normalizedIgnore)) {
        return $safeName;
    }

    $nameOnly = pathinfo($safeName, PATHINFO_FILENAME);
    $extOnly = pathinfo($safeName, PATHINFO_EXTENSION);
    return $nameOnly . '_' . date('Ymd_His') . ($extOnly !== '' ? '.' . $extOnly : '');
}

function request_flag_enabled($value)
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, array('1', 'true', 'on', 'yes', 'sim'), true);
}

function normalized_video_filename($currentName, $currentPath)
{
    $baseName = sanitize_upload_filename(pathinfo($currentName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'video';
    }

    $candidate = $baseName . '.' . NORMALIZED_VIDEO_EXTENSION;
    $ignorePath = strtolower(pathinfo($currentName, PATHINFO_EXTENSION)) === NORMALIZED_VIDEO_EXTENSION
        ? $currentPath
        : null;

    return unique_media_name_for_path($candidate, $ignorePath);
}

function temporary_normalized_video_path()
{
    return MIDIAS_DIR . '/.wisetv_' . uniqid('', true) . '.' . NORMALIZED_VIDEO_EXTENSION;
}

function normalize_video_for_signage($sourcePath, $targetPath, $removeAudio = false)
{
    if (!upload_can_normalize_videos()) {
        return false;
    }

    $ffmpegPath = ffmpeg_binary_path();
    if (!is_string($ffmpegPath) || $ffmpegPath === '') {
        return false;
    }

    $videoFilter = 'scale=w=' . NORMALIZED_VIDEO_WIDTH
        . ':h=' . NORMALIZED_VIDEO_HEIGHT
        . ':force_original_aspect_ratio=decrease:force_divisible_by=2,fps='
        . NORMALIZED_VIDEO_FPS;

    $command = escapeshellarg($ffmpegPath)
        . ' -y -i ' . escapeshellarg($sourcePath)
        . ' -map 0:v:0';

    if (!$removeAudio) {
        $command .= ' -map 0:a:0?';
    }

    $command .= ' -sn -dn'
        . ' -vf ' . escapeshellarg($videoFilter)
        . ' -c:v libx264'
        . ' -profile:v main'
        . ' -pix_fmt yuv420p'
        . ' -preset medium'
        . ' -crf ' . (int)NORMALIZED_VIDEO_CRF
        . ' -maxrate ' . escapeshellarg(NORMALIZED_VIDEO_MAXRATE)
        . ' -bufsize ' . escapeshellarg(NORMALIZED_VIDEO_BUFSIZE);

    if ($removeAudio) {
        $command .= ' -an';
    } else {
        $command .= ' -c:a aac -b:a ' . escapeshellarg(NORMALIZED_AUDIO_BITRATE) . ' -ac 2';
    }

    $command .= ' -movflags +faststart '
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
    if (!is_dir(PLAYER_LOGS_DIR)) {
        @mkdir(PLAYER_LOGS_DIR, 0775, true);
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

function public_base_url()
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $configured = getenv('WISETV_PUBLIC_BASE_URL');
    if (is_string($configured) && trim($configured) !== '') {
        $resolved = rtrim(trim($configured), '/');
        return $resolved;
    }

    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        $resolved = '';
        return $resolved;
    }

    $scheme = 'http';
    $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    if ($forwardedProto !== '') {
        $scheme = strtolower(trim(explode(',', $forwardedProto)[0]));
    } elseif (
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
    ) {
        $scheme = 'https';
    }

    $resolved = $scheme . '://' . $host;
    return $resolved;
}

function absolute_app_url($path)
{
    $normalizedPath = '/' . ltrim((string)$path, '/');
    $base = public_base_url();
    if ($base === '') {
        return $normalizedPath;
    }
    return $base . $normalizedPath;
}

function current_script_public_url($extraParams = array())
{
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '/player/admin_api.php';
    $url = absolute_app_url($scriptName);
    if (!is_array($extraParams) || empty($extraParams)) {
        return $url;
    }

    return $url . '?' . http_build_query($extraParams, '', '&', PHP_QUERY_RFC3986);
}

function player_public_url($path, $extraParams = array())
{
    $normalizedPath = '/player/' . ltrim((string)$path, '/');
    $url = absolute_app_url($normalizedPath);
    if (!is_array($extraParams) || empty($extraParams)) {
        return $url;
    }

    return $url . '?' . http_build_query($extraParams, '', '&', PHP_QUERY_RFC3986);
}

function absolute_media_or_page_url($url)
{
    $value = trim((string)$url);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^(https?:|data:|blob:)#i', $value)) {
        return $value;
    }

    if (strpos($value, '/midias/') !== false) {
        $parts = parse_url($value);
        $path = isset($parts['path']) ? (string)$parts['path'] : $value;
        $filename = basename($path);
        $filename = $filename !== '' ? rawurldecode($filename) : '';
        if ($filename !== '') {
            return absolute_app_url(media_url_for_name($filename));
        }
    }

    return absolute_app_url($value);
}

function fully_cloud_api_base()
{
    $configured = getenv('WISETV_FULLY_CLOUD_API_BASE');
    if (!is_string($configured) || trim($configured) === '') {
        return 'https://api.fully-kiosk.com';
    }

    return rtrim(trim($configured), '/');
}

function fully_cloud_api_email()
{
    $value = getenv('WISETV_FULLY_CLOUD_API_EMAIL');
    return is_string($value) ? trim($value) : '';
}

function fully_cloud_api_key()
{
    $value = getenv('WISETV_FULLY_CLOUD_API_KEY');
    return is_string($value) ? trim($value) : '';
}

function fully_export_secret()
{
    $configured = getenv('WISETV_EXPORT_SECRET');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    return fully_cloud_api_key();
}

function fully_export_token($action, $device)
{
    $secret = fully_export_secret();
    if ($secret === '') {
        return '';
    }

    return hash_hmac('sha256', sanitize_device($device) . '|' . trim((string)$action), $secret);
}

function validate_fully_export_token($action, $device, $token)
{
    $expected = fully_export_token($action, $device);
    $provided = trim((string)$token);
    if ($expected === '' || $provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

function fully_cloud_template_ready()
{
    return public_base_url() !== '' && fully_cloud_api_email() !== '' && fully_cloud_api_key() !== '';
}

function fully_cloud_status()
{
    $email = fully_cloud_api_email();
    $apiKey = fully_cloud_api_key();
    $publicBase = public_base_url();
    $templateReady = fully_cloud_template_ready();

    $missing = array();
    if ($email === '' || $apiKey === '') {
        $missing[] = 'api_credentials';
    }
    if ($publicBase === '') {
        $missing[] = 'public_base_url';
    }

    return array(
        'api_base' => fully_cloud_api_base(),
        'api_configured' => $email !== '' && $apiKey !== '',
        'public_base_url' => $publicBase,
        'public_base_configured' => $publicBase !== '',
        'manifest_ready' => $publicBase !== '',
        'settings_ready' => $templateReady,
        'sync_ready' => empty($missing),
        'missing_requirements' => $missing,
        'message' => $templateReady
            ? 'Pronto para sincronizar assim que a TV estiver vinculada ao Fully Cloud.'
            : 'Configure WISETV_PUBLIC_BASE_URL, WISETV_FULLY_CLOUD_API_EMAIL e WISETV_FULLY_CLOUD_API_KEY para habilitar a sincronizacao com Fully Cloud.'
    );
}

function fully_cloud_last_sync_from_entry($entry)
{
    $lastSyncAt = isset($entry['fully_last_sync_at']) && is_string($entry['fully_last_sync_at'])
        ? trim($entry['fully_last_sync_at'])
        : '';
    $lastSyncStatus = isset($entry['fully_last_sync_status']) && is_string($entry['fully_last_sync_status'])
        ? trim($entry['fully_last_sync_status'])
        : '';
    $lastSyncMessage = isset($entry['fully_last_sync_message']) && is_string($entry['fully_last_sync_message'])
        ? trim($entry['fully_last_sync_message'])
        : '';
    $lastSyncHash = isset($entry['fully_last_sync_hash']) && is_string($entry['fully_last_sync_hash'])
        ? trim($entry['fully_last_sync_hash'])
        : '';

    return array(
        'last_sync_at' => $lastSyncAt,
        'last_sync_status' => $lastSyncStatus,
        'last_sync_message' => $lastSyncMessage,
        'last_sync_hash' => $lastSyncHash
    );
}

function fully_settings_template_from_entry($entry)
{
    if (!is_array($entry) || !isset($entry['fully_settings_template']) || !is_array($entry['fully_settings_template'])) {
        return null;
    }

    return $entry['fully_settings_template'];
}

function fully_settings_template_ready_from_entry($entry)
{
    $template = fully_settings_template_from_entry($entry);
    return is_array($template) && !empty($template);
}

function get_status_storage_dir()
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = array(
        STATUS_DIR,
        PLAYLISTS_DIR . '/status'
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

    $resolved = STATUS_DIR;
    return $resolved;
}

function get_player_logs_storage_dir()
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = array(
        PLAYER_LOGS_DIR,
        STATUS_DIR . '/logs',
        PLAYLISTS_DIR . '/logs'
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

    $resolved = PLAYER_LOGS_DIR;
    return $resolved;
}

function player_log_device_dir($device)
{
    $device = sanitize_device($device);
    if ($device === '') {
        return null;
    }

    $baseDir = get_player_logs_storage_dir();
    $deviceDir = $baseDir . '/' . $device;
    if (!is_dir($deviceDir)) {
        @mkdir($deviceDir, 0775, true);
    }

    return is_dir($deviceDir) ? $deviceDir : null;
}

function truncate_log_text($value, $maxLength = 400)
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function sanitize_log_extra($value, $depth = 0)
{
    if ($depth >= 3) {
        return null;
    }

    if (is_array($value)) {
        $result = array();
        $count = 0;
        foreach ($value as $key => $entry) {
            if ($count >= 20) {
                break;
            }
            $normalizedKey = truncate_log_text(is_int($key) ? (string)$key : preg_replace('/[^a-zA-Z0-9_.:-]/', '_', (string)$key), 60);
            if ($normalizedKey === '') {
                $normalizedKey = 'item_' . $count;
            }

            $normalizedValue = sanitize_log_extra($entry, $depth + 1);
            if ($normalizedValue === null) {
                continue;
            }

            $result[$normalizedKey] = $normalizedValue;
            $count++;
        }
        return $result;
    }

    if (is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if ($value === null) {
        return null;
    }

    $text = truncate_log_text($value, 300);
    return $text === '' ? null : $text;
}

function sanitize_player_log_event($device, $event, $receivedAt)
{
    if (!is_array($event)) {
        return null;
    }

    $type = truncate_log_text(isset($event['type']) ? $event['type'] : '', 80);
    if ($type === '') {
        return null;
    }

    $severity = strtolower(trim((string)(isset($event['severity']) ? $event['severity'] : 'info')));
    if (!in_array($severity, array('info', 'warn', 'error'), true)) {
        $severity = 'info';
    }

    $timestampUnix = isset($event['timestamp_unix']) ? (int)$event['timestamp_unix'] : 0;
    if ($timestampUnix <= 0) {
        $timestampUnix = $receivedAt;
    }

    $payload = array(
        'device' => $device,
        'type' => $type,
        'severity' => $severity,
        'timestamp_unix' => $timestampUnix,
        'timestamp_iso' => gmdate('c', $timestampUnix),
        'received_unix' => $receivedAt,
        'received_iso' => gmdate('c', $receivedAt)
    );

    $stringFields = array(
        'message' => 300,
        'reason' => 120,
        'media_name' => 180,
        'media_type' => 40,
        'url' => 300,
        'current_src' => 300,
        'playlist_version' => 80,
        'watchdog_label' => 120,
        'build' => 80,
        'session_id' => 120,
        'item_key' => 180,
        'document_visibility' => 40,
        'error_message' => 220
    );

    foreach ($stringFields as $field => $maxLength) {
        if (!isset($event[$field])) {
            continue;
        }
        $value = truncate_log_text($event[$field], $maxLength);
        if ($value !== '') {
            $payload[$field] = $value;
        }
    }

    $numericFields = array(
        'item_index',
        'current_time',
        'duration',
        'ready_state',
        'network_state',
        'error_code',
        'play_attempt',
        'recovery_attempt'
    );

    foreach ($numericFields as $field) {
        if (!isset($event[$field]) || $event[$field] === '') {
            continue;
        }
        if (!is_numeric($event[$field])) {
            continue;
        }
        $numericValue = $event[$field] + 0;
        $payload[$field] = is_float($numericValue) ? (float)$numericValue : (int)$numericValue;
    }

    $boolFields = array(
        'paused',
        'ended',
        'muted',
        'online',
        'cache_reset',
        'autoplay_muted',
        'from_cache_snapshot'
    );

    foreach ($boolFields as $field) {
        if (isset($event[$field])) {
            $payload[$field] = (bool)$event[$field];
        }
    }

    if (isset($event['extra'])) {
        $extra = sanitize_log_extra($event['extra']);
        if (is_array($extra) && !empty($extra)) {
            $payload['extra'] = $extra;
        }
    }

    return $payload;
}

function write_player_log_events($device, $events)
{
    $deviceDir = player_log_device_dir($device);
    if ($deviceDir === null || !is_array($events) || empty($events)) {
        return 0;
    }

    $receivedAt = time();
    $buffers = array();
    $written = 0;

    foreach ($events as $event) {
        $payload = sanitize_player_log_event($device, $event, $receivedAt);
        if (!is_array($payload)) {
            continue;
        }
        if (!isset($payload['severity']) || strtolower((string)$payload['severity']) !== 'error') {
            continue;
        }

        $path = $deviceDir . '/' . gmdate('Y-m-d', (int)$payload['timestamp_unix']) . '.jsonl';
        $json = json_encode_payload($payload);
        if ($json === false) {
            continue;
        }

        if (!isset($buffers[$path])) {
            $buffers[$path] = '';
        }
        $buffers[$path] .= $json . PHP_EOL;
        $written++;
    }

    if ($written <= 0) {
        return 0;
    }

    foreach ($buffers as $path => $chunk) {
        if (@file_put_contents($path, $chunk, FILE_APPEND | LOCK_EX) === false) {
            return 0;
        }
    }

    return $written;
}

function list_player_log_files($device)
{
    $baseDir = get_player_logs_storage_dir();
    if (!is_dir($baseDir)) {
        return array();
    }

    $devices = array();
    $normalizedDevice = sanitize_device($device);
    if ($normalizedDevice !== '') {
        $devices[] = $normalizedDevice;
    } else {
        $entries = scandir($baseDir);
        if ($entries === false) {
            return array();
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $baseDir . '/' . $entry;
            if (is_dir($fullPath)) {
                $devices[] = sanitize_device($entry);
            }
        }
    }

    $files = array();
    foreach ($devices as $deviceId) {
        if ($deviceId === '') {
            continue;
        }

        $deviceDir = $baseDir . '/' . $deviceId;
        if (!is_dir($deviceDir)) {
            continue;
        }

        $entries = scandir($deviceDir);
        if ($entries === false) {
            continue;
        }

        foreach ($entries as $entry) {
            if (!ends_with($entry, '.jsonl')) {
                continue;
            }
            $fullPath = $deviceDir . '/' . $entry;
            if (!is_file($fullPath)) {
                continue;
            }
            $files[] = array(
                'device' => $deviceId,
                'path' => $fullPath,
                'name' => $entry,
                'modified_unix' => (int)@filemtime($fullPath)
            );
        }
    }

    usort($files, function ($left, $right) {
        $nameCompare = strcmp((string)$right['name'], (string)$left['name']);
        if ($nameCompare !== 0) {
            return $nameCompare;
        }
        return ((int)$right['modified_unix']) <=> ((int)$left['modified_unix']);
    });

    return $files;
}

function normalize_log_severities($value)
{
    $allowed = array('info', 'warn', 'error');
    $result = array();
    foreach (explode(',', (string)$value) as $entry) {
        $severity = strtolower(trim($entry));
        if (in_array($severity, $allowed, true)) {
            $result[] = $severity;
        }
    }
    return array_values(array_unique($result));
}

function read_recent_player_logs($device, $limit, $severities = array())
{
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $allowedSeverities = array_values(array_unique(array_filter($severities, function ($value) {
        return in_array($value, array('info', 'warn', 'error'), true);
    })));

    $logs = array();
    foreach (list_player_log_files($device) as $file) {
        $lines = @file($file['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            continue;
        }

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $decoded = json_decode($lines[$index], true);
            if (!is_array($decoded)) {
                continue;
            }

            $severity = isset($decoded['severity']) ? strtolower((string)$decoded['severity']) : 'info';
            if (!empty($allowedSeverities) && !in_array($severity, $allowedSeverities, true)) {
                continue;
            }

            if (!isset($decoded['device']) || trim((string)$decoded['device']) === '') {
                $decoded['device'] = $file['device'];
            }

            $logs[] = $decoded;
            if (count($logs) >= $limit) {
                break 2;
            }
        }
    }

    usort($logs, function ($left, $right) {
        $timeCompare = ((int)$right['timestamp_unix']) <=> ((int)$left['timestamp_unix']);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return ((int)$right['received_unix']) <=> ((int)$left['received_unix']);
    });

    return array_slice($logs, 0, $limit);
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

function generate_playlist_version()
{
    return str_replace('.', '', sprintf('%.6F', microtime(true)));
}

function extract_playlist_version($payload)
{
    if (
        is_array($payload) &&
        isset($payload['_meta']) &&
        is_array($payload['_meta']) &&
        isset($payload['_meta']['playlist_version'])
    ) {
        $version = trim((string)$payload['_meta']['playlist_version']);
        if ($version !== '') {
            return $version;
        }
    }

    return '';
}

function playlist_path_for_device($device)
{
    return PLAYLISTS_DIR . '/' . $device . '.json';
}

function playlist_version_for_device($device, $payload = null)
{
    $version = extract_playlist_version($payload);
    if ($version !== '') {
        return $version;
    }

    $path = playlist_path_for_device($device);
    if (!file_exists($path)) {
        return '';
    }

    if ($payload === null) {
        $contents = file_get_contents($path);
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            $version = extract_playlist_version($decoded);
            if ($version !== '') {
                return $version;
            }
        }
    }

    $modified = (int)@filemtime($path);
    return $modified > 0 ? (string)$modified : '';
}

function build_playlist_payload($playlistItems, $version = null)
{
    return array(
        'playlist' => array_values(is_array($playlistItems) ? $playlistItems : array()),
        '_meta' => array(
            'playlist_version' => $version !== null && $version !== '' ? (string)$version : generate_playlist_version(),
            'updated_at' => gmdate('c')
        )
    );
}

function normalize_playlist_item($item)
{
    if (!is_array($item)) {
        return null;
    }

    $type = isset($item['type']) ? trim((string)$item['type']) : '';
    $url = trim((string)(isset($item['url']) ? $item['url'] : ''));
    $duration = (int)(isset($item['duration']) ? $item['duration'] : 0);

    if (($type !== 'image' && $type !== 'video' && $type !== 'embed' && $type !== 'youtube') || $url === '') {
        return null;
    }

    $entry = array(
        'type' => $type,
        'url' => $url
    );

    if ($type === 'image') {
        $entry['duration'] = $duration > 0 ? $duration : 10;
    } elseif ($type === 'embed') {
        $entry['duration'] = $duration >= 15 ? $duration : 60;
    } elseif ($duration > 0) {
        $entry['duration'] = $duration;
    }

    return $entry;
}

function normalize_playlist_items($playlist)
{
    $normalized = array();
    if (!is_array($playlist)) {
        return $normalized;
    }

    foreach ($playlist as $item) {
        $entry = normalize_playlist_item($item);
        if ($entry !== null) {
            $normalized[] = $entry;
        }
    }

    return $normalized;
}

function load_playlist_file($device)
{
    $path = playlist_path_for_device($device);
    if (!file_exists($path)) {
        return null;
    }

    $payload = load_json_file($path);
    if (!is_array($payload) || !isset($payload['playlist']) || !is_array($payload['playlist'])) {
        return null;
    }

    return $payload;
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

function save_registry_device_entry($registry, $device, $payload)
{
    if (!is_array($registry)) {
        $registry = array();
    }
    if (!isset($registry['devices']) || !is_array($registry['devices'])) {
        $registry['devices'] = array();
    }

    $device = sanitize_device($device);
    if ($device === '') {
        return $registry;
    }

    $current = array();
    if (isset($registry['devices'][$device]) && is_array($registry['devices'][$device])) {
        $current = $registry['devices'][$device];
    }

    $registry['devices'][$device] = array_merge($current, is_array($payload) ? $payload : array());
    return $registry;
}

function save_device_fully_settings_template($registry, $device, $settingsTemplate)
{
    return save_registry_device_entry($registry, $device, array(
        'fully_settings_template' => is_array($settingsTemplate) ? $settingsTemplate : array(),
        'fully_settings_template_fetched_at' => gmdate('c')
    ));
}

function update_device_sync_state($registry, $device, $status, $message, $playlistHash = '')
{
    return save_registry_device_entry($registry, $device, array(
        'fully_last_sync_at' => gmdate('c'),
        'fully_last_sync_status' => trim((string)$status),
        'fully_last_sync_message' => trim((string)$message),
        'fully_last_sync_hash' => trim((string)$playlistHash)
    ));
}

function http_get_text($url, $timeoutSeconds = 30)
{
    $headers = array(
        'Accept: application/json',
        'User-Agent: WiseTV-FullyCloud/1.0'
    );

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => max(1, (int)$timeoutSeconds),
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n"
        )
    ));

    $body = @file_get_contents($url, false, $context);
    $responseHeaders = isset($http_response_header) && is_array($http_response_header)
        ? $http_response_header
        : array();

    $statusCode = 0;
    if (!empty($responseHeaders) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $parts)) {
        $statusCode = (int)$parts[1];
    }

    return array(
        'ok' => $body !== false,
        'status_code' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $body !== false ? $body : ''
    );
}

function fully_cloud_remote_request($deviceId, $commandParams, $persistent = true, $nowait = false)
{
    $deviceId = sanitize_fully_device_id($deviceId);
    if ($deviceId === '') {
        return array('ok' => false, 'error' => 'Device ID do Fully invalido');
    }

    $query = array(
        'apiemail' => fully_cloud_api_email(),
        'apikey' => fully_cloud_api_key(),
        'devid' => $deviceId,
        'persistent' => $persistent ? '1' : '0',
        'nowait' => $nowait ? '1' : '0'
    );

    foreach ((array)$commandParams as $key => $value) {
        $query[(string)$key] = (string)$value;
    }

    $url = fully_cloud_api_base() . '/remote/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $response = http_get_text($url, 40);
    if (!$response['ok']) {
        return array('ok' => false, 'error' => 'Falha ao comunicar com a API do Fully Cloud');
    }

    $lines = preg_split('/\r\n|\r|\n/', trim((string)$response['body']));
    $parsed = array();
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            if (isset($decoded['content']) && is_string($decoded['content'])) {
                $content = base64_decode($decoded['content'], true);
                if (is_string($content) && $content !== '') {
                    $decoded['content_decoded'] = $content;
                    $contentJson = json_decode($content, true);
                    if (is_array($contentJson)) {
                        $decoded['content_json'] = $contentJson;
                    }
                }
            }
            $parsed[] = $decoded;
            continue;
        }

        $parsed[] = array('raw' => $line);
    }

    return array(
        'ok' => true,
        'status_code' => $response['status_code'],
        'response' => $parsed,
        'raw_body' => (string)$response['body']
    );
}

function fully_cloud_response_line($response)
{
    return isset($response['response'][0]) && is_array($response['response'][0])
        ? $response['response'][0]
        : array();
}

function fully_cloud_response_status($response)
{
    $line = fully_cloud_response_line($response);
    return isset($line['status']) ? trim((string)$line['status']) : '';
}

function fully_cloud_response_text($response, $fallback = '')
{
    $line = fully_cloud_response_line($response);
    if (isset($line['statustext']) && trim((string)$line['statustext']) !== '') {
        return trim((string)$line['statustext']);
    }
    return trim((string)$fallback);
}

function fully_cloud_response_has_error($response)
{
    if (!is_array($response) || empty($response['ok'])) {
        return true;
    }

    return strcasecmp(fully_cloud_response_status($response), 'Error') === 0;
}

function fully_cloud_apply_audio_preference($fullyDeviceId, $audioEnabled)
{
    if ($audioEnabled) {
        return array(
            'ok' => true,
            'response' => array(array(
                'status' => 'Skipped',
                'statustext' => 'Volume mantido como esta para evitar ajuste automatico em 100%.'
            ))
        );
    }

    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'setAudioVolume',
        'stream' => '3',
        'level' => '0'
    ), true, false);
}

function fully_cloud_start_playlist($fullyDeviceId)
{
    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'playerStart'
    ), true, false);
}

function fully_cloud_reload_start_url($fullyDeviceId)
{
    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'loadStartUrl'
    ), true, false);
}

function fully_cloud_bring_to_foreground($fullyDeviceId, $persistent = true)
{
    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'toForeground'
    ), $persistent, false);
}

function fully_cloud_restart_app($fullyDeviceId, $persistent = false)
{
    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'restartApp'
    ), $persistent, false);
}

function fully_cloud_set_string_setting($fullyDeviceId, $key, $value)
{
    $settingKey = trim((string)$key);
    if ($settingKey === '') {
        return array('ok' => false, 'error' => 'Chave de setting do Fully invalida');
    }

    return fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'setStringSetting',
        'key' => $settingKey,
        'value' => (string)$value
    ), true, false);
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
        'created_at' => $createdAt,
        'fully_enabled' => request_flag_enabled(isset($entry['fully_enabled']) ? $entry['fully_enabled'] : false),
        'fully_audio_enabled' => !isset($entry['fully_audio_enabled']) || request_flag_enabled($entry['fully_audio_enabled']),
        'fully_wallpaper_media' => sanitize_wallpaper_media_name(isset($entry['fully_wallpaper_media']) ? $entry['fully_wallpaper_media'] : ''),
        'fully_device_id' => sanitize_fully_device_id(isset($entry['fully_device_id']) ? $entry['fully_device_id'] : ''),
        'fully_last_sync' => fully_cloud_last_sync_from_entry($entry),
        'fully_settings_template_ready' => fully_settings_template_ready_from_entry($entry),
        'fully_settings_template_fetched_at' => isset($entry['fully_settings_template_fetched_at']) && is_string($entry['fully_settings_template_fetched_at'])
            ? trim($entry['fully_settings_template_fetched_at'])
            : ''
    );
}

function fully_manifest_filename($device)
{
    return sanitize_upload_filename('fully-manifest-' . sanitize_device($device) . '.json');
}

function fully_settings_filename($device)
{
    return sanitize_upload_filename('fully-video-settings-' . sanitize_device($device) . '.json');
}

function build_fully_manifest_payload($device, $registry)
{
    $entry = normalize_registry_entry($device, $registry);
    $payload = load_playlist_file($device);
    $playlistItems = is_array($payload) && isset($payload['playlist']) && is_array($payload['playlist'])
        ? normalize_playlist_items($payload['playlist'])
        : array();

    $manifestItems = array();
    foreach ($playlistItems as $index => $item) {
        $type = isset($item['type']) ? trim((string)$item['type']) : '';
        $rawUrl = isset($item['url']) ? (string)$item['url'] : '';
        $url = absolute_media_or_page_url($rawUrl);
        if ($url === '') {
            continue;
        }

        $row = array(
            'index' => (int)$index,
            'type' => $type,
            'url' => $url
        );

        if (isset($item['duration'])) {
            $row['duration'] = (int)$item['duration'];
        }

        $manifestItems[] = $row;
    }

    $playlistVersion = is_array($payload) ? playlist_version_for_device($device, $payload) : '';
    $playlistHashSource = json_encode_payload($manifestItems);
    $playlistHash = is_string($playlistHashSource) ? sha1($playlistHashSource) : '';

    return array(
        'format' => 'wisetv-fully-manifest-v1',
        'generated_at' => gmdate('c'),
        'device' => array(
            'id' => $device,
            'friendly_name' => $entry['friendly_name'],
            'fully_device_id' => $entry['fully_device_id']
        ),
        'playlist_version' => $playlistVersion,
        'playlist_hash' => $playlistHash,
        'items' => $manifestItems,
        'urls' => array_values(array_map(function ($item) {
            return $item['url'];
        }, $manifestItems))
    );
}

function build_fully_wallpaper_page_url($device)
{
    return player_public_url('wallpaper.php', array(
        'device' => sanitize_device($device)
    ));
}

function build_fully_wallpaper_media_url($wallpaperMedia)
{
    $name = sanitize_wallpaper_media_name($wallpaperMedia);
    if ($name === '') {
        return '';
    }

    if (!is_file(MIDIAS_DIR . '/' . $name)) {
        return '';
    }

    return absolute_app_url(media_url_for_name($name));
}

function build_fully_wallpaper_url($device, $registry = null)
{
    $wallpaperMedia = '';
    if (is_array($registry)) {
        $entry = normalize_registry_entry($device, $registry);
        $wallpaperMedia = isset($entry['fully_wallpaper_media']) ? $entry['fully_wallpaper_media'] : '';
    }

    $mediaUrl = build_fully_wallpaper_media_url($wallpaperMedia);
    if ($mediaUrl !== '') {
        return $mediaUrl;
    }

    return build_fully_wallpaper_page_url($device);
}

function build_fully_settings_export_url($device)
{
    $params = array(
        'action' => 'export_fully_settings',
        'device' => sanitize_device($device)
    );
    $token = fully_export_token('export_fully_settings', $device);
    if ($token !== '') {
        $params['token'] = $token;
    }

    return current_script_public_url($params);
}

function build_fully_manifest_export_url($device)
{
    return current_script_public_url(array(
        'action' => 'export_fully_manifest',
        'device' => sanitize_device($device)
    ));
}

function fully_cloud_extract_settings_from_response($response)
{
    if (!is_array($response) || !isset($response['response']) || !is_array($response['response'])) {
        return null;
    }

    foreach ($response['response'] as $line) {
        if (!is_array($line)) {
            continue;
        }

        if (isset($line['startURL']) || isset($line['mainPlaylist']) || isset($line['deviceID'])) {
            return $line;
        }

        if (isset($line['content_json']) && is_array($line['content_json'])) {
            $contentJson = $line['content_json'];
            if (isset($contentJson['startURL']) || isset($contentJson['mainPlaylist']) || isset($contentJson['deviceID'])) {
                return $contentJson;
            }
        }
    }

    return null;
}

function fetch_fully_device_settings_template($fullyDeviceId)
{
    $response = fully_cloud_remote_request($fullyDeviceId, array(
        'cmd' => 'listSettings',
        'type' => 'json'
    ), false, false);

    if (!$response['ok']) {
        return $response;
    }

    $settings = fully_cloud_extract_settings_from_response($response);
    if (!is_array($settings) || empty($settings)) {
        return array(
            'ok' => false,
            'error' => 'A API do Fully Cloud respondeu, mas nao retornou um payload de settings utilizavel.',
            'response' => isset($response['response']) ? $response['response'] : array()
        );
    }

    return array(
        'ok' => true,
        'settings' => $settings,
        'response' => isset($response['response']) ? $response['response'] : array()
    );
}

function fully_playlist_item_defaults($baseItem = array())
{
    $defaults = array(
        'type' => -1,
        'url' => '',
        'loopItem' => false,
        'loopFile' => false,
        'fileOrder' => 0,
        'nextItemOnTouch' => true,
        'nextFileOnTouch' => false,
        'nextItemTimer' => 0,
        'nextImageFileTimer' => 0,
        'nextVideoFileTimer' => 0
    );

    return array_merge($defaults, is_array($baseItem) ? $baseItem : array());
}

function fully_playlist_base_item_from_template($settingsTemplate)
{
    if (!is_array($settingsTemplate)) {
        return fully_playlist_item_defaults();
    }

    $rawPlaylist = isset($settingsTemplate['mainPlaylist']) ? $settingsTemplate['mainPlaylist'] : null;
    $playlist = null;

    if (is_string($rawPlaylist) && trim($rawPlaylist) !== '') {
        $playlist = json_decode($rawPlaylist, true);
    } elseif (is_array($rawPlaylist)) {
        $playlist = $rawPlaylist;
    }

    if (is_array($playlist) && isset($playlist[0]) && is_array($playlist[0])) {
        return fully_playlist_item_defaults($playlist[0]);
    }

    return fully_playlist_item_defaults();
}

function build_fully_playlist_entries($manifestItems, $settingsTemplate = null)
{
    $entries = array();
    $baseItem = fully_playlist_base_item_from_template($settingsTemplate);

    foreach ((array)$manifestItems as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = isset($item['type']) ? trim((string)$item['type']) : '';
        if ($type !== 'video' && $type !== 'image') {
            return array(
                'ok' => false,
                'error' => 'A sincronizacao com Fully Cloud suporta apenas itens de playlist do tipo video e imagem no momento.'
            );
        }

        $entry = fully_playlist_item_defaults($baseItem);
        $entry['type'] = $type === 'video' ? 1 : 2;
        $entry['url'] = isset($item['url']) ? (string)$item['url'] : '';
        $entry['loopItem'] = false;
        $entry['loopFile'] = false;
        $entry['fileOrder'] = (int)$index;
        $entry['nextItemOnTouch'] = false;
        $entry['nextFileOnTouch'] = false;
        $entry['nextItemTimer'] = 0;
        $entry['nextImageFileTimer'] = 0;
        $entry['nextVideoFileTimer'] = 0;

        if ($type === 'image') {
            $duration = isset($item['duration']) ? (int)$item['duration'] : 10;
            if ($duration <= 0) {
                $duration = 10;
            }
            $entry['nextItemTimer'] = $duration;
            $entry['nextImageFileTimer'] = $duration;
        }

        $entries[] = $entry;
    }

    return array(
        'ok' => true,
        'entries' => $entries
    );
}

function build_fully_settings_payload($device, $registry, $settingsTemplate = null)
{
    $entry = normalize_registry_entry($device, $registry);
    $audioEnabled = $entry['fully_audio_enabled'];

    if ($settingsTemplate === null) {
        $entryTemplate = array();
        if (
            isset($registry['devices']) &&
            is_array($registry['devices']) &&
            isset($registry['devices'][$device]) &&
            is_array($registry['devices'][$device])
        ) {
            $entryTemplate = $registry['devices'][$device];
        }

        $settingsTemplate = fully_settings_template_from_entry($entryTemplate);
    }

    if (!is_array($settingsTemplate) || empty($settingsTemplate)) {
        return array(
            'ok' => false,
            'error' => 'Ainda nao existe um template de settings do Fully Video Kiosk salvo para esta TV.'
        );
    }

    $manifest = build_fully_manifest_payload($device, $registry);
    $playlistBuild = build_fully_playlist_entries(isset($manifest['items']) ? $manifest['items'] : array(), $settingsTemplate);
    if (!$playlistBuild['ok']) {
        return $playlistBuild;
    }

    $settingsPayload = $settingsTemplate;
    $settingsPayload['mainPlaylist'] = json_encode_payload($playlistBuild['entries'], JSON_PRETTY_PRINT);
    if ($settingsPayload['mainPlaylist'] === false) {
        return array(
            'ok' => false,
            'error' => 'Nao foi possivel gerar o JSON da playlist do Fully.'
        );
    }

    // Force Fully Video Kiosk to use the native media player for direct MP4/image URLs.
    $settingsPayload['playMedia'] = true;
    $settingsPayload['loopPlaylist'] = true;
    $settingsPayload['autoImportSettings'] = true;
    $settingsPayload['autoplayVideos'] = true;
    $settingsPayload['autoplayAudio'] = $audioEnabled;
    $settingsPayload['resumeVideoAudio'] = $audioEnabled;
    $settingsPayload['enableFullscreenVideos'] = true;
    $settingsPayload['startURL'] = build_fully_wallpaper_page_url($device);
    $settingsPayload['wallpaperURL'] = build_fully_wallpaper_url($device, $registry);
    $settingsPayload['showThrobberForMedia'] = false;
    $settingsPayload['showPlayControlsForVideo'] = false;
    $settingsPayload['showNameForMedia'] = false;

    return array(
        'ok' => true,
        'settings' => $settingsPayload,
        'manifest' => $manifest
    );
}

function build_device_payload($device, $registry, $fullyCloud = null)
{
    $entry = normalize_registry_entry($device, $registry);
    $fullyCloud = is_array($fullyCloud) ? $fullyCloud : fully_cloud_status();
    $manifestPayload = build_fully_manifest_payload($device, $registry);
    $lastSync = $entry['fully_last_sync'];

    return array(
        'id' => $device,
        'friendly_name' => $entry['friendly_name'],
        'created_at' => $entry['created_at'],
        'fully' => array(
            'enabled' => $entry['fully_enabled'],
            'audio_enabled' => $entry['fully_audio_enabled'],
            'wallpaper_media' => $entry['fully_wallpaper_media'],
            'wallpaper_url' => build_fully_wallpaper_url($device, $registry),
            'device_id' => $entry['fully_device_id'],
            'manifest_url' => build_fully_manifest_export_url($device),
            'settings_url' => build_fully_settings_export_url($device),
            'playlist_hash' => $manifestPayload['playlist_hash'],
            'playlist_version' => $manifestPayload['playlist_version'],
            'sync_ready' => $fullyCloud['sync_ready'] && $entry['fully_enabled'] && $entry['fully_device_id'] !== '',
            'settings_template_ready' => $entry['fully_settings_template_ready'],
            'settings_template_fetched_at' => $entry['fully_settings_template_fetched_at'],
            'last_sync_at' => $lastSync['last_sync_at'],
            'last_sync_status' => $lastSync['last_sync_status'],
            'last_sync_message' => $lastSync['last_sync_message'],
            'last_sync_hash' => $lastSync['last_sync_hash']
        )
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
    $path = playlist_path_for_device($device);
    if (file_exists($path)) {
        return true;
    }

    return save_json_file($path, build_playlist_payload(array()));
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

        $payload = build_playlist_payload($newPlaylist);
        if (!save_json_file($path, $payload)) {
            json_error('Falha ao atualizar playlists apos alterar midia', 500);
        }

        $result['files_updated']++;
    }

    return $result;
}

function update_device_wallpaper_references($oldFilename, $newFilename)
{
    $registry = load_device_registry();
    if (!isset($registry['devices']) || !is_array($registry['devices'])) {
        return array('devices_updated' => 0);
    }

    $updated = 0;
    foreach ($registry['devices'] as $device => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $current = sanitize_wallpaper_media_name(isset($entry['fully_wallpaper_media']) ? $entry['fully_wallpaper_media'] : '');
        if ($current === '' || strcasecmp($current, $oldFilename) !== 0) {
            continue;
        }

        $registry['devices'][$device]['fully_wallpaper_media'] = $newFilename === null
            ? ''
            : sanitize_wallpaper_media_name($newFilename);
        $updated++;
    }

    if ($updated > 0 && !save_device_registry($registry)) {
        json_error('Falha ao atualizar wallpapers das TVs apos alterar midia', 500);
    }

    return array('devices_updated' => $updated);
}

ensure_directories();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if (admin_session_should_start($action)) {
    start_admin_session();
}

if ($method === 'GET' && $action === 'session_status') {
    json_ok(array_merge(array('ok' => true), admin_session_payload()));
}

if ($method === 'POST' && $action === 'login') {
    $body = read_json_body();
    $username = isset($body['username']) ? (string)$body['username'] : '';
    $password = isset($body['password']) ? (string)$body['password'] : '';

    if (!admin_login_matches($username, $password)) {
        json_response(array(
            'ok' => false,
            'authenticated' => false,
            'error' => 'Usuario ou senha incorretos.'
        ), 401);
    }

    admin_log_in($username);
    json_ok(array_merge(array('ok' => true), admin_session_payload()));
}

if ($method === 'POST' && $action === 'logout') {
    admin_log_out();
    json_ok(array(
        'ok' => true,
        'authenticated' => false,
        'username' => ''
    ));
}

if (admin_action_requires_auth($action)) {
    require_admin_authentication();
}

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
    $fullyCloud = fully_cloud_status();
    $devices = array_map(function ($device) use ($registry, $fullyCloud) {
        return build_device_payload($device, $registry, $fullyCloud);
    }, list_all_device_ids($registry));

    json_ok(array(
        'ok' => true,
        'devices' => $devices,
        'fully_cloud' => $fullyCloud
    ));
}

if ($method === 'GET' && $action === 'get_fully_cloud_status') {
    json_ok(array(
        'ok' => true,
        'fully_cloud' => fully_cloud_status()
    ));
}

if (($method === 'GET' || $method === 'HEAD') && $action === 'export_fully_manifest') {
    $device = sanitize_device(isset($_GET['device']) ? $_GET['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $registry = load_device_registry();
    $knownDevices = list_all_device_ids($registry);
    if (!in_array($device, $knownDevices, true)) {
        json_error('TV nao encontrada', 404);
    }

    $payload = build_fully_manifest_payload($device, $registry);
    $json = json_encode_payload($payload, JSON_PRETTY_PRINT);
    if ($json === false) {
        json_error('Falha ao gerar manifesto do Fully', 500);
    }

    header('Content-Disposition: inline; filename="' . fully_manifest_filename($device) . '"');
    if ($method === 'HEAD') {
        exit;
    }
    echo $json . PHP_EOL;
    exit;
}

if (($method === 'GET' || $method === 'HEAD') && $action === 'export_fully_settings') {
    $device = sanitize_device(isset($_GET['device']) ? $_GET['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }
    if (fully_export_secret() !== '' && !validate_fully_export_token('export_fully_settings', $device, isset($_GET['token']) ? $_GET['token'] : '')) {
        json_error('Token de exportacao invalido', 403);
    }

    $registry = load_device_registry();
    $knownDevices = list_all_device_ids($registry);
    if (!in_array($device, $knownDevices, true)) {
        json_error('TV nao encontrada', 404);
    }

    $settingsBuild = build_fully_settings_payload($device, $registry);
    if (!$settingsBuild['ok']) {
        json_error(isset($settingsBuild['error']) ? $settingsBuild['error'] : 'Nao foi possivel gerar os settings do Fully.', 409);
    }

    $json = json_encode_payload($settingsBuild['settings'], JSON_PRETTY_PRINT);
    if ($json === false) {
        json_error('Falha ao gerar fully-video-settings.json', 500);
    }

    header('Content-Disposition: inline; filename="' . fully_settings_filename($device) . '"');
    if ($method === 'HEAD') {
        exit;
    }
    echo $json . PHP_EOL;
    exit;
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

    $clientPlaylistVersion = trim((string)(isset($_REQUEST['playlist_version']) ? $_REQUEST['playlist_version'] : ''));
    $serverPlaylistVersion = playlist_version_for_device($device);

    json_ok(array(
        'ok' => true,
        'device' => $device,
        'last_seen_unix' => $now,
        'playlist_version' => $serverPlaylistVersion,
        'playlist_changed' => $serverPlaylistVersion !== '' && $serverPlaylistVersion !== $clientPlaylistVersion
    ));
}

if ($method === 'POST' && $action === 'player_event') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $events = array();
    if (isset($body['events']) && is_array($body['events'])) {
        $events = $body['events'];
    } elseif (isset($body['event']) && is_array($body['event'])) {
        $events = array($body['event']);
    }

    $written = write_player_log_events($device, $events);
    if ($written <= 0) {
        json_error('Nenhum evento valido para registrar', 400);
    }

    json_ok(array(
        'ok' => true,
        'device' => $device,
        'written' => $written
    ));
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

if ($method === 'GET' && $action === 'list_player_logs') {
    $device = sanitize_device(isset($_GET['device']) ? $_GET['device'] : '');
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $severities = normalize_log_severities(isset($_GET['severity']) ? $_GET['severity'] : 'error');

    json_ok(array(
        'ok' => true,
        'device' => $device,
        'logs' => read_recent_player_logs($device, $limit, $severities)
    ));
}

if ($method === 'GET' && $action === 'get_playlist') {
    $device = sanitize_device(isset($_GET['device']) ? $_GET['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $path = playlist_path_for_device($device);
    if (!file_exists($path)) {
        json_ok(array(
            'ok' => true,
            'device' => $device,
            'playlist' => array(),
            'playlist_version' => ''
        ));
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        json_error('Falha ao ler playlist', 500);
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['playlist']) || !is_array($decoded['playlist'])) {
        json_error('Playlist invalida no arquivo', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => $device,
        'playlist' => $decoded['playlist'],
        'playlist_version' => playlist_version_for_device($device, $decoded)
    ));
}

if ($method === 'POST' && $action === 'save_playlist') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    $playlist = isset($body['playlist']) ? $body['playlist'] : null;

    if ($device === '' || !is_array($playlist)) {
        json_error('Dados incompletos', 400);
    }

    $normalized = normalize_playlist_items($playlist);

    $version = generate_playlist_version();
    $path = playlist_path_for_device($device);
    if (!save_json_file($path, build_playlist_payload($normalized, $version))) {
        json_error('Nao foi possivel salvar arquivo', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => $device,
        'count' => count($normalized),
        'playlist_version' => $version
    ));
}

if ($method === 'POST' && $action === 'append_item_to_playlists') {
    $body = read_json_body();
    $devices = isset($body['devices']) && is_array($body['devices']) ? $body['devices'] : array();
    $item = normalize_playlist_item(isset($body['item']) ? $body['item'] : null);

    if ($item === null) {
        json_error('Item de playlist invalido', 400);
    }

    $normalizedDevices = array_values(array_unique(array_filter(array_map('sanitize_device', $devices), function ($value) {
        return $value !== '';
    })));

    if (empty($normalizedDevices)) {
        json_error('Selecione ao menos uma playlist', 400);
    }

    $registry = load_device_registry();
    $knownDevices = array_flip(list_all_device_ids($registry));
    $updatedDevices = array();
    $skippedDevices = array();

    foreach ($normalizedDevices as $device) {
        if (!isset($knownDevices[$device]) && !file_exists(playlist_path_for_device($device))) {
            $skippedDevices[] = $device;
            continue;
        }

        if (!ensure_playlist_file($device)) {
            json_error('Nao foi possivel preparar a playlist da TV ' . $device, 500);
        }

        $payload = load_playlist_file($device);
        $playlistItems = is_array($payload) && isset($payload['playlist']) && is_array($payload['playlist'])
            ? normalize_playlist_items($payload['playlist'])
            : array();

        $playlistItems[] = $item;
        if (!save_json_file(playlist_path_for_device($device), build_playlist_payload($playlistItems))) {
            json_error('Nao foi possivel atualizar a playlist da TV ' . $device, 500);
        }

        $updatedDevices[] = $device;
    }

    if (empty($updatedDevices)) {
        json_error('Nenhuma playlist valida foi atualizada', 400);
    }

    json_ok(array(
        'ok' => true,
        'devices' => $updatedDevices,
        'skipped_devices' => $skippedDevices,
        'count' => count($updatedDevices),
        'item' => $item
    ));
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
        'created_at' => gmdate('c'),
        'fully_enabled' => false,
        'fully_audio_enabled' => true,
        'fully_wallpaper_media' => '',
        'fully_device_id' => ''
    );

    if (!save_device_registry($registry)) {
        json_error('Nao foi possivel salvar os dados da TV', 500);
    }

    $fullyCloud = fully_cloud_status();
    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry, $fullyCloud)
    ));
}

if ($method === 'POST' && $action === 'clone_playlist') {
    $body = read_json_body();
    $sourceDevice = sanitize_device(isset($body['source_device']) ? $body['source_device'] : '');
    $friendlyName = sanitize_label(isset($body['friendly_name']) ? $body['friendly_name'] : '');

    if ($sourceDevice === '') {
        json_error('Selecione a TV de origem da playlist', 400);
    }

    $sourcePayload = load_playlist_file($sourceDevice);
    if (!is_array($sourcePayload)) {
        json_error('Playlist de origem nao encontrada', 404);
    }

    $registry = load_device_registry();
    $device = next_device_id(list_all_device_ids($registry));
    $playlistItems = normalize_playlist_items($sourcePayload['playlist']);
    $playlistPath = playlist_path_for_device($device);

    if (!save_json_file($playlistPath, build_playlist_payload($playlistItems))) {
        json_error('Nao foi possivel clonar a playlist para a nova TV', 500);
    }

    $registry['devices'][$device] = array(
        'friendly_name' => $friendlyName !== '' ? $friendlyName : $device,
        'created_at' => gmdate('c'),
        'fully_enabled' => false,
        'fully_audio_enabled' => true,
        'fully_wallpaper_media' => '',
        'fully_device_id' => ''
    );

    if (!save_device_registry($registry)) {
        @unlink($playlistPath);
        json_error('Nao foi possivel salvar os dados da nova TV', 500);
    }

    $fullyCloud = fully_cloud_status();
    json_ok(array(
        'ok' => true,
        'source_device' => $sourceDevice,
        'device' => build_device_payload($device, $registry, $fullyCloud),
        'count' => count($playlistItems)
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
    $fullyEnabled = request_flag_enabled(isset($body['fully_enabled']) ? $body['fully_enabled'] : $entry['fully_enabled']);
    $fullyAudioEnabled = !isset($body['fully_audio_enabled'])
        ? $entry['fully_audio_enabled']
        : request_flag_enabled($body['fully_audio_enabled']);
    $fullyWallpaperMedia = !isset($body['fully_wallpaper_media'])
        ? $entry['fully_wallpaper_media']
        : sanitize_wallpaper_media_name($body['fully_wallpaper_media']);
    $fullyDeviceId = sanitize_fully_device_id(isset($body['fully_device_id']) ? $body['fully_device_id'] : $entry['fully_device_id']);
    $currentEntry = isset($registry['devices'][$device]) && is_array($registry['devices'][$device]) ? $registry['devices'][$device] : array();
    $lastSync = isset($entry['fully_last_sync']) && is_array($entry['fully_last_sync']) ? $entry['fully_last_sync'] : array();
    $template = isset($currentEntry['fully_settings_template']) && is_array($currentEntry['fully_settings_template'])
        ? $currentEntry['fully_settings_template']
        : array();
    $templateFetchedAt = isset($currentEntry['fully_settings_template_fetched_at']) && is_string($currentEntry['fully_settings_template_fetched_at'])
        ? trim($currentEntry['fully_settings_template_fetched_at'])
        : '';

    if ($fullyDeviceId !== $entry['fully_device_id']) {
        $template = array();
        $templateFetchedAt = '';
        $lastSync = array(
            'last_sync_at' => '',
            'last_sync_status' => '',
            'last_sync_message' => '',
            'last_sync_hash' => ''
        );
    }

    $registry['devices'][$device] = array_merge($currentEntry, array(
        'friendly_name' => $friendlyName !== '' ? $friendlyName : $device,
        'created_at' => $entry['created_at'],
        'fully_enabled' => $fullyEnabled,
        'fully_audio_enabled' => $fullyAudioEnabled,
        'fully_wallpaper_media' => $fullyWallpaperMedia,
        'fully_device_id' => $fullyDeviceId,
        'fully_settings_template' => $template,
        'fully_settings_template_fetched_at' => $templateFetchedAt,
        'fully_last_sync_at' => isset($lastSync['last_sync_at']) ? $lastSync['last_sync_at'] : '',
        'fully_last_sync_status' => isset($lastSync['last_sync_status']) ? $lastSync['last_sync_status'] : '',
        'fully_last_sync_message' => isset($lastSync['last_sync_message']) ? $lastSync['last_sync_message'] : '',
        'fully_last_sync_hash' => isset($lastSync['last_sync_hash']) ? $lastSync['last_sync_hash'] : ''
    ));

    if (!save_device_registry($registry)) {
        json_error('Nao foi possivel atualizar os dados da TV', 500);
    }

    $fullyCloud = fully_cloud_status();
    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry, $fullyCloud)
    ));
}

if ($method === 'POST' && $action === 'fetch_fully_device_settings') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $fullyCloud = fully_cloud_status();
    if (!$fullyCloud['api_configured']) {
        json_error('Configure WISETV_FULLY_CLOUD_API_EMAIL e WISETV_FULLY_CLOUD_API_KEY antes de consultar o Fully Cloud.', 409);
    }

    $registry = load_device_registry();
    $knownDevices = list_all_device_ids($registry);
    if (!in_array($device, $knownDevices, true)) {
        json_error('TV nao encontrada', 404);
    }

    $entry = normalize_registry_entry($device, $registry);
    if ($entry['fully_device_id'] === '') {
        json_error('Preencha o Device ID do Fully Cloud para esta TV antes de consultar os settings.', 409);
    }

    $response = fetch_fully_device_settings_template($entry['fully_device_id']);
    if (!$response['ok']) {
        json_error(isset($response['error']) ? $response['error'] : 'Falha ao consultar o Fully Cloud.', 502);
    }

    $registry = save_device_fully_settings_template($registry, $device, $response['settings']);
    if (!save_device_registry($registry)) {
        json_error('Os settings foram lidos do Fully Cloud, mas nao foi possivel salvar o template local.', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry, fully_cloud_status()),
        'fully_device_id' => $entry['fully_device_id'],
        'fully_cloud' => $fullyCloud,
        'response' => $response['response']
    ));
}

if ($method === 'POST' && $action === 'sync_fully_device') {
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
    if (!$entry['fully_enabled']) {
        json_error('Ative a integracao com Fully Cloud para esta TV antes de sincronizar.', 409);
    }
    if ($entry['fully_device_id'] === '') {
        json_error('Preencha o Device ID do Fully Cloud para esta TV antes de sincronizar.', 409);
    }

    $fullyCloud = fully_cloud_status();
    if (!$fullyCloud['api_configured']) {
        json_error('Configure WISETV_FULLY_CLOUD_API_EMAIL e WISETV_FULLY_CLOUD_API_KEY antes de sincronizar.', 409);
    }
    if (!$fullyCloud['public_base_configured']) {
        json_error('Configure WISETV_PUBLIC_BASE_URL para gerar uma URL publica de settings.', 409);
    }
    $rawEntry = isset($registry['devices'][$device]) && is_array($registry['devices'][$device]) ? $registry['devices'][$device] : array();
    if (!fully_settings_template_ready_from_entry($rawEntry)) {
        $settingsTemplateResponse = fetch_fully_device_settings_template($entry['fully_device_id']);
        if (!$settingsTemplateResponse['ok']) {
            json_error(
                isset($settingsTemplateResponse['error']) ? $settingsTemplateResponse['error'] : 'Falha ao ler os settings do Fully antes da sincronizacao.',
                502
            );
        }

        $registry = save_device_fully_settings_template($registry, $device, $settingsTemplateResponse['settings']);
        if (!save_device_registry($registry)) {
            json_error('Os settings do Fully foram lidos, mas nao foi possivel salvar o template local.', 500);
        }
    }

    $manifest = build_fully_manifest_payload($device, $registry);
    $settingsUrl = build_fully_settings_export_url($device);
    $response = fully_cloud_remote_request($entry['fully_device_id'], array(
        'cmd' => 'importSettingsFile',
        'url' => $settingsUrl
    ), true, false);

    if (!$response['ok']) {
        $registry = update_device_sync_state($registry, $device, 'error', isset($response['error']) ? $response['error'] : 'Falha ao sincronizar', $manifest['playlist_hash']);
        save_device_registry($registry);
        json_error(isset($response['error']) ? $response['error'] : 'Falha ao sincronizar com o Fully Cloud.', 502);
    }

    $syncStatus = fully_cloud_response_status($response);
    if ($syncStatus === '') {
        $syncStatus = 'OK';
    }
    $syncMessage = fully_cloud_response_text($response, 'Comando enviado ao Fully Cloud');

    $startUrl = build_fully_wallpaper_page_url($device);
    $startUrlResponse = fully_cloud_set_string_setting($entry['fully_device_id'], 'startURL', $startUrl);
    if (!$startUrlResponse['ok'] || strcasecmp(fully_cloud_response_status($startUrlResponse), 'Error') === 0) {
        $startUrlWarning = fully_cloud_response_text($startUrlResponse, 'Nao foi possivel gravar o Start URL no Fully Cloud.');
        if ($startUrlWarning !== '') {
            $syncMessage .= ' Start URL: ' . $startUrlWarning;
        }
    }

    $wallpaperUrl = build_fully_wallpaper_url($device, $registry);
    $wallpaperResponse = fully_cloud_set_string_setting($entry['fully_device_id'], 'wallpaperURL', $wallpaperUrl);
    if (!$wallpaperResponse['ok'] || strcasecmp(fully_cloud_response_status($wallpaperResponse), 'Error') === 0) {
        $wallpaperSetWarning = fully_cloud_response_text($wallpaperResponse, 'Nao foi possivel gravar o Wallpaper URL no Fully Cloud.');
        if ($wallpaperSetWarning !== '') {
            $syncMessage .= ' Wallpaper URL: ' . $wallpaperSetWarning;
        }
    }

    $reloadWallpaperResponse = fully_cloud_reload_start_url($entry['fully_device_id']);
    if (!$reloadWallpaperResponse['ok'] || strcasecmp(fully_cloud_response_status($reloadWallpaperResponse), 'Error') === 0) {
        $wallpaperWarning = fully_cloud_response_text($reloadWallpaperResponse, 'Nao foi possivel recarregar o wallpaper no Fully Cloud.');
        if ($wallpaperWarning !== '') {
            $syncMessage .= ' Wallpaper: ' . $wallpaperWarning;
        }
    }

    $playerStartResponse = fully_cloud_start_playlist($entry['fully_device_id']);
    if (!$playerStartResponse['ok'] || strcasecmp(fully_cloud_response_status($playerStartResponse), 'Error') === 0) {
        $playerWarning = fully_cloud_response_text($playerStartResponse, 'Nao foi possivel reiniciar a playlist no Fully Cloud.');
        if ($playerWarning !== '') {
            $syncMessage .= ' Player: ' . $playerWarning;
        }
    }

    $audioResponse = fully_cloud_apply_audio_preference($entry['fully_device_id'], $entry['fully_audio_enabled']);
    if (!$audioResponse['ok'] || strcasecmp(fully_cloud_response_status($audioResponse), 'Error') === 0) {
        $audioWarning = fully_cloud_response_text($audioResponse, 'Nao foi possivel ajustar o audio no Fully Cloud.');
        if ($audioWarning !== '') {
            $syncMessage .= ' Audio: ' . $audioWarning;
        }
    }

    $registry = update_device_sync_state($registry, $device, $syncStatus, $syncMessage, $manifest['playlist_hash']);
    if (!save_device_registry($registry)) {
        json_error('Sincronizacao enviada, mas nao foi possivel salvar o status local.', 500);
    }

    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry, fully_cloud_status()),
        'fully_cloud' => fully_cloud_status(),
        'response' => $response['response']
    ));
}

if ($method === 'POST' && $action === 'fully_control_device') {
    $body = read_json_body();
    $device = sanitize_device(isset($body['device']) ? $body['device'] : '');
    $control = trim((string)(isset($body['control']) ? $body['control'] : ''));
    if ($device === '') {
        json_error('Device invalido', 400);
    }

    $allowedControls = array(
        'open_fully' => 'Abrindo o Fully na TV.',
        'restart_playlist' => 'Reiniciando a playlist da TV.',
        'restart_app' => 'Reiniciando o app Fully na TV.'
    );
    if (!isset($allowedControls[$control])) {
        json_error('Comando do Fully invalido', 400);
    }

    $registry = load_device_registry();
    $knownDevices = list_all_device_ids($registry);
    if (!in_array($device, $knownDevices, true)) {
        json_error('TV nao encontrada', 404);
    }

    $entry = normalize_registry_entry($device, $registry);
    if (!$entry['fully_enabled']) {
        json_error('Ative a integracao com Fully Cloud para esta TV antes de usar comandos remotos.', 409);
    }
    if ($entry['fully_device_id'] === '') {
        json_error('Preencha o Device ID do Fully Cloud para esta TV antes de usar comandos remotos.', 409);
    }

    $fullyCloud = fully_cloud_status();
    if (!$fullyCloud['api_configured']) {
        json_error('Configure WISETV_FULLY_CLOUD_API_EMAIL e WISETV_FULLY_CLOUD_API_KEY antes de usar comandos remotos do Fully Cloud.', 409);
    }

    $message = $allowedControls[$control];
    $response = null;
    $warnings = array();

    if ($control === 'open_fully') {
        $response = fully_cloud_bring_to_foreground($entry['fully_device_id'], true);
        if (fully_cloud_response_has_error($response)) {
            json_error(fully_cloud_response_text($response, 'Nao foi possivel trazer o Fully para frente nesta TV.'), 502);
        }

        $reloadResponse = fully_cloud_reload_start_url($entry['fully_device_id']);
        if (fully_cloud_response_has_error($reloadResponse)) {
            $warnings[] = fully_cloud_response_text($reloadResponse, 'Nao foi possivel recarregar a Start URL do Fully.');
        }

        $playerResponse = fully_cloud_start_playlist($entry['fully_device_id']);
        if (fully_cloud_response_has_error($playerResponse)) {
            $warnings[] = fully_cloud_response_text($playerResponse, 'Nao foi possivel reiniciar a playlist do Fully.');
        }

        $message = fully_cloud_response_text($response, 'Fully trazido para frente na TV.');
    } elseif ($control === 'restart_playlist') {
        $response = fully_cloud_start_playlist($entry['fully_device_id']);
        if (fully_cloud_response_has_error($response)) {
            json_error(fully_cloud_response_text($response, 'Nao foi possivel reiniciar a playlist desta TV.'), 502);
        }

        $message = fully_cloud_response_text($response, 'Playlist reiniciada na TV.');
    } else {
        $response = fully_cloud_restart_app($entry['fully_device_id'], false);
        if (fully_cloud_response_has_error($response)) {
            json_error(fully_cloud_response_text($response, 'Nao foi possivel reiniciar o app Fully nesta TV.'), 502);
        }

        $message = fully_cloud_response_text($response, 'App Fully reiniciado na TV.');
    }

    if (!empty($warnings)) {
        $message .= ' ' . implode(' ', $warnings);
    }

    json_ok(array(
        'ok' => true,
        'device' => build_device_payload($device, $registry, fully_cloud_status()),
        'fully_cloud' => $fullyCloud,
        'control' => $control,
        'message' => $message,
        'response' => isset($response['response']) && is_array($response['response']) ? $response['response'] : array()
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
    $wallpaperStats = update_device_wallpaper_references($oldName, $newName);

    json_ok(array(
        'ok' => true,
        'name' => $newName,
        'updated_playlists' => $stats['files_updated'],
        'updated_items' => $stats['items_updated'],
        'updated_wallpapers' => $wallpaperStats['devices_updated']
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
    $wallpaperStats = update_device_wallpaper_references($name, null);

    json_ok(array(
        'ok' => true,
        'name' => $name,
        'updated_playlists' => $stats['files_updated'],
        'updated_items' => $stats['items_updated'],
        'updated_wallpapers' => $wallpaperStats['devices_updated']
    ));
}

if ($method === 'POST' && $action === 'upload_media') {
    if (!is_dir(MIDIAS_DIR)) {
        @mkdir(MIDIAS_DIR, 0775, true);
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
        json_error('Falha no upload (codigo ' . $errorCode . ')', 400);
    }

    $tmpName = isset($file['tmp_name']) ? $file['tmp_name'] : '';
    $originalName = isset($file['name']) ? $file['name'] : '';
    $size = isset($file['size']) ? (int)$file['size'] : 0;

    if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
        json_error('Arquivo muito grande ou vazio', 400);
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
    $videoNormalized = false;
    $audioRemoved = false;
    $normalizationWarning = '';

    if ($type === 'video') {
        $stripAudio = request_flag_enabled(isset($_POST['strip_audio']) ? $_POST['strip_audio'] : false);
        $videoMetadata = video_metadata_from_file($target);

        if ($capabilities['normalize_videos_on_upload']) {
            $normalizedName = normalized_video_filename($safeName, $target);
            $normalizedTempPath = temporary_normalized_video_path();
            if (!normalize_video_for_signage($target, $normalizedTempPath, $stripAudio)) {
                @unlink($normalizedTempPath);
                @unlink($target);
                json_error('Nao foi possivel padronizar o video enviado para o perfil da plataforma.', 500);
            }

            $finalPath = MIDIAS_DIR . '/' . $normalizedName;
            $sameOutputPath = str_replace('\\', '/', $finalPath) === str_replace('\\', '/', $target);

            if ($sameOutputPath) {
                if (!@unlink($target)) {
                    @unlink($normalizedTempPath);
                    json_error('Nao foi possivel substituir o video original pela versao padronizada.', 500);
                }
                if (!@rename($normalizedTempPath, $finalPath)) {
                    @unlink($normalizedTempPath);
                    json_error('Nao foi possivel salvar o video padronizado.', 500);
                }
            } else {
                if (!@rename($normalizedTempPath, $finalPath)) {
                    @unlink($normalizedTempPath);
                    json_error('Nao foi possivel salvar o video padronizado.', 500);
                }
                @unlink($target);
            }

            $safeName = $normalizedName;
            $target = $finalPath;
            $videoNormalized = true;
            $convertedToHd = true;
            $audioRemoved = $stripAudio;
            $videoMetadata = video_metadata_from_file($target);
        } elseif ($stripAudio) {
            $normalizationWarning = 'Servidor sem ffmpeg: o audio foi mantido e o video foi salvo sem padronizacao.';
        } else {
            $normalizationWarning = 'Servidor sem ffmpeg: o video foi salvo no formato original, sem padronizacao.';
        }
    }

    json_ok(array(
        'ok' => true,
        'name' => $safeName,
        'type' => $type,
        'url' => media_url_for_name($safeName),
        'converted_to_hd' => $convertedToHd,
        'video_normalized' => $videoNormalized,
        'audio_removed' => $audioRemoved,
        'normalization_warning' => $normalizationWarning,
        'normalization_profile' => upload_normalization_profile(),
        'normalization_profile_label' => upload_normalization_profile_label($audioRemoved),
        'video_metadata' => $videoMetadata,
        'capabilities' => $capabilities
    ));
}

json_error('Acao nao suportada', 404);
