<?php
// Deployment-only configuration merge. Values are deliberately never printed.
if ($argc !== 3) { throw new RuntimeException('Usage: configure-test-environment target.env credentials.env'); }
[$script, $target, $credentials] = $argv;
if (! preg_match('#^/opt/decoadmin/(staging|releases/test-[a-f0-9]+-source)/\.env\.staging$#', $target)) {
    throw new RuntimeException('Only an explicit staging environment may be configured.');
}
$source = parse_ini_file($credentials, false, INI_SCANNER_RAW);
if (($source['SHOPIFY_API_KEY'] ?? '') !== 'a755a5ea264486246fd8836dab3e004c' || empty($source['SHOPIFY_API_SECRET'])) {
    throw new RuntimeException('Wrong or missing Deco Reviews test identity.');
}
$values = [
    'DECO_REVIEWS_ENVIRONMENT' => 'test',
    'DECO_REVIEWS_TEST_CLIENT_ID' => $source['SHOPIFY_API_KEY'],
    'DECO_REVIEWS_TEST_CLIENT_SECRET' => $source['SHOPIFY_API_SECRET'],
    'DECO_REVIEWS_TEST_DELIVERY_ENABLED' => 'false',
    'DECO_REVIEWS_TEST_AUTOMATION_STORES' => '',
    'DECO_REVIEWS_TEST_RECIPIENT_ALLOWLIST' => '',
];
$lines = file($target, FILE_IGNORE_NEW_LINES);
$lines = array_values(array_filter($lines, function ($line) use ($values) {
    $key = strstr($line, '=', true);
    return $key === false || ! array_key_exists($key, $values);
}));
foreach ($values as $key => $value) {
    if (preg_match('/[\r\n"\\\\]/', $value)) { throw new RuntimeException('Invalid credential encoding.'); }
    $lines[] = $key.'="'.$value.'"';
}
if (file_put_contents($target, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) { throw new RuntimeException('Configuration could not be saved.'); }
chmod($target, 0600);
echo 'Deco Reviews test configured; all automated sending disabled.'.PHP_EOL;
