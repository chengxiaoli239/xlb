import { fireEvent } from './test/dom-events';
import { describe, expect, it, vi } from 'vitest';
import ClientPreview from './ClientPreview';
import { render, settle } from './test/test-utils';

function mockApi(session: unknown, overview?: unknown, subscription?: unknown) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const path = String(input);
    const body = path.endsWith('/auth/csrf') ? { token: 'test-csrf-token' }
      : path.endsWith('/auth/session') ? session
      : path.endsWith('/me/overview') ? overview
        : path.endsWith('/me/subscriptions') ? (init?.method === 'POST' ? subscription : subscription ? [subscription] : [])
          : path.endsWith('/me/clients') || path.includes('/me/orders') || path.endsWith('/catalog/plans') ? []
          : null;
    return new Response(JSON.stringify({ data: body }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('ClientPreview', () => {
  it('shows the three separate unavailable connection indicators with an offline member fixture', async () => {
    const user = { id: 'fixture-member', name: '本地测试会员', email: 'member@example.test', role: 'member', account_type: 'full' };
    mockApi(user, { user, entitlement: null, activeSubscriptions: 0, recentOrders: [] });
    const container = await render(<ClientPreview />);
    await settle();

    expect(container.querySelector('.client-app')).not.toBeNull();
    expect(container.querySelector('.connection-unavailable')?.textContent).toContain('VPN 内核待接入');
    expect(container.querySelector('.connection-state')?.textContent).toContain('连接能力待接入');
    expect(container.querySelector('.route-live-label')?.textContent).toContain('尚未开放');
  });

  it('toggles the authenticated client between light and dark themes and persists the choice', async () => {
    const user = { id: 'fixture-member', name: '本地测试会员', email: 'member@example.test', role: 'member', account_type: 'full' };
    mockApi(user, { user, entitlement: null, activeSubscriptions: 0, recentOrders: [] });
    const container = await render(<ClientPreview />);
    await settle();

    const app = container.querySelector('.client-app');
    expect(app?.getAttribute('data-theme')).toBe('light');
    const toggle = container.querySelector<HTMLButtonElement>('.theme-toggle');
    expect(toggle?.getAttribute('aria-label')).toBe('切换深色主题');
    await fireEvent(toggle!, 'click');
    expect(app?.getAttribute('data-theme')).toBe('dark');
    expect(localStorage.getItem('xlb-client-theme')).toBe('dark');
    await fireEvent(toggle!, 'click');
    expect(app?.getAttribute('data-theme')).toBe('light');
  });

  it('keeps the login fields required and validates email format before submit', async () => {
    const fetchMock = mockApi(null);
    const container = await render(<ClientPreview />);
    await settle();

    const email = container.querySelector<HTMLInputElement>('#client-email')!;
    const password = container.querySelector<HTMLInputElement>('#client-password')!;
    const form = container.querySelector<HTMLFormElement>('form.client-login-form')!;
    expect(email.required).toBe(true);
    expect(email.type).toBe('email');
    expect(email.labels?.[0]?.textContent).toContain('邮箱地址');
    expect(password.required).toBe(true);
    expect(password.labels?.[0]?.textContent).toContain('登录密码');
    expect(form.checkValidity()).toBe(false);
    await fireEvent(email, 'input', { value: 'not-an-email' });
    expect(email.validity.typeMismatch).toBe(true);
    expect(fetchMock.mock.calls.every(([input]) => !String(input).endsWith('/auth/login'))).toBe(true);
  });

  it('labels anonymous accounts without exposing a null email or implying email-based cross-device recovery', async () => {
    const user = { id: 'fixture-anonymous', name: '匿名会员', email: null, role: 'member', account_type: 'anonymous' };
    mockApi(user, { user, entitlement: null, activeSubscriptions: 0, recentOrders: [] });
    const container = await render(<ClientPreview />);
    await settle();

    expect(container.querySelector('.client-profile-copy')?.textContent).toContain('匿名账号');
    expect(container.textContent).not.toContain('null');
    await fireEvent(container.querySelector<HTMLButtonElement>('.client-nav button:last-child')!, 'click');
    expect(container.querySelector('.account-profile-band')?.textContent).toContain('匿名账号');
    expect(container.querySelector('.account-profile-band')?.textContent).toContain('未绑定邮箱');
    expect(container.querySelector('.client-view-stack')?.textContent).toContain('不支持通过邮箱在其他设备恢复');
    expect(container.querySelector('.client-view-stack')?.textContent).not.toContain('同一个迅连宝账户，跨端');
  });

  it('continues to display paid entitlement for a full member account', async () => {
    const user = { id: 'fixture-member', name: '本地测试会员', email: 'member@example.test', role: 'member', account_type: 'full' };
    const subscription = { id: 'fixture-subscription', name: '固定订阅', status: 'active', created_at: '2026-01-01', expires_at: null, last_used_at: null, url: 'https://example.test/subscription' };
    mockApi(user, {
      user,
      entitlement: { status: 'active', starts_at: '2026-01-01', ends_at: '2027-01-01', data_used_gb: 12.5, plan_name: '年度会员', period_label: '年度', traffic_gb: 1500, max_devices: 5 },
      activeSubscriptions: 0,
      recentOrders: [],
    }, subscription);
    const container = await render(<ClientPreview />);
    await settle();

    expect(container.textContent).toContain('年度会员');
    expect(container.textContent).toContain('member@example.test');
  });
});
