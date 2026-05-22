<?php

interface CloudProviderInterface
{
    public function getProviderKey(): string;

    public function getCapabilities(array $account): array;

    public function getTraffic(array $account);

    public function getInstanceStatus(array $account);

    public function controlInstance(array $account, string $action, string $shutdownMode = 'KeepCharging');

    public function getAccountBalance(array $account);

    public function getInstanceBill(array $account, string $billingCycle);

    public function getBillOverview(array $account, string $billingCycle);

    public function getInstanceSecurityGroups(array $account);

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule);

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule);
}
