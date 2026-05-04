<?php
declare(strict_types=1);

$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($basePath === '') {
    $basePath = '';
}

header('Location: ' . $basePath . '/player/');
exit;
?>
