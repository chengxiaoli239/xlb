import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity, ArrowDownToLine, ArrowRight, ArrowUpRight, BadgeCheck, Check,
  ChevronDown, CircleHelp, CloudDownload, CreditCard, ExternalLink,
  Globe2, KeyRound, LifeBuoy, Link2, LockKeyhole, LogOut, MapPin,
  Menu, Moon, RefreshCw, Route, Settings2, Shield, ShieldCheck,
  Smartphone, Sun, X,
} from 'lucide-react';
import './client-preview.css';
import CompatibilityPanel, { SubscriptionImport } from './SubscriptionTools';

type User = { id: string; name: string; email: string | null; role: 'admin' | 'member'; account_type: 'anonymous' | 'full' };
type Plan = { id: string; name: string; period_label: string; duration_days: number; price_cents: number; traffic_gb: number; max_devices: number };
type Overview = {
  user: User;
  entitlement: null | { status: string; starts_at: string; ends_at: string; data_used_gb: number; plan_name: string; period_label: string; traffic_gb: number; max_devices: number };
  activeSubscriptions: number;
  recentOrders: Array<{ id: string; plan_name: string; total_cents: number; status: string; created_at: string }>;
};
type Subscription = { id: string; name: string; status: string; created_at: string; expires_at: string | null; last_used_at: string | null; url: string | null };
type Client = { id: string; platform: string; name: string; version: string | null; description: string | null; download_url: string | null; qr_code_url: string | null; tutorial_url: string | null; compatibility_status: string };
type Order = { id: string; plan_name: string; total_cents: number; status: string; created_at: string; expires_at: string | null; user_name: string };
type View = 'home' | 'servers' | 'subscription' | 'compatibility' | 'plans' | 'account';
type Envelope<T> = { data: T; meta?: { total: number; page: number; per_page: number; total_pages: number } };

async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const method = init?.method?.toUpperCase() ?? 'GET';
  const unsafe = !['GET', 'HEAD', 'OPTIONS'].includes(method);
  const csrf = unsafe
    ? await fetch('/api/v1/auth/csrf', { credentials: 'same-origin' }).then(async response => {
      if (!response.ok) throw new Error('无法建立安全会话，请刷新后重试');
      return (await response.json() as Envelope<{ token: string }>).data.token;
    })
    : null;
  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers: {
      ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
      ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      ...init?.headers,
    },
  });
  if (!response.ok) {
    const body = await response.json().catch(() => null) as { error?: { message?: string } } | null;
    throw Object.assign(new Error(body?.error?.message ?? '请求失败，请稍后重试'), { status: response.status });
  }
  if (response.status === 204) return undefined as T;
  return (await response.json() as Envelope<T>).data;
}

const money = (cents: number) => `¥${(cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)}`;
const dateText = (value?: string | null) => value
  ? new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(value))
  : '尚未生成';
const shortDate = (value?: string | null) => value
  ? new Intl.DateTimeFormat('zh-CN', { month: 'short', day: 'numeric' }).format(new Date(value))
  : '—';
const percentage = (used: number, total: number) => total > 0 ? Math.min(100, Math.max(0, used / total * 100)) : 0;

const navItems: Array<{ id: View; label: string; icon: typeof Shield }> = [
  { id: 'home', label: '连接中心', icon: ShieldCheck },
  { id: 'servers', label: '线路选择', icon: Globe2 },
  { id: 'subscription', label: '订阅与设备', icon: Link2 },
  { id: 'compatibility', label: '兼容与连接', icon: Route },
  { id: 'plans', label: '套餐权益', icon: CreditCard },
  { id: 'account', label: '账户设置', icon: Settings2 },
];

export default function ClientPreview() {
  const [view, setView] = useState<View>(() => {
    const query = new URLSearchParams(window.location.search).get('view');
    return navItems.some(item => item.id === query) ? query as View : 'home';
  });
  const loadEpoch = useRef(0);
  const refreshLock = useRef(false);
  const [refreshing, setRefreshing] = useState(false);
  const [syncedAt, setSyncedAt] = useState<string | null>(null);
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    try { return localStorage.getItem('xlb-client-theme') === 'dark' ? 'dark' : 'light'; }
    catch { return 'light'; }
  });
  const [user, setUser] = useState<User | null>(null);
  const [overview, setOverview] = useState<Overview | null>(null);
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [clients, setClients] = useState<Client[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [toast, setToast] = useState('');
  const [mobileMenu, setMobileMenu] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [dialog, setDialog] = useState<'connect' | 'subscription' | 'rotate' | null>(null);
  const [showQr, setShowQr] = useState(() => new URLSearchParams(window.location.search).get('qr') === '1');

  const notify = useCallback((message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 2800);
  }, []);

  const loadMemberData = useCallback(async () => {
    const epoch = ++loadEpoch.current;
    const [overviewResult, subscriptionsResult, clientsResult, ordersResult] = await Promise.allSettled([
      api<Overview>('/api/v1/me/overview'),
      api<Subscription[]>('/api/v1/me/subscriptions'),
      api<Client[]>('/api/v1/me/clients'),
      api<Order[]>('/api/v1/me/orders?per_page=4'),
    ]);
    if (epoch !== loadEpoch.current) return;
    if (overviewResult.status === 'rejected' || subscriptionsResult.status === 'rejected') {
      setOverview(null);
      setSubscriptions([]);
      const failed = [overviewResult, subscriptionsResult].find(result => result.status === 'rejected');
      if (failed?.status === 'rejected' && failed.reason?.status === 401) setUser(null);
      throw new Error('会员或订阅同步失败，请重新登录或稍后刷新。');
    }
    if (overviewResult.value.user.role !== 'member') {
      setUser(null);
      setOverview(null);
      setSubscriptions([]);
      throw new Error('当前会话已切换，请使用会员账户重新登录。');
    }
    let nextSubscriptions = subscriptionsResult.value;
    if (overviewResult.value.entitlement && nextSubscriptions.length === 0) {
      const prepared = await api<Subscription>('/api/v1/me/subscriptions', { method: 'POST' });
      if (epoch !== loadEpoch.current) return;
      nextSubscriptions = [prepared, ...nextSubscriptions];
    }
    setUser(overviewResult.value.user);
    setOverview(overviewResult.value);
    setSubscriptions(nextSubscriptions);
    setClients(clientsResult.status === 'fulfilled' ? clientsResult.value : []);
    setOrders(ordersResult.status === 'fulfilled' ? ordersResult.value : []);
    setSyncedAt(new Date().toLocaleTimeString('zh-CN'));
    setError('');
  }, []);

  useEffect(() => {
    document.title = '迅连宝 VPN · 客户端';
    api<User | null>('/api/v1/auth/session')
      .then(async current => {
        if (current?.role === 'member') {
          setUser(current);
          await loadMemberData();
        } else if (current?.role === 'admin') {
          setError('当前登录的是管理员账户。切换到会员账户后，才能查看会员权益与订阅。');
        }
      })
      .catch(() => setError('会员服务暂时不可用，请检查本地 PHP API 是否已启动。'))
      .finally(() => setLoading(false));
    api<Plan[]>('/api/v1/catalog/plans').then(setPlans).catch(() => undefined);
  }, [loadMemberData]);

  useEffect(() => {
    try { localStorage.setItem('xlb-client-theme', theme); }
    catch { /* The selected theme still works for this session. */ }
  }, [theme]);

  const refreshMember = useCallback(async () => {
    if (refreshLock.current) return;
    refreshLock.current = true;
    setRefreshing(true);
    try { await loadMemberData(); }
    catch (reason) { setError((reason as Error).message); }
    finally { refreshLock.current = false; setRefreshing(false); }
  }, [loadMemberData]);

  useEffect(() => {
    if (!user || busy) return;
    const refresh = () => { if (document.visibilityState === 'visible') void refreshMember(); };
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);
    return () => {
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [user, busy, refreshMember]);

  const activeSubscription = subscriptions.find(subscription => subscription.status === 'active' && subscription.url && (!subscription.expires_at || Date.parse(subscription.expires_at) > Date.now())) ?? null;
  const entitlement = overview?.entitlement ?? null;
  const activeClients = useMemo(() => clients.filter(client => client.compatibility_status === 'verified'), [clients]);

  const signIn = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const next = await api<User>('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email, password }) });
      if (next.role !== 'member') {
        await api('/api/v1/auth/logout', { method: 'POST' });
        throw new Error('请使用会员账户登录客户端');
      }
      setUser(next);
      await loadMemberData();
      notify('已连接会员账户');
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const logout = async () => {
    setBusy(true);
    try {
      await api('/api/v1/auth/logout', { method: 'POST' });
      loadEpoch.current++;
      setUser(null);
      setOverview(null);
      setSubscriptions([]);
      setOrders([]);
      setError('');
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const createSubscription = async () => {
    setBusy(true);
    setError('');
    try {
      const subscription = await api<Subscription>('/api/v1/me/subscriptions', { method: 'POST' });
      setSubscriptions(current => [subscription, ...current.filter(row => row.id !== subscription.id)]);
      setShowQr(true);
      setDialog(null);
      notify('固定订阅已准备好，续费后继续使用');
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const rotateSubscription = async () => {
    if (!activeSubscription) return;
    setBusy(true);
    try {
      const next = await api<Subscription>(`/api/v1/me/subscriptions/${activeSubscription.id}/rotate`, { method: 'POST' });
      setSubscriptions(current => current.map(row => row.id === next.id ? next : row));
      setShowQr(true);
      setDialog(null);
      notify('订阅链接已轮换，旧链接立即失效');
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (loading) return <div className="client-loading"><span className="client-loader" /><span>正在连接会员账户…</span></div>;
  if (!user) return <LoginView email={email} setEmail={setEmail} password={password} setPassword={setPassword} error={error} busy={busy} onSubmit={signIn} />;

  const currentLabel = navItems.find(item => item.id === view)?.label ?? '连接中心';

  return <div className="client-app" data-theme={theme}>
    <aside className={'client-sidebar' + (mobileMenu ? ' is-open' : '')}>
      <a href="/client-preview.html" className="client-brand" aria-label="迅连宝客户端首页" onClick={event => { event.preventDefault(); setView('home'); setMobileMenu(false); }}>
        <span className="client-brand-mark"><ShieldCheck size={27} strokeWidth={2.3} /></span>
        <span className="client-brand-copy"><b>迅连宝</b><small>SECURE NETWORK</small></span>
        <button className="client-icon-button close-sidebar" type="button" aria-label="关闭导航" onClick={event => { event.preventDefault(); setMobileMenu(false); }}><X size={20} /></button>
      </a>
      <div className="client-sidebar-kicker">个人工作区</div>
      <nav className="client-nav" aria-label="客户端导航">
        {navItems.map(item => <button key={item.id} type="button" className={view === item.id ? 'active' : ''} aria-current={view === item.id ? 'page' : undefined} onClick={() => { setView(item.id); setMobileMenu(false); }}><item.icon size={20} strokeWidth={1.9} /><span>{item.label}</span>{item.id === 'subscription' && subscriptions.length > 0 && <small>{subscriptions.length}</small>}</button>)}
      </nav>
      <div className="client-sidebar-bottom">
        <div className="client-safety"><span className="safety-icon"><Shield size={18} /></span><div><b>连接保护</b><small>客户端内核尚未接入</small></div><span className="status-dot pending" /></div>
        <button type="button" className="client-side-link" onClick={() => { window.location.href = '/account?view=support'; }}><LifeBuoy size={17} />帮助与支持<ArrowUpRight size={15} /></button>
        <div className="client-profile"><span className="client-avatar">{user.name.slice(0, 1)}</span><span className="client-profile-copy"><b>{user.name}</b><small>{user.account_type === 'anonymous' ? '匿名账号 · 未绑定邮箱' : user.email ?? '未绑定邮箱'}</small></span><button type="button" className="client-icon-button" title="退出账户" aria-label="退出账户" onClick={() => void logout()}><LogOut size={17} /></button></div>
      </div>
    </aside>

    {mobileMenu && <button type="button" className="client-mobile-scrim" aria-label="关闭导航" onClick={() => setMobileMenu(false)} />}
    <div className="client-workspace">
      <header className="client-topbar">
        <div className="client-topbar-leading"><button type="button" className="client-icon-button mobile-menu-toggle" aria-label="打开导航" onClick={() => setMobileMenu(true)}><Menu size={21} /></button><div className="client-breadcrumb"><span>迅连宝</span><span className="breadcrumb-divider">/</span><strong>{currentLabel}</strong></div></div>
        <div className="client-topbar-actions"><span className="client-env-label"><i />本地预览</span><button type="button" className="client-icon-button theme-toggle" aria-label={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} title={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} onClick={() => setTheme(current => current === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? <Sun size={19} /> : <Moon size={19} />}</button><span className="topbar-account">{user.name}<ChevronDown size={15} /></span></div>
      </header>

      <main className="client-main">
        <div className="client-demo-banner"><span className="banner-mark"><Activity size={16} /></span><span><b>本地开发预览</b><span>会员数据来自本地 PHP 后台；连接内核待接入。{syncedAt && ` 同步于 ${syncedAt}`}</span></span><button type="button" onClick={() => void refreshMember()} disabled={refreshing} title="刷新会员数据" aria-label="刷新会员数据"><RefreshCw size={16} /></button></div>
        {error && <div className="client-inline-error" role="alert"><CircleHelp size={18} /><span>{error}</span><button type="button" aria-label="关闭提示" onClick={() => setError('')}><X size={17} /></button></div>}

        {view === 'home' && <HomeView user={user} overview={overview} subscription={activeSubscription} onConnect={() => setDialog('connect')} onServers={() => setView('servers')} onSubscription={() => setView('subscription')} onPlans={() => setView('plans')} orders={orders} />}
        {view === 'servers' && <ServersView onPlans={() => setView('plans')} onSubscription={() => setView('subscription')} />}
        {view === 'subscription' && <SubscriptionView entitlement={entitlement} subscription={activeSubscription} loading={busy} showQr={showQr} clients={clients} onCreate={() => void createSubscription()} onRotate={() => setDialog('rotate')} onPlans={() => setView('plans')} notify={notify} />}
        {view === 'compatibility' && <CompatibilityPanel subscription={activeSubscription} entitlement={entitlement} notify={notify} onSubscription={() => setView('subscription')} />}
        {view === 'plans' && <PlansView plans={plans} onOpenMemberCenter={() => { window.location.href = '/account?view=plans'; }} />}
        {view === 'account' && <AccountView user={user} overview={overview} clients={clients} verifiedClients={activeClients} onOrders={() => { window.location.href = '/account?view=orders'; }} onSupport={() => { window.location.href = '/account?view=support'; }} onLogout={() => void logout()} />}
      </main>
      <footer className="client-footer"><span>迅连宝 VPN</span><span>账号、套餐与订阅使用同一会员后台</span><span>桌面端 · 移动端</span></footer>
    </div>

    <nav className="client-bottom-nav" aria-label="移动端导航">
      {navItems.filter(item => ['home', 'servers', 'subscription', 'account'].includes(item.id)).map(item => <button key={item.id} type="button" className={view === item.id ? 'active' : ''} aria-current={view === item.id ? 'page' : undefined} onClick={() => setView(item.id)}><item.icon size={21} strokeWidth={1.9} /><span>{item.id === 'subscription' ? '订阅' : item.id === 'servers' ? '线路' : item.id === 'account' ? '我的' : '连接'}</span></button>)}
    </nav>

    {dialog && <ClientDialog type={dialog} busy={busy} subscription={activeSubscription} onClose={() => setDialog(null)} onCreate={() => void createSubscription()} onRotate={() => void rotateSubscription()} />}
    {toast && <div className="client-toast" role="status"><Check size={17} />{toast}</div>}
  </div>;
}

function LoginView({ email, setEmail, password, setPassword, error, busy, onSubmit }: {
  email: string; setEmail: (value: string) => void; password: string; setPassword: (value: string) => void; error: string; busy: boolean; onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
}) {
  const [theme, setTheme] = useState<'light' | 'dark'>('light');
  return <main className="client-login" data-theme={theme}>
    <section className="login-showcase"><a href="/client-preview.html" className="client-brand"><span className="client-brand-mark"><ShieldCheck size={27} /></span><span className="client-brand-copy"><b>迅连宝</b><small>SECURE NETWORK</small></span></a><div className="login-message"><span className="login-overline">XUNLIANBAO CLIENT</span><h1>一处连接，<br />多端安心。</h1><p>使用你的迅连宝会员账户，统一管理线路、套餐权益和订阅凭证。</p><div className="login-flow"><span><LockKeyhole size={19} /></span><i /><span><Globe2 size={19} /></span><i /><span><Smartphone size={19} /></span></div><div className="login-flow-caption"><span>账户安全</span><span>跨端同步</span><span>扫码导入</span></div></div><div className="login-showcase-footer"><span>WINDOWS · MACOS · ANDROID</span><span>统一会员与订阅</span></div></section>
    <section className="login-form-side"><div className="login-form-top"><span>会员客户端预览</span><button type="button" className="client-icon-button" aria-label="切换主题" onClick={() => setTheme(current => current === 'light' ? 'dark' : 'light')}>{theme === 'light' ? <Moon size={19} /> : <Sun size={19} />}</button></div><form className="client-login-form" onSubmit={onSubmit}><span className="form-eyebrow">欢迎回来</span><h2>登录迅连宝</h2><p>登录后同步你现有的套餐和固定订阅。</p>{error && <div className="login-error" role="alert">{error}</div>}<label htmlFor="client-email">邮箱地址</label><input id="client-email" type="email" autoComplete="username" value={email} onChange={event => setEmail(event.target.value)} placeholder="name@example.com" required /><label htmlFor="client-password">登录密码</label><input id="client-password" type="password" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} placeholder="输入会员账户密码" required /><button className="client-primary login-submit" type="submit" disabled={busy}>{busy ? '正在登录…' : '登录并同步账户'}<ArrowRight size={18} /></button><div className="login-local-hint"><ShieldCheck size={17} /><span>开发账号使用本地会员 API 验证，不会访问线上迅连宝账户。</span></div></form><div className="login-form-footer"><span>还没有账户？</span><a href="/account?view=plans">查看会员中心 <ExternalLink size={13} /></a></div></section>
  </main>;
}

function PageTitle({ eyebrow, title, description, action }: { eyebrow: string; title: string; description: string; action?: React.ReactNode }) {
  return <div className="client-page-title"><div><span>{eyebrow}</span><h1>{title}</h1><p>{description}</p></div>{action && <div className="page-title-action">{action}</div>}</div>;
}

function HomeView({ user, overview, subscription, onConnect, onServers, onSubscription, onPlans, orders }: {
  user: User; overview: Overview | null; subscription: Subscription | null; onConnect: () => void; onServers: () => void; onSubscription: () => void; onPlans: () => void; orders: Order[];
}) {
  const entitlement = overview?.entitlement ?? null;
  const used = entitlement ? percentage(entitlement.data_used_gb, entitlement.traffic_gb) : 0;
  return <div className="client-view-stack">
    <PageTitle eyebrow="连接中心" title={`你好，${user.name}`} description="你的账户权益、订阅和连接状态都在这里。" action={<span className="connection-unavailable"><i />VPN 内核待接入</span>} />
    <section className="connection-stage">
      <div className="connection-copy"><span className="connection-state"><i />连接能力待接入</span><h2>连接状态，一目了然。</h2><p>会员账户和订阅已同步；客户端内核及会员线路服务接入后，这里会显示真实连接状态。</p><button type="button" className="client-primary connect-action" onClick={onConnect}><ShieldCheck size={20} />查看连接准备情况<ArrowRight size={17} /></button><button type="button" className="current-route" onClick={onServers}><span className="route-icon"><MapPin size={19} /></span><span><small>会员线路</small><b>当前暂无可用线路</b></span><span className="route-disclaimer">查看说明 <ChevronDown size={16} /></span></button></div>
      <div className="route-visual" aria-label="客户端连接路径状态"><div className="route-visual-head"><span>连接路径</span><span className="route-live-label"><i />尚未开放</span></div><div className="path-track"><div className="path-node device-node"><span><MonitorIcon /></span><small>当前设备</small><b>会员客户端</b></div><div className="path-connector"><i /><span>加密通道</span></div><div className="path-node server-node"><span><Globe2 size={22} /></span><small>会员线路</small><b>等待线路开放</b></div></div><div className="path-caption"><LockKeyhole size={15} /><span>确认真实线路可用后，才会显示受保护状态。</span></div></div>
    </section>
    <section className="member-overview-band">
      <div className="member-overview-heading"><span className="band-icon"><BadgeCheck size={19} /></span><span><small>会员权益</small><b>{entitlement?.plan_name ?? '尚未开通有效套餐'}</b></span></div>
      {entitlement ? <><div className="member-overview-item"><small>有效期至</small><b>{dateText(entitlement.ends_at)}</b></div><div className="member-overview-item usage-item"><small>套餐周期流量</small><b>{entitlement.data_used_gb.toFixed(1)} <span>/ {entitlement.traffic_gb} GB</span></b><i><span style={{ width: `${used}%` }} /></i></div></> : <div className="member-overview-empty">套餐权益会从会员后台自动同步到客户端。</div>}
      <button type="button" className="client-text-button" onClick={onPlans}>{entitlement ? '查看套餐' : '选择套餐'}<ArrowRight size={16} /></button>
    </section>
    <div className="client-content-columns">
      <section className="client-section-block"><div className="client-section-heading"><div><span>快速访问</span><h2>你的订阅与设备</h2></div><button className="client-text-button" type="button" onClick={onSubscription}>查看全部 <ArrowRight size={15} /></button></div><button type="button" className="subscription-summary" onClick={onSubscription}><span className="summary-icon"><Link2 size={20} /></span><span className="summary-text"><b>{subscription ? '固定订阅已准备好' : '生成固定订阅链接'}</b><small>{subscription ? '续费后继续使用同一链接' : '由后台为当前账户生成'}</small></span><span className="summary-state">{subscription ? <><i />已就绪</> : '获取'}<ArrowRight size={16} /></span></button></section>
      <section className="client-section-block orders-overview"><div className="client-section-heading"><div><span>账户活动</span><h2>最近订单</h2></div><a className="client-text-button" href="/account?view=orders">订单记录 <ArrowUpRight size={15} /></a></div>{orders.length ? <div className="order-summary-row"><span className="order-icon"><CreditCard size={18} /></span><span><b>{orders[0].plan_name}</b><small>{shortDate(orders[0].created_at)} · {orders[0].status.includes('paid') ? '已完成 · 演示订单' : '本地演示订单'}</small></span><strong>{money(orders[0].total_cents)}</strong></div> : <div className="orders-empty"><span>尚无订单记录</span><a href="/account?view=plans">前往会员中心 <ArrowUpRight size={14} /></a></div>}</section>
    </div>
    <div className="connection-notice"><Shield size={17} /><span>客户端连接能力仍在开发；线路、速度、连接时长和用量只有服务端实际采集后才会显示。</span></div>
  </div>;
}

function MonitorIcon() {
  return <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="13" rx="2" stroke="currentColor" strokeWidth="1.7"/><path d="M8 21h8m-4-4v4" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" /></svg>;
}

function ServersView({ onPlans, onSubscription }: { onPlans: () => void; onSubscription: () => void }) {
  return <div className="client-view-stack">
    <PageTitle eyebrow="线路目录" title="会员可用线路" description="这里只会显示已开放给会员、并通过健康检查的真实节点。" action={<span className="directory-state"><i />暂未开放</span>} />
    <section className="server-unavailable" aria-labelledby="server-unavailable-title">
      <div className="server-unavailable-icon"><Globe2 size={34} /></div>
      <div className="server-unavailable-copy"><span className="connection-state"><i />会员线路暂不可用</span><h2 id="server-unavailable-title">当前没有可连接的线路</h2><p>管理后台已有节点运维页面，但会员线路目录和健康状态接口尚未开放。正式节点通过验收并发布后，才会显示在这里。</p>
        <div className="server-connection-status"><span><small>会员账户</small><b><i />已连接</b></span><span><small>订阅凭证</small><b><i />可同步</b></span><span><small>可用线路</small><b className="pending"><i />暂未开放</b></span></div>
        <div className="server-unavailable-actions"><button type="button" className="client-primary" onClick={onSubscription}>查看订阅与设备<ArrowRight size={16} /></button><button type="button" className="client-secondary" onClick={onPlans}>查看套餐权益</button></div>
      </div>
    </section>
    <div className="server-footer-band"><span className="server-footer-icon"><ShieldCheck size={20} /></span><span><b>线路状态以服务端实测为准</b><small>当前不会显示模拟地区、延迟、负载或连接成功状态。</small></span></div>
  </div>;
}

function SubscriptionView({ entitlement, subscription, loading, showQr, clients, onCreate, onRotate, onPlans, notify }: {
  entitlement: Overview['entitlement']; subscription: Subscription | null; loading: boolean; showQr: boolean;
  clients: Client[]; onCreate: () => void; onRotate: () => void; onPlans: () => void; notify: (message: string) => void;
}) {
  const verifiedCount = clients.filter(client => client.compatibility_status === 'verified').length;
  return <div className="client-view-stack">
    <PageTitle eyebrow="账户连接" title="订阅与设备" description="固定订阅链接由会员后台维护；续费后地址保持不变。" action={<button className="client-secondary" type="button" onClick={() => window.location.href = '/account?view=clients'}><ArrowDownToLine size={17} />客户端下载</button>} />
    <section className="subscription-member-band"><span className="subscription-member-icon"><BadgeCheck size={22} /></span><span className="subscription-member-copy"><small>当前会员权益</small><b>{entitlement?.plan_name ?? '暂无有效会员'}</b></span><span className="subscription-member-detail"><small>到期时间</small><b>{entitlement ? dateText(entitlement.ends_at) : '开通套餐后显示'}</b></span><span className="subscription-member-detail"><small>可用设备</small><b>{entitlement ? `最多 ${entitlement.max_devices} 台` : '—'}</b></span><button type="button" className="client-primary" onClick={onPlans}>{entitlement ? '查看套餐' : '选择套餐'}<ArrowRight size={16} /></button></section>
    <div className="subscription-main-grid"><section className="subscription-panel"><div className="client-section-heading"><div><span>固定订阅凭证</span><h2>{subscription ? '你的订阅已准备好' : '生成你的固定订阅链接'}</h2></div><span className={'credential-status' + (subscription ? ' ready' : '')}><i />{subscription ? '有效' : '待创建'}</span></div><p className="subscription-intro">{subscription ? '在迅连宝客户端扫码，或复制到兼容的 Clash / Mihomo 客户端。续费不会改变此地址。' : '订阅会绑定当前会员账户，由后台生成固定凭证。获取后即可扫码或复制导入。'}</p>{subscription?.url ? <><SubscriptionImport subscription={subscription} entitlement={entitlement} notify={notify} initialQr={showQr} /><button type="button" className="client-quiet-button" onClick={onRotate}><RefreshCw size={16} />轮换固定凭证</button></> : <div className="credential-empty"><div className="credential-empty-art"><KeyRound size={25} /><span /><span /></div><div><b>为当前账户准备专属凭证</b><small>同一账户只保留一个有效固定链接；需要时可轮换旧凭证。</small></div><button className="client-primary" type="button" disabled={loading} onClick={onCreate}>{loading ? '正在准备…' : '获取固定订阅'}<ArrowRight size={16} /></button></div>}<div className="credential-security"><LockKeyhole size={16} /><span>订阅地址等同连接凭证，请勿公开分享。轮换后旧地址会立即失效。</span></div></section>
      <aside className="client-side-panel"><div className="client-section-heading"><div><span>兼容性目录</span><h2>已配置客户端</h2></div><span className="client-count">{verifiedCount} 已验证</span></div>{clients.length ? <div className="compatible-clients">{clients.slice(0, 4).map(client => <div className="compatible-client" key={client.id}><span className="compatible-icon">{client.platform === 'android' || client.platform === 'ios' ? <Smartphone size={19} /> : <MonitorIcon />}</span><span><b>{client.name}</b><small>{client.version ? `版本 ${client.version}` : '版本未配置'}</small></span><span className={'compatibility-tag ' + client.compatibility_status}>{compatibilityLabel(client.compatibility_status)}</span></div>)}</div> : <div className="client-empty-compact"><CloudDownload size={23} /><span>后台尚未配置可下载客户端</span></div>}<a className="client-text-button" href="/account?view=clients">查看客户端目录 <ArrowUpRight size={15} /></a><div className="supported-format"><span className="format-mark">YAML</span><span><b>Clash / Mihomo</b><small>格式需经真实客户端版本验证</small></span></div></aside></div>
  </div>;
}


function PlansView({ plans, onOpenMemberCenter }: { plans: Plan[]; onOpenMemberCenter: () => void }) {
  return <div className="client-view-stack"><PageTitle eyebrow="会员权益" title="选择适合你的套餐" description="套餐信息读取自本地会员后台，订单与支付继续使用会员中心现有流程。" action={<span className="client-sandbox-label"><ShieldCheck size={16} />本地演示订单，不会扣款</span>} />{plans.length ? <div className="client-plan-grid">{plans.map((plan, index) => <article className={'client-plan' + (index === 2 ? ' featured' : '')} key={plan.id}><span className="plan-period">{plan.period_label}</span><h2>{plan.name}</h2><div className="plan-price">{money(plan.price_cents)}<small> / {plan.period_label}</small></div><div className="plan-includes"><span><Check size={16} />{plan.traffic_gb} GB 周期累计流量</span><span><Check size={16} />最多 {plan.max_devices} 台设备</span><span><Check size={16} />订阅续费沿用固定链接</span></div><button type="button" className={index === 2 ? 'client-primary' : 'client-secondary'} onClick={onOpenMemberCenter}>前往会员中心办理<ArrowRight size={16} /></button></article>)}</div> : <div className="client-empty-state"><CreditCard size={23} /><b>正在读取套餐</b><span>请检查会员 API 是否可用。</span></div>}<div className="plan-flow-note"><span className="flow-number">01</span><span><b>选择套餐</b><small>使用同一会员账号</small></span><ArrowRight size={17} /><span className="flow-number">02</span><span><b>会员中心下单</b><small>本地环境仅生成测试订单</small></span><ArrowRight size={17} /><span className="flow-number">03</span><span><b>权益同步</b><small>客户端刷新会员状态</small></span></div></div>;
}

function AccountView({ user, overview, clients, verifiedClients, onOrders, onSupport, onLogout }: {
  user: User; overview: Overview | null; clients: Client[]; verifiedClients: Client[]; onOrders: () => void; onSupport: () => void; onLogout: () => void;
}) {
  const anonymous = user.account_type === 'anonymous';
  return <div className="client-view-stack">
    <PageTitle eyebrow="账户设置" title="账户与设备" description={anonymous ? '这是尚未绑定邮箱的匿名账号；升级与跨设备恢复入口尚未开放。' : '同一个迅连宝账户，跨端查看套餐、客户端和订单状态。'} />
    <section className="account-profile-band"><span className="account-avatar-large">{user.name.slice(0, 1)}</span><span><small>{anonymous ? '匿名账号' : '会员账户'}</small><b>{user.name}</b><em>{user.email ?? '未绑定邮箱'}</em></span><button className="client-secondary" type="button" onClick={onLogout}><LogOut size={16} />退出账户</button></section>
    <div className="account-facts">
      <div><span className="account-fact-icon"><BadgeCheck size={19} /></span><span><small>当前权益</small><b>{overview?.entitlement?.plan_name ?? '暂无有效会员'}</b></span><ArrowRight size={16} /></div>
      <div><span className="account-fact-icon"><Smartphone size={19} /></span><span><small>客户端目录</small><b>{clients.length} 个 · {verifiedClients.length} 个已验证</b></span><ArrowRight size={16} /></div>
      <button type="button" onClick={onOrders}><span className="account-fact-icon"><CreditCard size={19} /></span><span><small>订单与支付</small><b>进入会员中心查看记录</b></span><ArrowUpRight size={16} /></button>
      <button type="button" onClick={onSupport}><span className="account-fact-icon"><LifeBuoy size={19} /></span><span><small>帮助与支持</small><b>查看帮助工单</b></span><ArrowUpRight size={16} /></button>
    </div>
    {anonymous && <div className="connection-notice"><ShieldCheck size={17} /><span>此匿名账号未绑定邮箱，目前不支持通过邮箱在其他设备恢复；账号绑定或升级入口尚未开放。</span></div>}
    <div className="connection-notice"><ShieldCheck size={17} /><span>登录、会员权益、订单和订阅凭证已使用 Laravel 本地会话 API。客户端安装包、内核连接、设备心跳和流量采集尚未上线。</span></div>
  </div>;
}

function ClientDialog({ type, busy, subscription, onClose, onCreate, onRotate }: {
  type: 'connect' | 'subscription' | 'rotate'; busy: boolean; subscription: Subscription | null; onClose: () => void; onCreate: () => void; onRotate: () => void;
}) {
  const content = {
    connect: { icon: ShieldCheck, title: '连接内核尚未接入', body: '这个客户端原型还没有启动 Mihomo，也没有真实会员节点。下一阶段将把桌面端连接按钮接入 Tauri sidecar，把 Android 接入系统 VPNService。', action: '知道了', run: onClose },
    subscription: { icon: Link2, title: '获取固定订阅', body: '后台会为当前会员账户准备一个固定地址。它可以提前生成，购买或续费后仍沿用；当前节点是本地演示数据，不能建立真实 VPN 连接。', action: '生成并显示二维码', run: onCreate },
    rotate: { icon: RefreshCw, title: '轮换订阅凭证？', body: '确认后旧订阅链接将立即失效。已导入的客户端需要更新为新链接。', action: '确认轮换', run: onRotate },
  }[type];
  const Icon = content.icon;
  return <div className="client-dialog-backdrop" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) onClose(); }}><section className="client-dialog" role="dialog" aria-modal="true" aria-labelledby="client-dialog-title"><button className="client-icon-button dialog-close" type="button" aria-label="关闭" onClick={onClose}><X size={19} /></button><span className="dialog-icon"><Icon size={23} /></span><h2 id="client-dialog-title">{content.title}</h2><p>{content.body}</p>{type === 'rotate' && subscription && <small className="dialog-url">当前凭证创建于 {dateText(subscription.created_at)}</small>}<div className="dialog-actions"><button type="button" className="client-secondary" onClick={onClose} disabled={busy}>{type === 'connect' ? '返回客户端' : '取消'}</button>{type !== 'connect' && <button type="button" className="client-primary" onClick={content.run} disabled={busy}>{busy ? '处理中…' : content.action}<ArrowRight size={16} /></button>}{type === 'connect' && <button type="button" className="client-primary" onClick={content.run}>了解了</button>}</div></section></div>;
}

function compatibilityLabel(status: string) {
  return ({ verified: '已验证', pending: '待验证', maintenance: '维护中', unsupported: '不支持' } as Record<string, string>)[status] ?? '待验证';
}
