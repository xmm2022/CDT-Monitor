<?php

require_once __DIR__ . '/bootstrap.php';

$template = file_get_contents(__DIR__ . '/../template.html');

foreach (['tencentcloud', 'aws', 'gcp'] as $provider) {
    assertContains($provider, $template, "{$provider} should appear in frontend template");
}

assertContains('<option value="tencentcloud">腾讯云</option>', $template, 'Tencent option');
assertContains('<option value="aws">AWS</option>', $template, 'AWS option');
assertContains('<option value="gcp">GCP</option>', $template, 'GCP option');
assertContains('service_account_json', $template, 'GCP service account JSON field');
assertContains('getSecurityRuleLabel', $template, 'provider-specific security rule label helper');

pass('Frontend provider metadata');
