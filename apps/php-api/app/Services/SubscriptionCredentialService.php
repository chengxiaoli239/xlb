<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionCredentialService
{
    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:?string,created:bool}
     */
    public function ensure(string $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $subscription = DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')
                ->orderByDesc('created_at')->lockForUpdate()->first();

            if ($subscription) {
                $result = $this->present($subscription);
                if ($result['url'] !== null) {
                    return [...$result, 'created' => false];
                }

                $secret = $this->newToken();
                $encrypted = Crypt::encryptString($secret);
                DB::table('subscriptions')->where('id', $subscription->id)->update([
                    'token_hash' => hash('sha256', $secret),
                    'token_encrypted' => $encrypted,
                ]);
                $this->audit($userId, 'subscription.credential_reissued', $subscription->id);

                return [...$this->present((object) [...(array) $subscription, 'token_encrypted' => $encrypted]), 'created' => false];
            }

            $id = (string) Str::uuid();
            $secret = $this->newToken();
            $createdAt = now()->toIso8601String();
            DB::table('subscriptions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'name' => 'Clash 订阅',
                'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret),
                'status' => 'active',
                'created_at' => $createdAt,
                'expires_at' => null,
            ]);
            $this->audit($userId, 'subscription.created', $id);

            return [
                'id' => $id,
                'name' => 'Clash 订阅',
                'status' => 'active',
                'created_at' => $createdAt,
                'expires_at' => null,
                'last_used_at' => null,
                'url' => '/subscribe/'.$secret,
                'created' => true,
            ];
        }, 3);
    }

    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:?string}|null
     */
    public function current(string $userId): ?array
    {
        $subscription = DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')
            ->orderByDesc('created_at')->first();

        return $subscription ? $this->present($subscription) : null;
    }

    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:?string}
     */
    public function present(object $subscription): array
    {
        $url = null;
        if ($subscription->status === 'active' && $subscription->token_encrypted) {
            try {
                $url = '/subscribe/'.Crypt::decryptString($subscription->token_encrypted);
            } catch (DecryptException) {
                $url = null;
            }
        }

        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'status' => $subscription->status,
            'created_at' => $subscription->created_at,
            'expires_at' => $subscription->expires_at,
            'last_used_at' => $subscription->last_used_at,
            'url' => $url,
        ];
    }

    /**
     * @return array{id:string,name:string,status:string,created_at:string,expires_at:?string,last_used_at:?string,url:string}|null
     */
    public function rotate(string $userId, string $subscriptionId): ?array
    {
        return DB::transaction(function () use ($userId, $subscriptionId): ?array {
            $subscription = DB::table('subscriptions')->where('id', $subscriptionId)->where('user_id', $userId)
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $subscription) {
                return null;
            }

            $secret = $this->newToken();
            DB::table('subscriptions')->where('id', $subscriptionId)->update([
                'token_hash' => hash('sha256', $secret),
                'token_encrypted' => Crypt::encryptString($secret),
            ]);
            $this->audit($userId, 'subscription.rotated', $subscriptionId);

            return [
                'id' => $subscriptionId,
                'name' => $subscription->name,
                'status' => 'active',
                'created_at' => $subscription->created_at,
                'expires_at' => $subscription->expires_at,
                'last_used_at' => $subscription->last_used_at,
                'url' => '/subscribe/'.$secret,
            ];
        }, 3);
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function audit(string $userId, string $action, string $subscriptionId): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $userId,
            'action' => $action,
            'target_type' => 'subscription',
            'target_id' => $subscriptionId,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
