<?php

trait InstanceContextHelpers
{
    protected function normalizeInstanceStatus(string $status, array $map): string
    {
        $key = strtoupper(trim($status));
        return $map[$key] ?? 'Unknown';
    }

    protected function decodeExtraConfig(array $account): array
    {
        $raw = $account['extra_config'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function buildIncompleteInstanceContext(array $account, string $message): array
    {
        return $this->buildBaseInstanceContext($account, [
            'discoveryStatus' => 'incomplete',
            'discoveryMode' => 'incomplete',
            'discoveryMessage' => $message,
        ]);
    }

    protected function buildErrorInstanceContext(array $account, string $message): array
    {
        return $this->buildBaseInstanceContext($account, [
            'discoveryStatus' => 'error',
            'discoveryMode' => 'instance',
            'discoveryMessage' => $message,
        ]);
    }

    protected function buildSuccessInstanceContext(array $account, array $values): array
    {
        return $this->buildBaseInstanceContext($account, array_merge([
            'discoveryStatus' => 'success',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
        ], $values));
    }

    protected function buildBaseInstanceContext(array $account, array $values): array
    {
        return array_merge([
            'instanceId' => $account['instance_id'] ?? '',
            'instanceName' => '',
            'instanceStatus' => 'Unknown',
            'publicIp' => '',
            'securityGroups' => [],
            'securityGroupCount' => 0,
            'securityGroupNames' => [],
            'discoveryStatus' => 'error',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
            'usingFallbackSecurityGroup' => false,
            'fallbackSecurityGroupId' => trim((string) ($account['security_group_id'] ?? '')),
            'trafficDataAvailable' => false,
            'trafficUsedGb' => null,
            'trafficError' => '',
        ], $values);
    }

    protected function collectSecurityGroupNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            $name = trim((string) ($group['security_group_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
