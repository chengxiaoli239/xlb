import { fireEvent } from './test/dom-events';
import { describe, expect, it, vi } from 'vitest';
import ClientPreview from './ClientPreview';
import { render, settle } from './test/test-utils';

function mockApi(session: unknown, overview?: unknown) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
    const path = String(input);
    const body = path.endsWith('/auth/session') ? session
      : path.endsWith('/me/overview') ? overview
        : path.endsWith('/me/subscriptions') || path.endsWith('/me/clients') || path.includes('/me/orders') || path.endsWith('/catalog/plans') ? []
          : null;
    return new Response(JSON.stringify({ data: body }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

describe('ClientPreview', () => {
  it('shows the three separate unavailable connection indicators with an offline member fixture', async () => {
    const user = { id: 'fixture-member', name: '本地测试会员', email: 'member@example.test', role: 'member' };
    mockApi(user, { user, entitlement: null, activeSubscriptions: 0, recentOrders: [] });
    const container = await render(<ClientPreview />);
    await settle();

    expect(container.querySelector('.client-app')).not.toBeNull();
    expect(container.querySelector('.connection-unavailable')?.textContent).toContain('VPN 内核待接入');
    expect(container.querySelector('.connection-state')?.textContent).toContain('连接能力待接入');
    expect(container.querySelector('.route-live-label')?.textContent).toContain('尚未开放');
  });

  it('toggles the authenticated client between light and dark themes and persists the choice', async () => {
    const user = { id: 'fixture-member', name: '本地测试会员', email: 'member@example.test', role: 'member' };
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
});
