<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

$passed = 0;
$failed = 0;

function reportHardeningTest(string $name, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

function setHardeningEnvironment(array $values): void {
    foreach ($values as $name => $value) {
        putenv($name . '=' . $value);
    }
}

setHardeningEnvironment([
    'APP_ENV' => 'production',
    'VERCEL' => '1',
    'VERCEL_ENV' => 'production',
    'APP_BASE_URL' => 'https://store.example.com',
    'VERCEL_URL' => 'untrusted-preview.vercel.app'
]);
reportHardeningTest(
    'Production origin uses APP_BASE_URL',
    getCanonicalAppOrigin() === 'https://store.example.com'
);
$_SERVER['HTTP_ORIGIN'] = 'https://store.example.com';
$_SERVER['HTTP_HOST'] = 'attacker.example';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example';
reportHardeningTest(
    'Production same-origin check ignores forwarded host and requires configured origin',
    getSameOriginRequestError() === null
);
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
reportHardeningTest(
    'Production rejects an origin that differs from APP_BASE_URL',
    getSameOriginRequestError() === 'Cross-origin requests are not allowed.'
);

setHardeningEnvironment([
    'APP_ENV' => 'production',
    'VERCEL' => '1',
    'VERCEL_ENV' => 'preview',
    'APP_BASE_URL' => 'https://store.example.com',
    'VERCEL_URL' => 'feature-branch-123.vercel.app'
]);
$_SERVER['HTTP_ORIGIN'] = 'https://feature-branch-123.vercel.app';
$_SERVER['HTTP_HOST'] = 'attacker.example';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example';
reportHardeningTest(
    'Preview origin uses VERCEL_URL and ignores client host headers',
    getCanonicalAppOrigin() === 'https://feature-branch-123.vercel.app'
        && getSameOriginRequestError() === null
);
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
reportHardeningTest(
    'Preview rejects an origin that differs from VERCEL_URL',
    getSameOriginRequestError() === 'Cross-origin requests are not allowed.'
);
putenv('VERCEL_URL=attacker.example');
reportHardeningTest(
    'Preview rejects an invalid VERCEL_URL',
    getCanonicalAppOrigin() === null
);

putenv('VERCEL=1');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8';
$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
reportHardeningTest(
    'Vercel uses one valid client IP from the platform forwarded header',
    resolveLoginThrottleIp() === '203.0.113.8'
);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8, 192.0.2.9';
reportHardeningTest(
    'Vercel rejects forwarded-IP chains',
    resolveLoginThrottleIp() === null
);
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
reportHardeningTest(
    'Vercel rejects an invalid forwarded IP',
    resolveLoginThrottleIp() === null
);
putenv('VERCEL=0');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8';
reportHardeningTest(
    'Local throttling uses REMOTE_ADDR instead of forwarded headers',
    resolveLoginThrottleIp() === '192.0.2.9'
);
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
reportHardeningTest(
    'Local throttling rejects an invalid REMOTE_ADDR',
    resolveLoginThrottleIp() === null
);

$timeoutOptions = getDatabaseTimeoutOptions();
configureDatabaseReadTimeout();
reportHardeningTest(
    'MySQL connection and read timeouts are bounded',
    $timeoutOptions[PDO::ATTR_TIMEOUT] === 5
        && (int)ini_get('mysqlnd.net_read_timeout') === 10
);

echo "Hardening tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
