import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AccountSnapshot, NativeBridge } from './native';

const member: AccountSnapshot = {
  user: { id: 'member-1', email: 'member@example.test', name: '沙箱会员', role: 'member', account_type: 'full' },
  entitlement: { status: 'active', ends_at: '2027-12-31T00:00:00Z', data_used_gb: 12.5, plan_name: '年度会员', period_label: '年度', traffic_gb: 1500, max_devices: 5 },
  activeSubscriptions: 1,
  nodeAuthorization: 'unavailable',
  devices: {
    entitlement_active: true,
    max_devices: 5,
    registered_count: 2,
    available_slots: 3,
    devices: [{ id: 'device-1', installation_id: 'install-1', name: 'Windows Desktop', platform: 'windows', status: 'registered', created_at: '2026-11-22T00:00:00Z' }],
  },
};

async function mount(bridge: NativeBridge) {
  Object.assign(globalThis, { IS_REACT_ACT_ENVIRONMENT: true });
  vi.resetModules();
  window.__XLB_NATIVE_TEST__ = bridge;
  const { default: App } = await import('./App');
  const host = document.createElement('div');
  document.body.append(host);
  const root = createRoot(host);
  await act(async () => { root.render(createElement(App)); });
  await act(async () => { await Promise.resolve(); });
  return { host, root };
}

async function setInput(host: HTMLElement, selector: string, value: string) {
  const input = host.querySelector<HTMLInputElement>(selector)!;
  await act(async () => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
  });
}

afterEach(() => {
  document.body.innerHTML = '';
  delete window.__XLB_NATIVE_TEST__;
  vi.restoreAllMocks();
});

describe('Windows account shell', () => {
  it('restores membership and device state without claiming a VPN connection', async () => {
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue(member),
      login: vi.fn(),
      logout: vi.fn(),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    expect(host.textContent).toContain('年度会员');
    expect(host.textContent).toContain('2 / 5 台');
    expect(host.textContent).toContain('服务线路暂不可用');
    expect(host.textContent).toContain('节点授权：暂不可用');
    expect(host.textContent).toContain('这不代表 VPN 已连接');
    expect(host.textContent).toContain('连接功能尚未接入');
    expect(host.querySelector('button[aria-disabled="true"]')).not.toBeNull();
    await act(async () => root.unmount());
  });

  it('labels ready as node authorization without enabling connection', async () => {
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue({ ...member, nodeAuthorization: 'ready' }),
      login: vi.fn(),
      logout: vi.fn(),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    expect(host.textContent).toContain('节点授权：节点授权已就绪');
    expect(host.textContent).toContain('这不代表 VPN 已连接');
    expect(host.querySelector('button[aria-disabled="true"]')).not.toBeNull();
    await act(async () => root.unmount());
  });

  it('shows API errors and does not invent a member session', async () => {
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue(null),
      login: vi.fn().mockRejectedValue(new Error('邮箱或密码不正确')),
      logout: vi.fn(),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    await setInput(host, '#email', 'member@example.test');
    await setInput(host, '#password', 'wrong');
    const form = host.querySelector('form')!;
    await act(async () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    expect(host.textContent).toContain('邮箱或密码不正确');
    expect(host.textContent).toContain('登录会员账户');
    await act(async () => root.unmount());
  });

  it('submits credentials to native code and renders the returned member snapshot', async () => {
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue(null),
      login: vi.fn().mockResolvedValue(member),
      logout: vi.fn(),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    await setInput(host, '#email', 'member@example.test');
    await setInput(host, '#password', 'local-test-password');
    const form = host.querySelector('form')!;
    await act(async () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    expect(bridge.login).toHaveBeenCalledWith('member@example.test', 'local-test-password');
    expect(host.textContent).toContain('年度会员');
    expect(host.textContent).toContain('服务线路暂不可用');
    await act(async () => root.unmount());
  });

  it('clears the view after a successful logout', async () => {
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue(member),
      login: vi.fn(),
      logout: vi.fn().mockResolvedValue(undefined),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    await act(async () => host.querySelector<HTMLButtonElement>('[aria-label="退出账户"]')!.click());
    expect(bridge.logout).toHaveBeenCalledOnce();
    expect(host.textContent).toContain('登录会员账户');
    await act(async () => root.unmount());
  });

  it('does not imply email recovery for an anonymous account', async () => {
    const anonymous: AccountSnapshot = {
      ...member,
      user: { ...member.user, email: null, account_type: 'anonymous' },
    };
    const bridge: NativeBridge = {
      restore: vi.fn().mockResolvedValue(anonymous),
      login: vi.fn(),
      logout: vi.fn(),
      localTestCredentials: vi.fn().mockResolvedValue(null),
    };
    const { host, root } = await mount(bridge);
    expect(host.querySelector('.account-copy small')?.textContent).toBe('匿名账号 · 未绑定邮箱');
    await act(async () => root.unmount());
  });
});
