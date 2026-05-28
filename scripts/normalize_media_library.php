<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$mediaDir = getenv('WISETV_NORMALIZE_MEDIA_DIR');
if (!is_string($mediaDir) || trim($mediaDir) === '') {
    $mediaDir = __DIR__ . '/../midias';
}
$mediaDir = rtrim($mediaDir, "/\\");

if (!is_dir($mediaDir)) {
    fwrite(STDERR, "Diretorio de midia nao encontrado: {$mediaDir}\n");
    exit(1);
}

$extensions = array('mp4', 'mov', 'm4v', 'webm');
$files = array_values(array_filter(scandir($mediaDir) ?: array(), function ($entry) use ($mediaDir, $extensions) {
    if ($entry === '.' || $entry === '..') {
        return false;
    }

    $path = $mediaDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_file($path)) {
        return false;
    }

    $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
    return in_array($extension, $extensions, true);
}));

sort($files, SORT_NATURAL | SORT_FLAG_CASE);

if (empty($files)) {
    fwrite(STDOUT, "Nenhum video encontrado em {$mediaDir}\n");
    exit(0);
}

function run_process(array $command): array
{
    $descriptor = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Nao foi possivel iniciar o processo: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return array(
        'code' => $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    );
}

function probe_media(string $path): array
{
    $result = run_process(array(
        'ffprobe',
        '-v', 'error',
        '-show_entries', 'stream=codec_name,codec_type,width,height,r_frame_rate,pix_fmt,profile,bit_rate:format=duration,size,bit_rate',
        '-of', 'json',
        $path,
    ));

    if ($result['code'] !== 0) {
        throw new RuntimeException(trim($result['stderr']) !== '' ? trim($result['stderr']) : 'ffprobe falhou.');
    }

    $decoded = json_decode($result['stdout'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('ffprobe retornou JSON invalido.');
    }

    return $decoded;
}

function format_summary(array $probe): string
{
    $video = array();
    foreach (($probe['streams'] ?? array()) as $stream) {
        if (($stream['codec_type'] ?? '') === 'video') {
            $video = $stream;
            break;
        }
    }

    $format = is_array($probe['format'] ?? null) ? $probe['format'] : array();

    return sprintf(
        '%s %sx%s %s %s',
        (string)($video['codec_name'] ?? '?'),
        (string)($video['width'] ?? '?'),
        (string)($video['height'] ?? '?'),
        (string)($video['r_frame_rate'] ?? '?'),
        (string)($format['bit_rate'] ?? '?')
    );
}

$success = array();
$failed = array();
$total = count($files);

fwrite(STDOUT, "Normalizando {$total} video(s) em {$mediaDir}\n");

foreach ($files as $index => $fileName) {
    $path = $mediaDir . DIRECTORY_SEPARATOR . $fileName;
    $tmpPath = $path . '.wisetv-normalize.tmp.mp4';
    $backupPath = $path . '.wisetv-normalize.bak';

    fwrite(STDOUT, sprintf("[%d/%d] %s\n", $index + 1, $total, $fileName));

    try {
        $before = probe_media($path);
        fwrite(STDOUT, '  Antes: ' . format_summary($before) . "\n");

        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
        }
        if (file_exists($backupPath)) {
            @unlink($backupPath);
        }

        $command = array(
            'ffmpeg',
            '-y',
            '-i', $path,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:color=black,fps=30',
            '-c:v', 'libx264',
            '-profile:v', 'main',
            '-pix_fmt', 'yuv420p',
            '-preset', 'medium',
            '-crf', '21',
            '-maxrate', '4M',
            '-bufsize', '8M',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ac', '2',
            '-movflags', '+faststart',
            $tmpPath,
        );

        $result = run_process($command);
        if ($result['code'] !== 0) {
            throw new RuntimeException(trim($result['stderr']) !== '' ? trim($result['stderr']) : 'ffmpeg falhou.');
        }

        if (!file_exists($tmpPath)) {
            throw new RuntimeException('Arquivo temporario nao foi gerado.');
        }

        if (!rename($path, $backupPath)) {
            throw new RuntimeException('Nao foi possivel mover o arquivo original para backup temporario.');
        }
        if (!rename($tmpPath, $path)) {
            @rename($backupPath, $path);
            throw new RuntimeException('Nao foi possivel substituir o arquivo original pelo arquivo normalizado.');
        }
        @unlink($backupPath);

        $after = probe_media($path);
        fwrite(STDOUT, '  Depois: ' . format_summary($after) . "\n");
        $success[] = $fileName;
    } catch (Throwable $e) {
        if (file_exists($tmpPath)) {
            @unlink($tmpPath);
        }
        if (file_exists($backupPath) && !file_exists($path)) {
            @rename($backupPath, $path);
        }

        $message = $e->getMessage();
        fwrite(STDERR, "  ERRO: {$message}\n");
        $failed[$fileName] = $message;
    }
}

fwrite(STDOUT, "\nResumo:\n");
fwrite(STDOUT, '  Sucesso: ' . count($success) . "\n");
fwrite(STDOUT, '  Falhas: ' . count($failed) . "\n");

if (!empty($failed)) {
    foreach ($failed as $fileName => $message) {
        fwrite(STDERR, "  - {$fileName}: {$message}\n");
    }
    exit(2);
}

exit(0);
