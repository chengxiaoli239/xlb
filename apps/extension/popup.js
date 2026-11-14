const accountOrigin = 'http://127.0.0.1:5174';
let subscription = null;
let selectedFormat = 'auto';
let loading = false;
const element = (id) => document.getElementById(id);

function message(text, error = false) {
  element('message').textContent = text;
  element('message').classList.toggle('error', error);
}

async function api(path) {
  const response = await fetch(new URL(path, accountOrigin), {
    credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' },
    signal: AbortSignal.timeout(10000),
  });
  if (!response.ok) throw new Error(response.status === 401 ? '登录已失效，请重新登录会员中心。' : '本地会员服务暂不可用，请稍后重试。');
  return (await response.json()).data;
}

function selectedUrl() {
  if (!subscription?.url) return null;
  const url = new URL(subscription.url, accountOrigin);
  if (url.origin !== accountOrigin || !url.pathname.startsWith('/subscribe/')) return null;
  if (selectedFormat !== 'auto') url.searchParams.set('format', selectedFormat);
  url.searchParams.set('mode', 'rule');
  return url.toString();
}

function clearMember() {
  subscription = null;
  element('member').hidden = true;
  element('copy').disabled = true;
  element('subscription-address').textContent = '尚未准备凭证';
}

async function syncMember() {
  if (loading) return;
  loading = true;
  element('refresh').disabled = true;
  element('retry').hidden = true;
  clearMember();
  message('正在同步会员权益…');
  try {
    const current = await api('/api/v1/auth/session');
    if (!current || current.role !== 'member') {
      element('account-name').textContent = current ? '请切换会员账户' : '尚未登录';
      element('signed-out').hidden = false;
      message(current ? '当前是管理员会话，请在会员中心切换账号。' : '登录后返回扩展，即可同步会员权益。');
      return;
    }
    const [overview, subscriptions] = await Promise.all([api('/api/v1/me/overview'), api('/api/v1/me/subscriptions')]);
    if (overview.user.role !== 'member' || overview.user.id !== current.id) throw new Error('登录会话已切换，请重新同步。');
    subscription = subscriptions.find((item) => item.status === 'active' && item.url && (!item.expires_at || Date.parse(item.expires_at) > Date.now())) ?? null;
    element('account-name').textContent = current.name;
    const entitlement = overview.entitlement;
    element('usage').textContent = entitlement ? `${entitlement.data_used_gb} / ${entitlement.traffic_gb} GB` : '暂无有效套餐';
    element('plan-name').textContent = entitlement?.plan_name ?? '可提前准备订阅';
    element('usage-progress').value = entitlement?.traffic_gb > 0 ? Math.min(100, entitlement.data_used_gb / entitlement.traffic_gb * 100) : 0;
    element('expiry').textContent = entitlement ? `有效期至 ${new Date(entitlement.ends_at).toLocaleDateString('zh-CN')}` : '开通或续费后刷新同步到期时间';
    element('subscription-address').textContent = subscription ? '127.0.0.1 / subscribe / ••••••••' : '前往订阅页准备固定凭证';
    element('copy').disabled = !selectedUrl();
    element('signed-out').hidden = true;
    element('member').hidden = false;
    element('sync-state').textContent = `同步于 ${new Date().toLocaleTimeString('zh-CN')} · 本地开发版`;
    message(entitlement ? 'VIP 续费完成后返回这里，会自动同步权益。' : '固定凭证与套餐独立，会员生效后方可获取配置。');
  } catch (reason) {
    clearMember();
    element('account-name').textContent = '同步失败';
    element('signed-out').hidden = false;
    element('retry').hidden = false;
    message(reason instanceof Error ? reason.message : '同步失败，请重试。', true);
  } finally {
    loading = false;
    element('refresh').disabled = false;
  }
}

document.querySelectorAll('[data-format]').forEach((button) => {
  button.addEventListener('click', () => {
    selectedFormat = button.dataset.format;
    document.querySelectorAll('[data-format]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    message(`已选择 ${selectedFormat === 'auto' ? '自动识别' : selectedFormat}，复制与二维码会使用该格式。`);
  });
});

element('copy').addEventListener('click', async () => {
  const url = selectedUrl();
  if (!url) return;
  try { await navigator.clipboard.writeText(url); message('订阅链接已复制，请在兼容客户端导入。'); }
  catch { message('剪贴板不可用，请在会员订阅页手动复制。', true); }
});

document.querySelectorAll('[data-page]').forEach((button) => {
  button.addEventListener('click', () => {
    const page = button.dataset.page;
    const isClientPage = ['qr', 'compatibility', 'login'].includes(page);
    const url = new URL(isClientPage ? '/client-preview.html' : '/account', accountOrigin);
    url.searchParams.set('view', page === 'qr' ? 'subscription' : page === 'login' ? 'home' : page);
    url.searchParams.set('source', 'extension');
    if (page === 'qr') { url.searchParams.set('qr', '1'); url.searchParams.set('format', selectedFormat); }
    if (globalThis.chrome?.tabs?.create) chrome.tabs.create({ url: url.toString() });
    else window.open(url.toString(), '_blank', 'noopener,noreferrer');
  });
});

element('refresh').addEventListener('click', () => void syncMember());
element('retry').addEventListener('click', () => void syncMember());
window.addEventListener('focus', () => void syncMember());
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') void syncMember(); });
void syncMember();
