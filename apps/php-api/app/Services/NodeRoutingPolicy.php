<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class NodeRoutingPolicy
{
    /**
     * @param  list<array{id:string,status:string,health_status:string,last_health_check_at:string,max_sessions:int,active_sessions:int,weight:int,latency_ms:int,packet_loss_pct:float,cpu_pct:float}>  $nodes
     * @param  list<string>  $acceptedHealthStatuses
     * @return list<NodeRoutingCandidateScore>
     */
    public function rankCandidates(
        array $nodes,
        string $affinityKey,
        DateTimeImmutable $now,
        array $acceptedHealthStatuses,
        int $healthTtlSeconds = 30,
        float $maxLoad = 0.7,
    ): array {
        if ($affinityKey === '' || $healthTtlSeconds < 1 || ! is_finite($maxLoad) || $maxLoad <= 0 || $maxLoad > 1) {
            throw new InvalidArgumentException('Routing policy inputs are invalid.');
        }

        $candidates = [];
        foreach ($nodes as $node) {
            $candidate = $this->scoreCandidate($node, $affinityKey, $now, $acceptedHealthStatuses, $healthTtlSeconds, $maxLoad);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }
        usort($candidates, static fn (NodeRoutingCandidateScore $left, NodeRoutingCandidateScore $right): int => [$left->score, $left->nodeId] <=> [$right->score, $right->nodeId]);

        return $candidates;
    }

    /**
     * @param  array{id:string,status:string,health_status:string,last_health_check_at:string,max_sessions:int,active_sessions:int,weight:int,latency_ms:int,packet_loss_pct:float,cpu_pct:float}  $node
     * @param  list<string>  $acceptedHealthStatuses
     */
    private function scoreCandidate(
        array $node,
        string $affinityKey,
        DateTimeImmutable $now,
        array $acceptedHealthStatuses,
        int $healthTtlSeconds,
        float $maxLoad,
    ): ?NodeRoutingCandidateScore {
        if ($node['id'] === '' || $node['status'] !== 'available'
            || ! in_array($node['health_status'], $acceptedHealthStatuses, true)
            || $node['max_sessions'] < 1 || $node['active_sessions'] < 0
            || $node['active_sessions'] >= $node['max_sessions']
            || $node['weight'] < 1 || $node['latency_ms'] < 0
            || ! is_finite($node['packet_loss_pct']) || ! is_finite($node['cpu_pct'])
            || $node['packet_loss_pct'] < 0 || $node['packet_loss_pct'] > 100
            || $node['cpu_pct'] < 0 || $node['cpu_pct'] > 100) {
            return null;
        }

        try {
            $lastHealthCheck = new DateTimeImmutable($node['last_health_check_at']);
        } catch (\Exception) {
            return null;
        }
        $healthAge = $now->getTimestamp() - $lastHealthCheck->getTimestamp();
        if ($healthAge < 0 || $healthAge > $healthTtlSeconds) {
            return null;
        }

        $load = $node['active_sessions'] / $node['max_sessions'];
        if ($load >= $maxLoad) {
            return null;
        }

        $latencyFactor = 1 / (1 + ($node['latency_ms'] / 100));
        $lossFactor = 1 - ($node['packet_loss_pct'] / 100);
        $cpuHeadroom = 1 - ($node['cpu_pct'] / 100);
        $effectiveWeight = $node['weight'] * $latencyFactor * $lossFactor * $cpuHeadroom * (1 - $load);
        if ($effectiveWeight <= 0) {
            return null;
        }

        $hash = hash('sha256', $affinityKey.'|'.$node['id']);
        $sample = hexdec(substr($hash, 0, 13));
        $uniform = ($sample + 1) / (4503599627370496 + 1);

        return new NodeRoutingCandidateScore($node['id'], $effectiveWeight, -log($uniform) / $effectiveWeight);
    }
}
