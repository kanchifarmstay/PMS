<?php
declare(strict_types=1);

function kfsEnvFilePath(string $channelManagerDir = __DIR__): string
{
    $override = getenv('KFS_ENV_FILE');
    if ($override !== false && trim($override) !== '') {
        return trim($override);
    }

    return dirname($channelManagerDir, 2) . '/kfs.env';
}

function loadKfsEnvFile(string $path): int
{
    if (!is_file($path) || !is_readable($path)) {
        return 0;
    }

    $values = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if ($values === false) {
        return 0;
    }

    $assignments = [];
    foreach ($values as $key => $value) {
        if (!is_string($key)
            || preg_match('/^KFS_[A-Z0-9_]+$/', $key) !== 1
            || !is_scalar($value)
            || getenv($key) !== false
        ) {
            continue;
        }

        $assignments[$key] = (string)$value;
    }

    $loaded = 0;
    foreach ($assignments as $key => $value) {
        if (putenv($key . '=' . $value)) {
            $loaded++;
        }
    }

    return $loaded;
}
