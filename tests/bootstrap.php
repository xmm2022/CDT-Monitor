<?php

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function pass(string $message): void
{
    fwrite(STDOUT, "PASS: {$message}\n");
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $exportExpected = var_export($expected, true);
        $exportActual = var_export($actual, true);
        fail(($message ? $message . ' - ' : '') . "expected {$exportExpected}, got {$exportActual}");
    }
}

function assertTrue($actual, string $message = ''): void
{
    assertSame(true, $actual, $message);
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        fail(($message ? $message . ' - ' : '') . "expected to find {$needle} in {$haystack}");
    }
}

function createTempDbPath(string $name): string
{
    $dir = sys_get_temp_dir() . '/cdt-monitor-tests';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail('unable to create temp dir');
    }

    $path = $dir . '/' . $name . '-' . uniqid('', true) . '.sqlite';
    if (file_exists($path)) {
        unlink($path);
    }

    return $path;
}
