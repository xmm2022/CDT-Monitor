<?php

require_once __DIR__ . '/AliyunProvider.php';
require_once __DIR__ . '/HuaweiCloudProvider.php';

class ProviderFactory
{
    private $providers = [];

    public function __construct(?AliyunService $aliyunService = null)
    {
        $aliyunProvider = new AliyunProvider($aliyunService);
        $this->providers[$aliyunProvider->getProviderKey()] = $aliyunProvider;

        $huaweiProvider = new HuaweiCloudProvider();
        $this->providers[$huaweiProvider->getProviderKey()] = $huaweiProvider;
    }

    public function getProvider(string $providerKey): CloudProviderInterface
    {
        $providerKey = $providerKey ?: 'aliyun';

        if (!isset($this->providers[$providerKey])) {
            throw new Exception("暂不支持的云厂商: {$providerKey}");
        }

        return $this->providers[$providerKey];
    }
}
