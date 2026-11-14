<?php

namespace Tests\Support;

use App\Contracts\NodeProvider;
use Tests\TestCase;

abstract class NodeProviderContractTestCase extends TestCase
{
    abstract protected function provider(): NodeProvider;

    abstract protected function deployment(): object;

    public function test_resource_keys_are_stable_and_unique_for_deployments(): void
    {
        $provider = $this->provider();
        $firstKey = 'xlb-node-'.str_repeat('a', 32);
        $secondKey = 'xlb-node-'.str_repeat('b', 32);

        $this->assertSame($provider->resourceKey($firstKey), $provider->resourceKey($firstKey));
        $this->assertNotSame($provider->resourceKey($firstKey), $provider->resourceKey($secondKey));
    }

    public function test_lifecycle_results_follow_the_provider_neutral_contract(): void
    {
        $provider = $this->provider();
        $deployment = $this->deployment();

        $created = $provider->create($deployment);
        $this->assertContains($created['state'] ?? null, ['building', 'bootstrapping', 'ready']);
        $this->assertIsArray($created['resources'] ?? []);

        $inspected = $provider->inspect($deployment);
        $this->assertContains($inspected['state'] ?? null, ['building', 'bootstrapping', 'ready', 'deleting', 'failed', 'released']);
        $this->assertIsArray($inspected['resources'] ?? []);

        $probe = $provider->probe($deployment);
        $this->assertIsBool($probe['verified'] ?? null);
        $this->assertIsArray($probe['checks'] ?? []);
        if ($probe['verified']) {
            $this->assertIsString($probe['public_ip'] ?? null);
            $this->assertNotFalse(filter_var($probe['public_ip'], FILTER_VALIDATE_IP));
        }

        $released = $provider->release($deployment);
        $this->assertContains($released['state'] ?? null, ['deleting', 'released']);
        $this->assertIsArray($released['resources'] ?? []);

        $profile = $provider->profile($deployment);
        $this->assertIsString($profile['profile'] ?? null);
        $this->assertNotSame('', $profile['profile']);
    }
}
