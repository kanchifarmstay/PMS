<?php
declare(strict_types=1);

$testDb = sys_get_temp_dir() . '/kfs-pms-tests-' . getmypid() . '.sqlite';
@unlink($testDb);

putenv('KFS_DB_PATH=' . $testDb);
putenv('KFS_SITE_URL=https://example.test');
putenv('KFS_ADMIN_PASSWORD_HASH=' . password_hash('test-secret', PASSWORD_DEFAULT));
putenv('KFS_ICAL_TOKEN=test-ical-token');
putenv('KFS_CRON_SECRET=test-cron-token');
putenv('KFS_RAZORPAY_KEY_ID=rzp_test_example');
putenv('KFS_RAZORPAY_KEY_SECRET=test-razorpay-secret');
putenv('KFS_RAZORPAY_WEBHOOK_SECRET=test-webhook-secret');

$GLOBALS['kfs_test_db'] = $testDb;
$GLOBALS['kfs_tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['kfs_tests'][] = [$name, $fn];
}

function assertTrue(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertFalse(bool $condition, string $message = 'Expected false'): void
{
    assertTrue(!$condition, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message ?: 'Values are not identical';
        throw new RuntimeException($detail . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    assertTrue(str_contains($haystack, $needle), $message ?: "Expected to find {$needle}");
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    assertFalse(str_contains($haystack, $needle), $message ?: "Did not expect to find {$needle}");
}

function runTests(): never
{
    $failed = 0;
    foreach ($GLOBALS['kfs_tests'] as [$name, $fn]) {
        try {
            $fn();
            echo "PASS {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }

    $total = count($GLOBALS['kfs_tests']);
    echo "\n" . ($total - $failed) . "/{$total} passed\n";
    exit($failed === 0 ? 0 : 1);
}

