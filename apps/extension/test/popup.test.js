const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const popupSource = fs.readFileSync(path.join(root, 'popup.js'), 'utf8');
const popupHtml = fs.readFileSync(path.join(root, 'popup.html'), 'utf8');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));

class ElementStub {
  constructor(dataset = {}) {
    this.dataset = dataset;
    this.hidden = false;
    this.disabled = false;
    this.textContent = '';
    this.value = 0;
    this.attributes = {};
    this.listeners = {};
    this.classList = { toggle: (name, enabled) => { this.error = enabled && name === 'error'; } };
  }

  addEventListener(type, listener) { this.listeners[type] = listener; }
  setAttribute(name, value) { this.attributes[name] = value; }
  click() { return this.listeners.click?.(); }
}

function createPopup({ responses = {}, clipboardError = false } = {}) {
  const ids = [
    'message', 'member', 'copy', 'subscription-address', 'refresh', 'retry',
    'account-name', 'signed-out', 'usage', 'plan-name', 'usage-progress',
    'expiry', 'sync-state', 'account-notice',
  ];
  const elements = Object.fromEntries(ids.map((id) => [id, new ElementStub()]));
  const formats = ['auto', 'clash', 'mihomo'].map((format, index) => {
    const button = new ElementStub({ format });
    button.setAttribute('aria-pressed', String(index === 0));
    return button;
  });
  const pages = ['plans', 'login', 'qr', 'clients', 'compatibility'].map((page) => new ElementStub({ page }));
  const listeners = { window: {}, document: {} };
  const requests = [];
  const opened = [];
  const copied = [];
  let activeFetches = 0;

  const document = {
    visibilityState: 'visible',
    getElementById: (id) => elements[id],
    querySelectorAll: (selector) => selector === '[data-format]' ? formats : selector === '[data-page]' ? pages : [],
    addEventListener: (type, listener) => { listeners.document[type] = listener; },
  };
  const fetch = async (url, options) => {
    requests.push({ url: String(url), options });
    activeFetches += 1;
    try {
      const route = new URL(url).pathname;
      const result = responses[route];
      if (result instanceof Error) throw result;
      if (result?.httpError) return { ok: false, status: result.httpError };
      return { ok: true, json: async () => ({ data: result ?? null }) };
    } finally {
      activeFetches -= 1;
    }
  };
  const context = {
    URL,
    Date,
    AbortSignal,
    fetch,
    document,
    window: {
      open: (...args) => opened.push(args),
      addEventListener: (type, listener) => { listeners.window[type] = listener; },
    },
    navigator: {
      clipboard: {
        writeText: async (value) => {
          if (clipboardError) throw new Error('clipboard denied');
          copied.push(value);
        },
      },
    },
    chrome: { tabs: { create: (tab) => opened.push([tab.url]) } },
  };
  vm.runInNewContext(popupSource, context, { filename: 'popup.js' });

  return {
    elements, formats, pages, listeners, requests, opened, copied,
    async settled() {
      for (let i = 0; i < 20 && activeFetches; i += 1) await new Promise(setImmediate);
      await new Promise(setImmediate);
    },
  };
}

const memberResponses = (subscription, entitlement = {
  data_used_gb: 3, traffic_gb: 20, plan_name: '标准套餐', ends_at: '2027-01-01T00:00:00Z',
}) => ({
  '/api/v1/auth/session': { id: 7, role: 'member', name: '测试会员' },
  '/api/v1/me/overview': { user: { id: 7, role: 'member' }, entitlement },
  '/api/v1/me/subscriptions': subscription ? [subscription] : [],
});

test('未登录与非会员会话显示登录或切换账户提示', async (t) => {
  await t.test('未登录', async () => {
    const popup = createPopup();
    await popup.settled();
    assert.equal(popup.elements['signed-out'].hidden, false);
    assert.equal(popup.elements['account-name'].textContent, '尚未登录');
    assert.match(popup.elements.message.textContent, /登录后返回扩展/);
    assert.equal(popup.elements.member.hidden, true);
  });

  await t.test('管理员会话', async () => {
    const popup = createPopup({ responses: { '/api/v1/auth/session': { id: 1, role: 'admin' } } });
    await popup.settled();
    assert.equal(popup.elements['account-name'].textContent, '请切换会员账户');
    assert.match(popup.elements.message.textContent, /管理员会话/);
    assert.equal(popup.elements.member.hidden, true);
  });
});

test('有效会员显示套餐、用量和到期日，默认格式为自动识别且不会自动连接', async () => {
  const popup = createPopup({ responses: memberResponses({
    status: 'active', url: '/subscribe/token', expires_at: '2099-01-01T00:00:00Z',
  }) });
  await popup.settled();

  assert.equal(popup.elements.member.hidden, false);
  assert.equal(popup.elements['signed-out'].hidden, true);
  assert.equal(popup.elements['account-name'].textContent, '测试会员');
  assert.equal(popup.elements.usage.textContent, '3 / 20 GB');
  assert.equal(popup.elements['plan-name'].textContent, '标准套餐');
  assert.equal(popup.elements['usage-progress'].value, 15);
  assert.equal(popup.elements.copy.disabled, false);
  assert.equal(popup.formats[0].attributes['aria-pressed'], 'true');
  assert.equal(popup.formats[1].attributes['aria-pressed'], 'false');
  assert.equal(popup.requests.every(({ url }) => new URL(url).origin === 'http://127.0.0.1:5174'), true);
  assert.equal(popup.requests.every(({ options }) => options.credentials === 'include'), true);
  assert.equal(popup.elements['sync-state'].textContent.includes('已连接'), false);
  assert.equal(popupHtml.includes('自动连接'), false);
});

test('匿名账户明确显示身份与跨设备限制，但保留已有权益和有效订阅', async () => {
  const responses = memberResponses({
    status: 'active', url: '/subscribe/anonymous-token', expires_at: '2099-01-01T00:00:00Z',
  });
  responses['/api/v1/auth/session'] = {
    id: 7, role: 'member', account_type: 'anonymous', name: '匿名会员', email: null,
  };

  const popup = createPopup({ responses });
  await popup.settled();

  assert.equal(popup.elements['account-name'].textContent, '匿名账户');
  assert.equal(popup.elements['account-notice'].hidden, false);
  assert.match(popup.elements['account-notice'].textContent, /暂不支持跨设备迁移/);
  assert.equal(popup.elements.usage.textContent, '3 / 20 GB');
  assert.equal(popup.elements.copy.disabled, false);
  assert.equal(popup.elements['subscription-address'].textContent, '127.0.0.1 / subscribe / ••••••••');
  assert.match(popup.elements.message.textContent, /VIP 续费完成后返回这里/);
});

test('已过期或撤权的凭证不可复制，并提示前往订阅页准备凭证', async (t) => {
  const cases = [
    ['过期', { status: 'active', url: '/subscribe/expired', expires_at: '2000-01-01T00:00:00Z' }],
    ['撤权', { status: 'revoked', url: '/subscribe/revoked', expires_at: null }],
  ];
  for (const [label, subscription] of cases) {
    await t.test(label, async () => {
      const popup = createPopup({ responses: memberResponses(subscription) });
      await popup.settled();
      assert.equal(popup.elements.copy.disabled, true);
      assert.equal(popup.elements['subscription-address'].textContent, '前往订阅页准备固定凭证');
      assert.match(popup.elements.message.textContent, /VIP 续费完成后返回这里/);
      assert.doesNotMatch(popup.elements.message.textContent, /已断流|已断开|已停止连接/);
    });
  }
});

test('无有效套餐时明确提示开通或续费，且凭证保持不可用', async () => {
  const popup = createPopup({ responses: memberResponses(null, null) });
  await popup.settled();
  assert.equal(popup.elements.usage.textContent, '暂无有效套餐');
  assert.equal(popup.elements['plan-name'].textContent, '可提前准备订阅');
  assert.equal(popup.elements.copy.disabled, true);
  assert.match(popup.elements.message.textContent, /会员生效后方可获取配置/);
});

test('选择格式更新状态，复制与第三方客户端扫码入口使用固定订阅地址', async () => {
  const popup = createPopup({ responses: memberResponses({ status: 'active', url: '/subscribe/token' }) });
  await popup.settled();
  await popup.formats[1].click();
  assert.equal(popup.formats[1].attributes['aria-pressed'], 'true');
  assert.equal(popup.formats[0].attributes['aria-pressed'], 'false');
  await popup.elements.copy.click();
  assert.equal(popup.copied[0], 'http://127.0.0.1:5174/subscribe/token?format=clash&mode=rule');

  assert.match(popupHtml, /扫码导入客户端/);
  assert.doesNotMatch(popupHtml, /扫码登录|扫描二维码登录/);
  await popup.pages.find((button) => button.dataset.page === 'qr').click();
  const qrUrl = new URL(popup.opened[0][0]);
  assert.equal(qrUrl.pathname, '/client-preview.html');
  assert.equal(qrUrl.searchParams.get('view'), 'subscription');
  assert.equal(qrUrl.searchParams.get('qr'), '1');
  assert.equal(qrUrl.searchParams.get('format'), 'clash');
  assert.equal(qrUrl.searchParams.get('source'), 'extension');
});

test('接口失效展示重试与错误，不声称连接已断开', async () => {
  const popup = createPopup({ responses: { '/api/v1/auth/session': { httpError: 401 } } });
  await popup.settled();
  assert.equal(popup.elements.retry.hidden, false);
  assert.equal(popup.elements.copy.disabled, true);
  assert.match(popup.elements.message.textContent, /登录已失效/);
  assert.equal(popup.elements.message.error, true);
  assert.doesNotMatch(popup.elements.message.textContent, /已断流|已断开|已停止连接/);
});

test('清单权限只允许本机会员服务，扩展未声明 VPN 数据通道权限', () => {
  assert.deepEqual(manifest.host_permissions, ['http://127.0.0.1:5174/*']);
  assert.equal(manifest.permissions, undefined);
  assert.match(popupHtml, /未接入代理连接/);
});
