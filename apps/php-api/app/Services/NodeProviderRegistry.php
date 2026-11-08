<?php

namespace App\Services;

use App\Contracts\NodeProvider;
use RuntimeException;

class NodeProviderRegistry
{
    public function resolve(string $key): NodeProvider
    {
        if (! preg_match('/^[a-z0-9_-]{1,32}$/D', $key)) {
            throw new RuntimeException('节点服务商标识无效');
        }

        $class = config('node_automation.drivers.'.$key.'.class');
        if (! is_string($class) || ! is_a($class, NodeProvider::class, true)) {
            throw new RuntimeException('节点服务商驱动未配置：'.$key);
        }

        return app($class);
    }
}
