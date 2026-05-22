<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/ProviderFactory.php';

$factory = new ProviderFactory();

foreach (['aliyun', 'huaweicloud', 'tencentcloud', 'aws', 'gcp'] as $providerKey) {
    $provider = $factory->getProvider($providerKey);
    assertSame($providerKey, $provider->getProviderKey(), "{$providerKey} should resolve from factory");
}

pass('ProviderFactory multi-cloud registration');
