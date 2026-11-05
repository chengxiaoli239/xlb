import { createHash, randomBytes } from 'node:crypto';

export function hashToken(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}

export function createSubscriptionToken(): string {
  return randomBytes(32).toString('base64url');
}

export interface DemoNode {
  name: string;
  endpoint_host: string;
  endpoint_port: number;
}

export function buildDemoProfile(node: DemoNode): string {
  return [
    '# 迅连宝本地开发配置，不包含真实节点或可用凭证。',
    '# 仅用于验证 Mihomo 订阅格式和导入流程。',
    'mixed-port: 7890',
    'allow-lan: false',
    'mode: rule',
    'log-level: warning',
    'proxies:',
    '  - name: "' + node.name + '"',
    '    type: socks5',
    '    server: ' + node.endpoint_host,
    '    port: ' + node.endpoint_port,
    '    udp: false',
    'proxy-groups:',
    '  - name: 迅连宝测试',
    '    type: select',
    '    proxies: ["' + node.name + '", DIRECT]',
    'rules:',
    '  - MATCH,迅连宝测试',
    '',
  ].join('\n');
}
