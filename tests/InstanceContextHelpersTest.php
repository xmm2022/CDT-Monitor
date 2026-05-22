<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/InstanceContextHelpers.php';

class TestInstanceContextHelper
{
    use InstanceContextHelpers;

    public function status(string $status): string
    {
        return $this->normalizeInstanceStatus($status, [
            'RUNNING' => 'Running',
            'STOPPED' => 'Stopped',
            'PENDING' => 'Pending',
        ]);
    }

    public function incomplete(array $account, string $message): array
    {
        return $this->buildIncompleteInstanceContext($account, $message);
    }

    public function extra(array $account): array
    {
        return $this->decodeExtraConfig($account);
    }
}

$helper = new TestInstanceContextHelper();

assertSame('Running', $helper->status('RUNNING'), 'RUNNING should normalize');
assertSame('Stopped', $helper->status('stopped'), 'case-insensitive status should normalize');
assertSame('Unknown', $helper->status('unexpected'), 'unknown status should normalize to Unknown');

$context = $helper->incomplete(['instance_id' => 'i-1', 'security_group_id' => 'sg-1'], 'missing key');
assertSame('incomplete', $context['discoveryStatus'], 'incomplete status');
assertSame('i-1', $context['instanceId'], 'instance id should be preserved');
assertSame('sg-1', $context['fallbackSecurityGroupId'], 'fallback sg should be preserved');
assertSame(false, $context['trafficDataAvailable'], 'traffic should be unavailable');

$extra = $helper->extra(['extra_config' => '{"zone":"asia-east1-a"}']);
assertSame('asia-east1-a', $extra['zone'], 'extra_config should decode');

pass('InstanceContextHelpers');
