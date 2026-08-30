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

function loadKfsEnvFile(string $path, bool $required = false): int
{
    if (!file_exists($path)) {
        if ($required) {
            throw new RuntimeException('Private configuration is invalid.');
        }

        return 0;
    }

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Private configuration is invalid.');
    }

    $contents = @file_get_contents($path);
    if ($contents === false || str_contains($contents, "\0")) {
        throw new RuntimeException('Private configuration is invalid.');
    }

    $values = @parse_ini_string($contents, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('Private configuration is invalid.');
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
