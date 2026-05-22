<?php

require_once __DIR__ . '/../AliyunService.php';
require_once __DIR__ . '/CloudProviderInterface.php';

class AliyunProvider implements CloudProviderInterface
{
    private $service;

    public function __construct(?AliyunService $service = null)
    {
        $this->service = $service ?: new AliyunService();
    }

    public function getProviderKey(): string
    {
        return 'aliyun';
    }

    public function getCapabilities(array $account): array
    {
        return [
            'traffic_monitor' => true,
            'security_group_manage' => true,
            'instance_start_stop' => true,
            'stop_charging' => true,
            'billing_summary' => true,
            'schedule_manage' => true,
            'per_account_proxy' => true,
            'site_type_select' => true,
            'region_picker' => true,
        ];
    }

    public function getTraffic(array $account)
    {
        return $this->service->getTraffic($account);
    }

    public function getInstanceStatus(array $account)
    {
        return $this->service->getInstanceStatus($account);
    }

    public function controlInstance(array $account, string $action, string $shutdownMode = 'KeepCharging')
    {
        return $this->service->controlInstance($account, $action, $shutdownMode);
    }

    public function getAccountBalance(array $account)
    {
        return $this->service->getAccountBalance($account);
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        return $this->service->getInstanceBill($account, $billingCycle);
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        return $this->service->getBillOverview($account, $billingCycle);
    }

    public function getInstanceSecurityGroups(array $account)
    {
        return $this->service->getInstanceSecurityGroups($account);
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        return $this->service->addSecurityGroupRule($account, $securityGroupId, $rule);
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        return $this->service->deleteSecurityGroupRule($account, $securityGroupId, $rule);
    }
}
