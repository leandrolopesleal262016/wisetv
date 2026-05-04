<?php
declare(strict_types=1);

// Fallback entrypoint for hosts configured to prefer PHP index files.
readfile(__DIR__ . '/index.html');
