import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './platform';
import { fireEvent } from './test/dom-events';
import { cleanup, render, settle } from './test/test-utils';

const member = {
  id: 'member-local-test',
  name: '本地测试会员',
  email: 'member@xlb.local',
  role: 'member',
};

const plan = {
  id: 'plan-annual-test',
  name: '年度会员',
  period_label: '年度',
  duration_days: 365,
  price_cents: 2980,
  traffic_gb: 1500,
  max_devices: 5,
};

const order = {
  id: 'order-sandbox-test',
  plan_name: plan.name,
  total_cents: plan.price_cents,
  status: 'pending_test',
  created_at: '2026-11-24T00:00:00Z',
  expires_at: '2026-11-25T00:00:00Z',
  user_name: member.name,
};

function orderDetail(paid: boolean) {
  return {
    ...order,
    status: paid ? 'paid_test' : 'pending_test',
    plan_snapshot: { ...plan, version: 1 },
    user_email: member.email,
    is_test: true,
    payment: paid ? {
      id: 'payment-event-test',
      transaction_id: 'sandbox-transaction-test',
      amount_cents: plan.price_cents,
      currency: 'CNY',
      status: 'accepted_test',
      review_reason: null,
      created_at: '2026-11-24T00:01:00Z',
    } : null,
    refund: null,
    node_revocations: {
      status: 'not_required',
      total_count: 0,
      pending_count: 0,
      retrying_count: 0,
      confirmed_count: 0,
    },
    entitlement_grant: paid ? {
      id: 'grant-test',
      entitlement_id: 'entitlement-test',
      status: 'granted_test',
      starts_at: '2026-11-24T00:01:00Z',
      ends_at: '2027-11-24T00:01:00Z',
    } : null,
    events: [{
      id: 'order-event-test',
      from_status: paid ? 'pending_test' : null,
      to_status: paid ? 'paid_test' : 'pending_test',
      created_at: '2026-11-24T00:00:00Z',
      actor_name: member.name,
    }],
  };
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  window.history.replaceState({}, '', '/');
});

describe('member sandbox purchase flow', () => {
  it('creates a test order and simulates payment without contacting a payment provider', async () => {
    vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true);
    window.history.replaceState({}, '', '/account?view=plans');
    let paid = false;
    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = String(input);
      if (path.endsWith('/api/v1/auth/session')) return json(member);
      if (path.endsWith('/api/v1/auth/csrf')) return json({ token: 'test-csrf-token' });
      if (path.endsWith('/api/v1/catalog/plans')) return json([plan]);
      if (path.endsWith('/api/v1/me/overview')) {
        return json({ user: member, entitlement: null, activeSubscriptions: 0, recentOrders: [] });
      }
      if (path.endsWith('/api/v1/me/orders') && init?.method === 'POST') {
        return json(order);
      }
      if (path.endsWith('/api/v1/me/orders/order-sandbox-test/sandbox-payment')) {
        paid = true;
        return json({ subscription: { url: '/subscribe/fixed-sandbox-subscription-token-123456' } });
      }
      if (path.endsWith('/api/v1/me/orders/order-sandbox-test')) return json(orderDetail(paid));
      if (path.includes('/api/v1/')) return json([]);
      return json(null);
    });
    vi.stubGlobal('fetch', fetchMock);

    const container = await render(<App />);
    await settle();

    expect(container.textContent).toContain('套餐权益');
    expect(container.textContent).toContain('不会产生真实扣款');
    await fireEvent(container.querySelector<HTMLButtonElement>('.plan-row .secondary-button')!, 'click');
    expect(container.textContent).toContain('创建测试订单');
    await fireEvent([...container.querySelectorAll('button')].find(button => button.textContent?.includes('创建测试订单'))!, 'click');
    await settle();

    expect(fetchMock.mock.calls.some(([input, init]) => String(input).endsWith('/api/v1/me/orders')
      && init?.method === 'POST')).toBe(true);
    expect(container.textContent).toContain('模拟沙箱付款');
    await fireEvent([...container.querySelectorAll('button')].find(button => button.textContent?.includes('模拟沙箱付款'))!, 'click');
    expect(container.textContent).toContain('确认模拟付款');
    await fireEvent([...container.querySelectorAll('button')].find(button => button.textContent?.includes('确认模拟付款'))!, 'click');
    await settle();

    expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/sandbox-payment'))).toBe(true);
    expect(container.textContent).toContain('本地测试付款已记录');
    expect(container.textContent).toContain('固定订阅链接');
    expect(container.textContent).toContain('本地测试权益');
    expect(fetchMock.mock.calls.some(([input]) => /payment|checkout|charge/i.test(String(input))
      && !String(input).includes('/sandbox-payment'))).toBe(false);
  });
});

function json(data: unknown) {
  return Promise.resolve(new Response(JSON.stringify({ data }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  }));
}
