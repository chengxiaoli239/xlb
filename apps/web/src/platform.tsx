import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Activity, ArrowDownToLine, ArrowLeftRight, ArrowUpRight, BadgeCheck,
  Bell, ChevronDown, CircleHelp, CircleUserRound, Clipboard, Command,
  CreditCard, Gauge, Globe2, KeyRound, LayoutDashboard, LifeBuoy, Link2,
  LogOut, Menu, Monitor, Moon, MoreHorizontal, Network, Puzzle, RefreshCw, Search,
  QrCode, Server, Settings2, ShieldCheck, Smartphone, Sun, Users, X,
} from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';

type User = { id: string; email: string; name: string; role: 'admin' | 'member' };
type Plan = { id: string; name: string; period_label: string; duration_days: number; price_cents: number; traffic_gb: number; max_devices: number };
type ManagedPlan = Plan & { family_id: string; version: number; active: number };
type Overview = {
  user: User;
  entitlement: null | { status: string; ends_at: string; data_used_gb: number; plan_name: string; period_label: string; traffic_gb: number; max_devices: number };
  activeSubscriptions: number;
  recentOrders: Array<{ id: string; plan_name: string; total_cents: number; status: string; created_at: string }>;
};
type NodeStatus = 'candidate' | 'available' | 'draining' | 'maintenance' | 'isolated' | 'retired' | 'disabled';
type Node = { id: string; name: string; region: string; protocol: string; endpoint_host: string; endpoint_port: number; status: NodeStatus; is_test: boolean; automation_managed: boolean; max_sessions: number; active_sessions: number; latency_ms: number | null; health_status: string; last_health_check_at: string | null; config_version: number; allowed_transitions: NodeStatus[]; updated_at: string };
type NodeDetail = Node & { changes: Array<{ id: string; from_status: NodeStatus; to_status: NodeStatus; created_at: string; actor_name: string }> };
type AdminSummary = { memberCount: number; activeEntitlements: number; activeSubscriptions: number; availableNodes: number; nodeCount: number; pendingCommissionCents: number; recentAudit: Array<{ id: string; action: string; target_type: string; target_id: string; created_at: string; actor: string }> };
type Subscription = { id: string; name: string; status: string; created_at: string; expires_at: string | null; last_used_at: string | null; url: string | null };
type ClientPlatform = 'android' | 'ios' | 'windows' | 'macos' | 'linux' | 'browser';
type ClientCompatibility = 'pending' | 'verified' | 'maintenance' | 'unsupported';
type ClientCatalogItem = { id: string; platform: ClientPlatform; name: string; version: string | null; description: string | null; download_url: string | null; qr_code_url: string | null; tutorial_url: string | null; compatibility_status: ClientCompatibility };
type ManagedClient = ClientCatalogItem & { is_active: boolean; sort_order: number };
type ClientForm = { platform: ClientPlatform; name: string; version: string; description: string; download_url: string; qr_code_url: string; tutorial_url: string; compatibility_status: ClientCompatibility; sort_order: string; is_active: boolean };
type Commission = { id: string; partner_name: string; amount_cents: number; status: string; order_id: string; created_at: string };
type TicketStatus = 'open' | 'in_progress' | 'resolved';
type TicketSummary = { id: string; category: string; subject: string; status: TicketStatus; created_at: string; updated_at: string; user_name: string };
type TicketEvent = { id: string; kind: 'message' | 'status_changed'; body: string | null; from_status: TicketStatus | null; to_status: TicketStatus | null; created_at: string; actor_name: string; actor_role: User['role'] };
type TicketDetail = TicketSummary & { events: TicketEvent[]; resolved_at: string | null };
type OrderSummary = { id: string; plan_name: string; total_cents: number; status: string; created_at: string; expires_at: string | null; user_name: string };
type OrderDetail = OrderSummary & { plan_snapshot: Plan & { version: number }; user_email: string; is_test: boolean; payment: null | { id: string; transaction_id: string; amount_cents: number; currency: string; status: string; review_reason: string | null; created_at: string }; entitlement_grant: null | { id: string; entitlement_id: string; status: string; starts_at: string; ends_at: string }; events: Array<{ id: string; from_status: string | null; to_status: string; created_at: string; actor_name: string | null }> };
const orderStatusText: Record<string, string> = { pending_test: '待支付 · 测试', cancelled_test: '已取消 · 测试', expired_test: '已超时 · 测试', paid_test: '已完成 · 测试' };
const orderTone = (status: string): 'green' | 'amber' | 'gray' => status === 'paid_test' ? 'green' : status === 'pending_test' ? 'amber' : 'gray';
const dateTime = (value: string | null) => value ? new Date(value).toLocaleString('zh-CN') : '—';
type PageMeta = { total: number; page: number; per_page: number; total_pages: number };

const apiEnvelope = async <T,>(path: string, init?: RequestInit): Promise<{ data: T; meta?: PageMeta }> => {
  const unsafe = init?.method && !['GET', 'HEAD'].includes(init.method.toUpperCase());
  const csrf = unsafe ? await fetch('/api/v1/auth/csrf', { credentials: 'same-origin' }).then(response => {
    if (!response.ok) throw new Error('无法建立安全会话');
    return response.json() as Promise<{ data: { token: string } }>;
  }) : null;
  const response = await fetch(path, { ...init, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf.data.token } : {}), ...init?.headers } });
  if (!response.ok) {
    const body = await response.json().catch(() => null);
    throw new Error(body?.error?.message ?? '请求失败');
  }
  if (response.status === 204) return { data: undefined as T };
  return response.json();
};
const api = async <T,>(path: string, init?: RequestInit): Promise<T> => (await apiEnvelope<T>(path, init)).data;
const money = (cents: number) => '¥' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
const shortDate = (value?: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { month: 'short', day: 'numeric' }).format(new Date(value)) : '尚未使用';
const percent = (used: number, total: number) => total > 0 ? Math.min(100, Math.round(used / total * 100)) : 0;
const absoluteSubscriptionUrl = (value: string) => new URL(value, window.location.origin).toString();
const subscriptionQrNote = () => ['localhost', '127.0.0.1'].includes(window.location.hostname)
  ? '本机预览地址仅本机可达；手机扫码需使用可访问的局域网地址或正式域名。'
  : '订阅凭证请勿转发或公开展示。';
const clashVergeImportUrl = (value: string) => {
  const url = new URL('clash-verge://install-config');
  url.searchParams.set('name', '迅连宝');
  url.searchParams.set('url', absoluteSubscriptionUrl(value));
  return url.toString();
};

const nav = [
  { id: 'overview', label: '总览', icon: LayoutDashboard },
  { id: 'subscriptions', label: '订阅与设备', icon: Link2 },
  { id: 'plans', label: '套餐权益', icon: CreditCard },
  { id: 'orders', label: '订单记录', icon: ArrowLeftRight },
  { id: 'support', label: '帮助工单', icon: LifeBuoy },
];
const memberNav = [...nav, { id: 'clients', label: '客户端下载', icon: ArrowDownToLine }];
const clientPlatforms: Array<{ id: ClientPlatform; label: string; icon: typeof Smartphone }> = [
  { id: 'android', label: 'Android 安卓系统', icon: Smartphone },
  { id: 'ios', label: 'iPhone / iPad', icon: Smartphone },
  { id: 'windows', label: 'Windows 系统', icon: Monitor },
  { id: 'macos', label: 'macOS 苹果电脑', icon: Monitor },
  { id: 'linux', label: 'Linux / 路由器', icon: Network },
  { id: 'browser', label: '浏览器扩展', icon: Puzzle },
];
const adminNav = [
  { id: 'admin-plans', label: '套餐管理', icon: CreditCard },
  { id: 'admin-clients', label: '客户端目录', icon: Monitor },
  { id: 'users', label: '用户与会员', icon: Users },
  { id: 'nodes', label: '节点运维', icon: Server },
  { id: 'commissions', label: '代理结算', icon: ArrowUpRight },
];

export default function App() {
  const portalRole = window.location.pathname.startsWith('/account') || new URLSearchParams(window.location.search).get('portal') === 'member' ? 'member' : 'admin';
  const requestedView = new URLSearchParams(window.location.search).get('view');
  const memberViews = ['overview', 'subscriptions', 'plans', 'orders', 'support', 'clients'];
  const initialView = portalRole === 'member' && requestedView && memberViews.includes(requestedView) ? requestedView : 'overview';
  const [theme, setTheme] = useState<'dark' | 'light'>(() => {
    try { return window.localStorage.getItem('xlb-console-theme') === 'light' ? 'light' : 'dark'; }
    catch { return 'dark'; }
  });
  const [user, setUser] = useState<User | null>(null);
  const [view, setView] = useState(initialView);
  const [mobileMenu, setMobileMenu] = useState(false);
  const [toast, setToast] = useState('');
  const [loginEmail, setLoginEmail] = useState(portalRole === 'member' ? 'member@xlb.local' : 'admin@xlb.local');
  const [password, setPassword] = useState(portalRole === 'member' ? 'member-local-2026' : 'admin-local-2026');
  const [loading, setLoading] = useState(true);
  const [nonce, setNonce] = useState(0);
  const refresh = useCallback(() => setNonce(value => value + 1), []);
  const notify = useCallback((message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 2600);
  }, []);

  useEffect(() => {
    try { window.localStorage.setItem('xlb-console-theme', theme); }
    catch { /* Theme switching still works when browser storage is unavailable. */ }
  }, [theme]);

  useEffect(() => {
    api<User | null>('/api/v1/auth/session').then(async current => {
      if (current && current.role !== portalRole) {
        await api('/api/v1/auth/logout', { method: 'POST' }).catch(() => undefined);
        setUser(null);
        return;
      }
      setUser(current);
    }).catch(() => setUser(null)).finally(() => setLoading(false));
  }, [portalRole]);
  useEffect(() => {
    if (!user) return;
    api<User | null>('/api/v1/auth/session').then(setUser).catch(() => setUser(null));
  }, [nonce, user?.id]);

  const login = async (event: React.FormEvent) => {
    event.preventDefault();
    try {
      const next = await api<User>('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: loginEmail, password }) });
      if (next.role !== portalRole) {
        await api('/api/v1/auth/logout', { method: 'POST' });
        throw new Error(portalRole === 'member' ? '请使用会员账户登录' : '请使用管理员账户登录');
      }
      setUser(next);
      notify('已进入本地演示环境');
    } catch (error) { notify((error as Error).message); }
  };
  const logout = async () => {
    await api('/api/v1/auth/logout', { method: 'POST' });
    setUser(null);
    setView('overview');
  };

  if (loading) return <main className="boot"><span className="brand-mark">X</span><span>迅连宝控制台</span></main>;
  if (!user) return <Login portalRole={portalRole} email={loginEmail} setEmail={setLoginEmail} password={password} setPassword={setPassword} onSubmit={login} />;
  if (user.role === 'member') return <MemberPortal user={user} view={view} setView={setView} theme={theme} setTheme={setTheme} refresh={nonce} refreshData={refresh} notify={notify} logout={logout} toast={toast} />;

  return <div className="app-shell" data-theme={theme}>
    <Sidebar view={view} setView={setView} role={user.role} mobile={mobileMenu} close={() => setMobileMenu(false)} logout={logout} user={user} />
    {mobileMenu && <button className="scrim" aria-label="关闭菜单" onClick={() => setMobileMenu(false)} />}
    <div className="workspace">
      <header className="topbar">
        <button className="icon-button menu-toggle" onClick={() => setMobileMenu(true)} aria-label="打开导航"><Menu size={19} /></button>
        <div className="crumb"><span>控制台</span><span className="crumb-slash">/</span><strong>{labelFor(view, user.role)}</strong></div>
        <div className="top-actions">
          <span className="environment"><i /> 本地开发环境</span>
          <button type="button" className="icon-button theme-toggle" aria-label={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} title={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} onClick={() => setTheme(value => value === 'dark' ? 'light' : 'dark')}>
            {theme === 'dark' ? <Sun size={19} /> : <Moon size={19} />}
          </button>
          <button className="icon-button notification" aria-label="通知"><Bell size={17} /><span /></button>
          <button className="profile-trigger" onClick={() => setView(user.role === 'admin' ? 'users' : 'overview')}>
            <span className="profile-avatar">{user.name.slice(0, 1)}</span><span className="profile-name">{user.name}</span><ChevronDown size={14} />
          </button>
        </div>
      </header>
      <main className="main-content">
        {view === 'overview' && <OverviewPage user={user} refresh={nonce} setView={setView} notify={notify} />}
        {view === 'subscriptions' && <SubscriptionsPage refresh={nonce} notify={notify} />}
        {view === 'plans' && <PlansPage role={user.role} refresh={nonce} notify={notify} onDataChange={refresh} />}
        {view === 'orders' && <OrdersPage role={user.role} refresh={nonce} notify={notify} onDataChange={refresh} />}
        {view === 'support' && <SupportPage role={user.role} refresh={nonce} notify={notify} />}
        {view === 'users' && user.role === 'admin' && <UsersPage refresh={nonce} notify={notify} />}
        {view === 'admin-plans' && user.role === 'admin' && <AdminPlansPage notify={notify} />}
        {view === 'admin-clients' && user.role === 'admin' && <AdminClientsPage notify={notify} />}
        {view === 'nodes' && user.role === 'admin' && <NodesPage refresh={nonce} notify={notify} />}
        {view === 'commissions' && user.role === 'admin' && <CommissionsPage refresh={nonce} notify={notify} />}
      </main>
      <footer className="app-footer"><span>迅连宝控制台</span><span>本地重建 · 数据仅存于本机</span><button onClick={refresh}><RefreshCw size={13} /> 刷新数据</button></footer>
    </div>
    {toast && <div className="toast" role="status">{toast}</div>}
  </div>;
}

function MemberPortal({ user, view, setView, theme, setTheme, refresh, refreshData, notify, logout, toast }: {
  user: User; view: string; setView: (view: string) => void; theme: 'dark' | 'light'; setTheme: (theme: 'dark' | 'light') => void;
  refresh: number; refreshData: () => void; notify: (message: string) => void; logout: () => Promise<void>; toast: string;
}) {
  return <div className="member-portal" data-theme={theme}>
    <header className="member-header">
      <a className="member-brand" href="/account" aria-label="迅连宝会员中心"><span className="brand-mark">X</span><span>迅连宝<small>VPN</small></span></a>
      <nav className="member-nav" aria-label="会员导航">
        {memberNav.map(item => <button key={item.id} type="button" aria-current={view === item.id ? 'page' : undefined} onClick={() => setView(item.id)}><item.icon size={16} />{item.label}</button>)}
      </nav>
      <div className="member-account"><span className="member-account-name"><span className="profile-avatar">{user.name.slice(0, 1)}</span>{user.name}</span>
        <button type="button" className="icon-button theme-toggle" aria-label={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} title={theme === 'dark' ? '切换浅色主题' : '切换深色主题'} onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>{theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}</button>
        <button type="button" className="member-logout" onClick={() => void logout()}><LogOut size={15} />退出</button>
      </div>
    </header>
    <main className="member-main">
      <div className="member-preview-banner"><ShieldCheck size={17} /><span><b>本地开发预览</b> 套餐操作只生成演示订单，不会扣款，也不会开通真实 VPN 连接。</span></div>
      {view === 'overview' && <OverviewPage user={user} refresh={refresh} setView={setView} notify={notify} />}
      {view === 'subscriptions' && <SubscriptionsPage refresh={refresh} notify={notify} />}
      {view === 'clients' && <ClientDownloadPage refresh={refresh} setView={setView} notify={notify} />}
      {view === 'plans' && <PlansPage role="member" refresh={refresh} notify={notify} onDataChange={refreshData} />}
      {view === 'orders' && <OrdersPage role="member" refresh={refresh} notify={notify} onDataChange={refreshData} />}
      {view === 'support' && <SupportPage role="member" refresh={refresh} notify={notify} />}
    </main>
    <footer className="member-footer"><span>迅连宝会员中心</span><span>本地重建 · 数据仅用于开发验证</span><button type="button" onClick={refreshData}><RefreshCw size={14} />刷新</button></footer>
    {toast && <div className="toast" role="status">{toast}</div>}
  </div>;
}

function labelFor(view: string, role?: User['role']) {
  if (view === 'orders' && role === 'admin') return '订单管理';
  return [...memberNav, ...adminNav].find(item => item.id === view)?.label ?? '总览';
}

function Sidebar({ view, setView, role, mobile, close, logout, user }: {
  view: string; setView: (view: string) => void; role: User['role']; mobile: boolean; close: () => void; logout: () => void; user: User;
}) {
  const change = (id: string) => { setView(id); close(); };
  return <aside className={'sidebar' + (mobile ? ' is-open' : '')}>
    <div className="sidebar-top">
      <a className="brand" href="#" onClick={e => { e.preventDefault(); change('overview'); }}>
        <span className="brand-mark">X</span><span className="brand-word">迅连宝<small>VPN</small></span>
      </a>
      <button className="icon-button sidebar-close" aria-label="关闭导航" onClick={close}><X size={17} /></button>
    </div>
    <div className="workspace-switch"><span className="workspace-icon"><Command size={15} /></span><span><b>迅连宝工作区</b><small>{role === 'admin' ? '运营控制台' : '个人账户'}</small></span><ChevronDown size={14} /></div>
    <nav className="side-nav">
      <p className="nav-caption">工作区</p>
      {nav.map(item => <NavItem key={item.id} {...item} label={item.id === 'orders' && role === 'admin' ? '订单管理' : item.label} active={view === item.id} onClick={() => change(item.id)} />)}
      {role === 'admin' && <>
        <p className="nav-caption nav-caption-admin">运营管理</p>
        {adminNav.map(item => <NavItem key={item.id} {...item} active={view === item.id} onClick={() => change(item.id)} />)}
      </>}
    </nav>
    <div className="sidebar-bottom">
      <div className="side-status"><span className="pulse" /><span>演示数据正常</span><span className="status-count">1 / 1</span></div>
      <button className="sidebar-link" onClick={() => change('subscriptions')}><CircleHelp size={16} /> 使用帮助 <ArrowUpRight size={13} /></button>
      <button className="sidebar-link" onClick={logout}><LogOut size={16} /> 退出账户</button>
      <div className="side-user"><span className="profile-avatar">{user.name.slice(0, 1)}</span><span><b>{user.name}</b><small>{role === 'admin' ? '管理员' : '会员账户'}</small></span><MoreHorizontal size={17} /></div>
    </div>
  </aside>;
}

function NavItem({ label, icon: Icon, active, onClick }: { label: string; icon: typeof LayoutDashboard; active: boolean; onClick: () => void }) {
  return <button className={'nav-item' + (active ? ' active' : '')} onClick={onClick}><Icon size={17} strokeWidth={1.8} /><span>{label}</span>{active && <span className="nav-indicator" />}</button>;
}

function Login({ portalRole, email, setEmail, password, setPassword, onSubmit }: {
  portalRole: User['role']; email: string; setEmail: (value: string) => void; password: string; setPassword: (value: string) => void; onSubmit: (event: React.FormEvent) => void;
}) {
  return <main className="login-shell">
    <div className="login-aside">
      <div className="login-logo"><span className="brand-mark">X</span><span className="brand-word">迅连宝<small>VPN</small></span></div>
      <div className="login-copy"><span className="eyebrow"><i /> {portalRole === 'member' ? 'MEMBER ACCOUNT' : 'SERVICE CONTROL PLANE'}</span><h1>{portalRole === 'member' ? <>管理账户，<br />轻松连接。</> : <>连接服务，<br />一处管理。</>}</h1><p>{portalRole === 'member' ? '查看会员权益、订阅和账户服务。' : '账户、订阅、节点和结算状态，在同一工作区清楚呈现。'}</p></div>
      <div className="login-art"><div className="orbit orbit-one" /><div className="orbit orbit-two" /><div className="orbit-core"><ShieldCheck size={30} /></div><div className="orbit-node node-one" /><div className="orbit-node node-two" /><div className="orbit-node node-three" /><div className="art-label label-one"><span>API</span> 控制面在线</div><div className="art-label label-two"><span>01</span> 测试节点</div></div>
      <div className="login-aside-foot"><span>XL B · LOCAL PLATFORM</span><span>安全 · 可审计 · 可维护</span></div>
    </div>
    <section className="login-panel"><div className="login-panel-top"><span className="environment"><i /> 本地开发环境</span><button className="icon-button"><CircleHelp size={17} /></button></div>
      <form className="login-form" onSubmit={onSubmit}><span className="eyebrow">欢迎回来</span><h2>{portalRole === 'member' ? '登录会员中心' : '登录管理后台'}</h2><p>{portalRole === 'member' ? '管理你的套餐、设备与订阅。' : '请选择一个本地演示账户继续。'}</p>
        <label>邮箱地址<input type="email" autoComplete="username" value={email} onChange={e => setEmail(e.target.value)} required /></label>
        <label>登录密码<input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)} required /></label>
        <div className="form-meta"><label className="remember"><input type="checkbox" defaultChecked /> 保持登录</label><span>本机演示账号</span></div>
        <button className="primary-button login-submit">{portalRole === 'member' ? '登录会员中心' : '登录管理后台'} <ArrowUpRight size={16} /></button>
        {portalRole === 'admin' && <div className="demo-hint"><ShieldCheck size={15} /><span>默认管理员：<b>admin@xlb.local</b> / <b>admin-local-2026</b></span></div>}
        <div className="demo-hint"><CircleUserRound size={15} /><span>会员演示：<b>member@xlb.local</b> / <b>member-local-2026</b></span></div>
      </form>
      <div className="login-panel-foot"><span>© 2026 迅连宝</span><span>仅用于本地开发与验证</span></div>
    </section>
  </main>;
}

function PageHeading({ eyebrow, title, subtitle, action }: { eyebrow: string; title: string; subtitle: string; action?: React.ReactNode }) {
  return <div className="page-heading"><div><div className="eyebrow">{eyebrow}</div><h1>{title}</h1><p>{subtitle}</p></div>{action}</div>;
}
function SectionTitle({ title, detail, action }: { title: string; detail?: string; action?: React.ReactNode }) {
  return <div className="section-title"><div><h2>{title}</h2>{detail && <span>{detail}</span>}</div>{action}</div>;
}
function Modal({ title, detail, onClose, children, wide = false }: { title: string; detail?: string; onClose: () => void; children: React.ReactNode; wide?: boolean }) {
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const element = dialog.current;
    element?.showModal();
    return () => { if (element?.open) element.close(); };
  }, []);
  return <dialog ref={dialog} className={'app-modal' + (wide ? ' app-modal-wide' : '')} aria-label={title} onCancel={event => { event.preventDefault(); onClose(); }} onClick={event => {
    const element = dialog.current;
    if (!element || event.target !== element) return;
    const bounds = element.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) onClose();
  }}>
    <div className="modal-head"><div><h2>{title}</h2>{detail && <p>{detail}</p>}</div><button type="button" className="icon-button" aria-label="关闭弹框" onClick={onClose}><X size={18} /></button></div>
    <div className="modal-body">{children}</div>
  </dialog>;
}
function LoadingBlock() { return <div className="loading-block"><span className="spinner" /> 正在读取本地数据</div>; }
function Empty({ title, detail }: { title: string; detail: string }) { return <div className="empty-state"><Network size={21} /><b>{title}</b><span>{detail}</span></div>; }

function OverviewPage({ user, refresh, setView, notify }: { user: User; refresh: number; setView: (view: string) => void; notify: (value: string) => void }) {
  const [overview, setOverview] = useState<Overview | null>(null);
  const [summary, setSummary] = useState<AdminSummary | null>(null);
  const isAdmin = user.role === 'admin';
  useEffect(() => {
    api<Overview>('/api/v1/me/overview').then(setOverview).catch(error => notify((error as Error).message));
    if (isAdmin) api<AdminSummary>('/api/v1/admin/summary').then(setSummary).catch(error => notify((error as Error).message));
  }, [refresh, isAdmin, notify]);
  const entitlement = overview?.entitlement;
  const used = entitlement ? percent(entitlement.data_used_gb, entitlement.traffic_gb) : 0;
  const metrics = isAdmin && summary ? [
    { label: '注册会员', value: summary.memberCount, note: '本地演示账户', icon: Users, tone: 'teal' },
    { label: '有效权益', value: summary.activeEntitlements, note: '当前有效', icon: BadgeCheck, tone: 'green' },
    { label: '目录标记可用', value: summary.availableNodes + ' / ' + summary.nodeCount, note: '不代表真实在线', icon: Server, tone: 'amber' },
    { label: '待结算佣金', value: money(summary.pendingCommissionCents), note: '演示账本', icon: ArrowUpRight, tone: 'blue' },
  ] : null;
  return <div className="page-stack">
    <PageHeading eyebrow={isAdmin ? '运营总览' : '账户总览'} title={'早上好，' + user.name} subtitle={isAdmin ? '这里是迅连宝本地平台的运行概况。' : '管理你的套餐、设备和 Clash 订阅。'} action={<button className="secondary-button" onClick={() => notify('当前为本地开发环境')}><ArrowDownToLine size={15} /> 导出概览</button>} />
    {isAdmin && metrics ? <div className="metric-grid">{metrics.map(item => <Metric key={item.label} {...item} />)}</div> : <MemberEntitlement entitlement={entitlement} setView={setView} />}
    <div className="content-grid">
      <section className="panel activity-panel"><SectionTitle title={isAdmin ? '近期操作' : '最近订单'} detail={isAdmin ? '管理员与系统的最近操作记录' : '仅显示当前账户的订单'} action={<button className="text-button" onClick={() => setView(isAdmin ? 'nodes' : 'orders')}>查看全部 <ArrowUpRight size={14} /></button>} />
        {isAdmin ? <AuditList rows={summary?.recentAudit} /> : <OrderRows rows={overview?.recentOrders} />}
      </section>
      <section className="panel quick-panel"><SectionTitle title={isAdmin ? '平台状态' : '快捷操作'} detail={isAdmin ? '本地运行与测试状态' : '常用账户功能'} />
        {isAdmin ? <PlatformStatus summary={summary} setView={setView} /> : <div className="quick-actions"><QuickAction icon={Link2} label="管理订阅链接" detail={(overview?.activeSubscriptions ?? 0) + ' 个有效订阅'} onClick={() => setView('subscriptions')} /><QuickAction icon={CreditCard} label="查看套餐权益" detail="会员期限、流量与设备数" onClick={() => setView('plans')} /><QuickAction icon={LifeBuoy} label="使用帮助" detail="Clash 导入与账户说明" onClick={() => notify('帮助中心正在建设')} /></div>}
      </section>
    </div>
    <section className="panel bottom-panel"><SectionTitle title={isAdmin ? '服务组件' : '账户安全'} detail={isAdmin ? '开发环境中的主要服务模块' : '当前会话和凭证状态'} />
      {isAdmin ? <div className="service-strip"><ServiceItem label="账户 API" status="正常" icon={CircleUserRound} /><ServiceItem label="订阅接口" status="正常" icon={Link2} /><ServiceItem label="节点控制" status="演示" icon={Server} /><ServiceItem label="代理结算" status="样例数据" icon={ArrowUpRight} /></div> : <div className="service-strip"><ServiceItem label="登录会话" status="有效" icon={ShieldCheck} /><ServiceItem label="设备管理" status="可查看" icon={Smartphone} /><ServiceItem label="订阅凭证" status="可撤销" icon={KeyRound} /><ServiceItem label="密码保护" status="本地验证" icon={BadgeCheck} /></div>}
    </section>
  </div>;
}

function Metric({ label, value, note, icon: Icon, tone }: { label: string; value: string | number; note: string; icon: typeof Users; tone: string }) {
  return <div className="metric"><div className="metric-head"><span>{label}</span><span className={'metric-icon ' + tone}><Icon size={17} /></span></div><div className="metric-value">{value}</div><div className="metric-foot"><span className="metric-dot" />{note}</div></div>;
}
function MemberEntitlement({ entitlement, setView }: { entitlement?: Overview['entitlement']; setView: (view: string) => void }) {
  if (!entitlement) return <section className="panel member-empty"><BadgeCheck size={22} /><div><b>当前没有有效会员权益</b><span>请从正式购买入口确认账户套餐。</span></div></section>;
  const used = percent(entitlement.data_used_gb, entitlement.traffic_gb);
  return <section className="member-hero"><div className="member-main"><div className="member-tag"><BadgeCheck size={14} /> 当前有效会员</div><h2>{entitlement.plan_name}</h2><p>有效至 {shortDate(entitlement.ends_at)} · 最多 {entitlement.max_devices} 台设备</p><button className="member-link" onClick={() => setView('plans')}>查看会员权益 <ArrowUpRight size={14} /></button></div><div className="usage"><div className="usage-top"><span>套餐周期已用流量</span><b>{entitlement.data_used_gb.toFixed(1)} <small>/ {entitlement.traffic_gb} GB</small></b></div><div className="usage-bar"><span style={{ width: used + '%' }} /></div><div className="usage-foot"><span>剩余 {(entitlement.traffic_gb - entitlement.data_used_gb).toFixed(1)} GB</span><span>{used}% · 周期累计</span></div></div><div className="member-side"><span>当前连接</span><b><i /> 未连接</b><small>演示状态 · 无真实节点</small></div></section>;
}
function AuditList({ rows }: { rows?: AdminSummary['recentAudit'] }) {
  if (!rows) return <LoadingBlock />;
  if (!rows.length) return <Empty title="暂无操作记录" detail="管理员操作会记录在这里。" />;
  return <div className="activity-list">{rows.map(row => <div className="activity-row" key={row.id}><span className="activity-icon"><Settings2 size={15} /></span><span className="activity-copy"><b>{auditLabel(row.action)}</b><small>{row.actor} · {row.target_type} · {row.target_id.slice(0, 12)}</small></span><time>{shortDate(row.created_at)}</time></div>)}</div>;
}
function auditLabel(action: string) {
  return ({ 'order.created': '创建测试订单', 'order.cancelled': '取消测试订单', 'order.sandbox_paid': '模拟沙箱付款', 'order.payment_review': '记录待核验付款事件', 'node.status_changed': '更新测试节点状态', 'node.created': '创建演示节点', 'node.lifecycle_changed': '切换节点生命周期', 'subscription.created': '创建 Clash 订阅', 'subscription.rotated': '轮换订阅凭证', 'subscription.revoked': '撤销订阅凭证' } as Record<string, string>)[action] ?? action;
}
function OrderRows({ rows }: { rows?: Overview['recentOrders'] }) {
  if (!rows) return <LoadingBlock />;
  if (!rows.length) return <Empty title="还没有订单记录" detail="订单状态会显示在这里。" />;
  return <div className="activity-list">{rows.map(row => <div className="activity-row" key={row.id}><span className="activity-icon"><CreditCard size={15} /></span><span className="activity-copy"><b>{row.plan_name}</b><small>{row.id} · 本地演示记录</small></span><b className="order-amount">{money(row.total_cents)}</b><StatusPill status={orderStatusText[row.status] ?? row.status} tone={orderTone(row.status)} /></div>)}</div>;
}
function PlatformStatus({ summary, setView }: { summary: AdminSummary | null; setView: (view: string) => void }) {
  return <div className="quick-actions"><QuickAction icon={Server} label="测试节点" detail={summary ? summary.availableNodes + ' 条标记可用 · 健康未接入' : '载入中'} onClick={() => setView('nodes')} /><QuickAction icon={Link2} label="订阅凭证" detail={summary ? summary.activeSubscriptions + ' 个有效链接' : '载入中'} onClick={() => setView('subscriptions')} /><QuickAction icon={ArrowUpRight} label="代理结算" detail={summary ? money(summary.pendingCommissionCents) + ' 待结算演示金额' : '载入中'} onClick={() => setView('commissions')} /></div>;
}
function QuickAction({ icon: Icon, label, detail, onClick }: { icon: typeof Users; label: string; detail: string; onClick: () => void }) {
  return <button className="quick-action" onClick={onClick}><span className="quick-icon"><Icon size={16} /></span><span><b>{label}</b><small>{detail}</small></span><ArrowUpRight size={14} /></button>;
}
function ServiceItem({ label, status, icon: Icon }: { label: string; status: string; icon: typeof Users }) {
  return <div className="service-item"><span className="service-icon"><Icon size={16} /></span><span><b>{label}</b><small><i /> {status}</small></span></div>;
}

function SubscriptionsPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Subscription[] | null>(null);
  const [createdUrl, setCreatedUrl] = useState('');
  const [showSubscription, setShowSubscription] = useState(false);
  const [rotateTarget, setRotateTarget] = useState<Subscription | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<Subscription[]>('/api/v1/me/subscriptions').then(setRows).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load, refresh]);
  const create = async () => {
    setBusy(true);
    try { const item = await api<Subscription>('/api/v1/me/subscriptions', { method: 'POST' }); setCreatedUrl(item.url ?? ''); setShowSubscription(true); notify('订阅链接已生成；会员权益生效后可获取线路配置'); await load(); }
    catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const revoke = async (row: Subscription) => {
    try { await api('/api/v1/me/subscriptions/' + row.id + '/revoke', { method: 'POST' }); notify('订阅凭证已撤销'); await load(); }
    catch (error) { notify((error as Error).message); }
  };
  const rotate = async (row: Subscription) => {
    setBusy(true);
    try { const next = await api<Subscription>('/api/v1/me/subscriptions/' + row.id + '/rotate', { method: 'POST' }); setCreatedUrl(next.url ?? ''); setRotateTarget(null); notify('旧链接已撤销，新链接可随时复制'); await load(); }
    catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const copy = async (value: string) => {
    const absolute = new URL(value, window.location.origin).toString();
    try { await navigator.clipboard.writeText(absolute); notify('已复制订阅地址'); }
    catch { setCreatedUrl(absolute); notify('无法访问剪贴板，请手动复制'); }
  };
  const showQr = (url: string) => {
    setCreatedUrl(url);
    setRotateTarget(null);
    setShowSubscription(true);
  };
  const current = rows?.find(row => row.status === 'active' && row.url);
  return <div className="page-stack">
    <PageHeading eyebrow="账户连接" title="订阅与设备" subtitle="购买后自动准备固定订阅地址；后续续费继续使用同一地址。也可以在购买前获取。" action={<div className="subscription-actions"><button className="secondary-button" disabled={!current?.url} onClick={() => current?.url && void copy(current.url)}><Clipboard size={15} />复制链接</button><button className="primary-button" disabled={busy || !rows} onClick={() => { if (current?.url) showQr(current.url); else { setCreatedUrl(''); setRotateTarget(null); setShowSubscription(true); } }}><QrCode size={16} />{current ? '扫码导入' : '获取二维码'}</button></div>} />
    <div className="notice notice-warning"><ShieldCheck size={16} /><span><b>权限说明</b> 订阅链接可预先创建；客户端仅会在当前会员权益有效且线路可用时取得配置。本地预览使用测试节点，不提供真实连接。</span></div>
    {showSubscription && <Modal title={createdUrl ? '固定订阅链接' : rotateTarget ? '轮换订阅链接' : '获取固定订阅链接'} detail={createdUrl ? '可随时在此页面重新复制；请勿公开分享' : rotateTarget ? '旧链接将立即失效' : '同一账户重复获取时会返回现有链接'} onClose={() => { if (busy) return; setShowSubscription(false); setCreatedUrl(''); setRotateTarget(null); }}>
      {createdUrl ? <div className="subscription-qr-layout"><div className="subscription-qr"><QRCodeSVG value={absoluteSubscriptionUrl(createdUrl)} size={196} level="M" title="固定订阅二维码" /><b>用手机客户端扫描导入</b><span>{subscriptionQrNote()}</span></div><div className="secret-result"><div><span className="eyebrow">固定订阅 · 续费沿用</span><code>{absoluteSubscriptionUrl(createdUrl)}</code></div><div className="subscription-import-actions"><button className="secondary-button" onClick={() => void copy(createdUrl)}><Clipboard size={15} /> 复制链接</button><a className="secondary-button" href={clashVergeImportUrl(createdUrl)}><ArrowUpRight size={15} /> 导入 Clash Verge Rev</a></div></div></div> : <><p className="modal-copy">{rotateTarget ? '确认轮换后，当前订阅链接立即失效。请更新已连接的客户端。' : '订阅链接可以提前获取；会员权益生效且有可用线路后，兼容客户端即可从此地址获取配置。'}</p><div className="modal-actions"><button className="secondary-button" disabled={busy} onClick={() => { setShowSubscription(false); setRotateTarget(null); }}>取消</button><button className="primary-button" disabled={busy} onClick={() => void (rotateTarget ? rotate(rotateTarget) : create())}>{rotateTarget ? '确认轮换' : '获取链接'}</button></div></>}
    </Modal>}
    <section className="panel"><SectionTitle title="订阅凭证" detail={rows ? rows.length + ' 条记录' : '读取中'} action={<button className="secondary-button" onClick={() => void load()}><RefreshCw size={14} /> 刷新</button>} />
      {!rows ? <LoadingBlock /> : !rows.length ? <Empty title="尚未创建订阅" detail="获取固定链接后，可随时在这里复制或轮换地址。" /> : <div className="table-wrap"><table><thead><tr><th>名称</th><th>凭证状态</th><th>创建时间</th><th>配置权限</th><th>最近访问</th><th>操作</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><span className="table-main"><Link2 size={15} />{row.name}</span><small className="table-sub">{row.id.slice(0, 14)}</small></td><td><StatusPill status={row.status === 'active' ? '可用凭证' : '已撤销'} tone={row.status === 'active' ? 'green' : 'gray'} /></td><td>{shortDate(row.created_at)}</td><td>需有效会员权益</td><td>{shortDate(row.last_used_at)}</td><td><div className="row-actions">{row.status === 'active' && <>{row.url && <><button className="text-button" aria-label="复制固定订阅链接" title="复制固定订阅链接" onClick={() => void copy(row.url!)}><Clipboard size={14} />复制</button><button className="text-button" aria-label="显示订阅二维码" title="显示订阅二维码" onClick={() => showQr(row.url!)}><QrCode size={14} />二维码</button></>}<button className="text-button" onClick={() => { setCreatedUrl(''); setRotateTarget(row); setShowSubscription(true); }}>轮换</button><button className="text-button danger-text" onClick={() => void revoke(row)}>撤销</button></>}</div></td></tr>)}</tbody></table></div>}
    </section>
    <div className="two-column"><section className="panel"><SectionTitle title="连接客户端" detail="外部客户端兼容性仍需逐版本验证" /><div className="client-list"><div><span className="client-icon"><Monitor size={17} /></span><span><b>Clash Verge Rev</b><small>Mihomo 内核 · 计划首批验证</small></span><StatusPill status="待验证" tone="amber" /></div><div><span className="client-icon"><Smartphone size={17} /></span><span><b>Clash Mi</b><small>应用名称与平台待确认</small></span><StatusPill status="待确认" tone="gray" /></div></div></section><section className="panel"><SectionTitle title="使用状态" detail="权限与接入边界" /><div className="policy-list"><p><ShieldCheck size={15} /> 令牌以摘要校验、加密密文保存。</p><p><RefreshCw size={15} /> 轮换会立即废止旧链接。</p><p><Activity size={15} /> 用量由服务端策略执行，不依赖配置文案。</p><p><Globe2 size={15} /> 本地测试节点使用保留地址，不提供真实连接。</p></div></section></div>
  </div>;
}

function ClientDownloadPage({ refresh, setView, notify }: { refresh: number; setView: (view: string) => void; notify: (value: string) => void }) {
  const [platformId, setPlatformId] = useState<string>('android');
  const [clients, setClients] = useState<ClientCatalogItem[] | null>(null);
  const platform = clientPlatforms.find(item => item.id === platformId) ?? clientPlatforms[0];
  const platformClients = clients?.filter(item => item.platform === platform.id) ?? [];
  const PlatformIcon = platform.icon;

  useEffect(() => {
    api<ClientCatalogItem[]>('/api/v1/me/clients').then(setClients).catch(error => {
      notify((error as Error).message);
      setClients([]);
    });
  }, [refresh, notify]);

  const compatibility = (status: ClientCompatibility) => ({
    pending: { label: '待验证', tone: 'amber' },
    verified: { label: '已验证', tone: 'green' },
    maintenance: { label: '维护中', tone: 'amber' },
    unsupported: { label: '暂不支持', tone: 'gray' },
  } as const)[status];

  return <div className="page-stack client-guide">
    <PageHeading eyebrow="设备接入" title="客户端下载" subtitle="按设备选择客户端，并按照步骤导入迅连宝订阅。" action={<button className="primary-button" onClick={() => setView('subscriptions')}><QrCode size={15} /> 扫码导入</button>} />
    <div className="notice notice-neutral"><ShieldCheck size={16} /><span><b>本地开发预览</b> 当前订阅接口仅生成 Mihomo / Clash YAML 示例；节点仍为保留地址，不能建立真实连接。其他客户端格式需逐版本适配与验证。</span></div>
    <div className="client-platform-tabs" role="tablist" aria-label="选择设备平台">
      {clientPlatforms.map(item => {
        const Icon = item.icon;
        return <button key={item.id} type="button" role="tab" aria-selected={platformId === item.id} onClick={() => setPlatformId(item.id)}><Icon size={16} />{item.label}</button>;
      })}
    </div>
    <section className="client-catalog-section" aria-labelledby="client-platform-title">
      <div className="client-catalog-heading"><div><span className="eyebrow">{clients ? platformClients.length + ' 个客户端选项' : '读取中'}</span><h2 id="client-platform-title"><PlatformIcon size={21} />{platform.label}</h2></div><span className="client-release-status">下载与兼容状态以目录配置为准</span></div>
      <div className="client-download-grid">
        {!clients ? <LoadingBlock /> : !platformClients.length ? <Empty title="暂无上架客户端" detail="管理员配置并上架后会显示在这里。" /> : platformClients.map(client => {
          const status = compatibility(client.compatibility_status);
          return <article className="client-download-card" key={client.id}>
            <div className="client-download-head"><span className="client-icon"><PlatformIcon size={18} /></span><div><h3>{client.name}</h3><p>{client.version ? '版本 ' + client.version : '版本待配置'}</p></div><StatusPill status={status.label} tone={status.tone} /></div>
            <p className="client-download-detail">{client.description || '暂无说明。'}</p>
            <div className="client-format-label">{['Clash Mi', 'Clash Verge Rev', 'FlClash', 'Mihomo'].includes(client.name) ? '订阅格式：Clash / Mihomo YAML · 真实连接待验证' : '订阅格式：待适配验证'}</div>
            {client.qr_code_url && <a className="client-qr-link" href={client.qr_code_url} target="_blank" rel="noopener noreferrer"><img src={client.qr_code_url} alt={client.name + ' 下载二维码'} loading="lazy" />查看下载二维码</a>}
            <div className="client-entry-links">
              {client.download_url ? <a className="secondary-button" href={client.download_url} target="_blank" rel="noopener noreferrer"><ArrowDownToLine size={15} />下载客户端</a> : <button className="secondary-button" type="button" disabled title="下载地址尚未配置"><ArrowDownToLine size={15} />下载地址待配置</button>}
              {client.tutorial_url && <a className="text-button" href={client.tutorial_url} target="_blank" rel="noopener noreferrer">查看教程 <ArrowUpRight size={14} /></a>}
            </div>
          </article>;
        })}
      </div>
    </section>
    <section className="client-setup-section" aria-labelledby="client-setup-title">
      <div className="client-catalog-heading"><div><span className="eyebrow">连接流程</span><h2 id="client-setup-title">导入订阅</h2></div><button className="text-button" onClick={() => setView('subscriptions')}>管理订阅 <ArrowUpRight size={14} /></button></div>
      <ol className="client-setup-steps">
        <li><span>01</span><div><b>安装客户端</b><p>从客户端官方渠道安装对应平台的应用，并确认版本与系统兼容。</p></div></li>
        <li><span>02</span><div><b>获取固定订阅地址</b><p>首次购买后自动准备；未购买时也可提前获取，后续续费沿用同一地址。</p></div></li>
        <li><span>03</span><div><b>扫码或粘贴订阅</b><p>Clash Mi 等客户端选择扫码导入；桌面客户端可复制固定地址后添加远程订阅。</p></div></li>
        <li><span>04</span><div><b>更新并检查连接</b><p>当前只验证本地格式流程；需接入隔离测试节点后才能确认真实线路连接。</p></div></li>
      </ol>
    </section>
    <section className="client-help-section" aria-labelledby="client-help-title">
      <div className="client-catalog-heading"><div><span className="eyebrow">问题排查</span><h2 id="client-help-title">连接前检查</h2></div></div>
      <details><summary>客户端更新后没有线路</summary><p>确认会员权益已经生效，再手动更新订阅。权益未生效、节点维护或本地演示环境都可能导致配置暂不可用。</p></details>
      <details><summary>客户端提示配置格式不兼容</summary><p>确认选择了与设备匹配的客户端，并检查该版本对 Mihomo 或 Clash 订阅格式的支持情况；当前兼容性仍在验证中。</p></details>
      <details><summary>仍然无法连接</summary><p>记录设备系统版本、客户端名称和版本、发生时间及提示内容，然后在帮助工单中提交。请勿附上密码或完整订阅地址。</p></details>
    </section>
  </div>;
}

function PlansPage({ role, refresh, notify, onDataChange }: { role: User['role']; refresh: number; notify: (value: string) => void; onDataChange: () => void }) {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [account, setAccount] = useState<Overview | null>(null);
  const [selection, setSelection] = useState<{ plan: Plan; key: string } | null>(null);
  const [orderId, setOrderId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => {
    api<Plan[]>('/api/v1/catalog/plans').then(setPlans).catch(error => notify((error as Error).message));
    if (role === 'member') api<Overview>('/api/v1/me/overview').then(setAccount).catch(error => notify((error as Error).message));
  }, [refresh, role, notify]);
  const create = async () => {
    if (!selection || busy) return;
    setBusy(true); setError('');
    try {
      const order = await api<OrderDetail>('/api/v1/me/orders', { method: 'POST', headers: { 'Idempotency-Key': selection.key }, body: JSON.stringify({ plan_id: selection.plan.id }) });
      setSelection(null); setOrderId(order.id); notify('测试订单已创建');
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  };
  return <div className="page-stack"><PageHeading eyebrow="套餐中心" title="套餐权益" subtitle="查看套餐期限、流量和设备数量。" />{role === 'member' && <section className="vip-account-summary" aria-label="当前会员账户"><div><span>当前账号</span><b>{account?.user.email ?? '读取中'}</b></div><div><span>会员等级</span><b>{account?.entitlement?.plan_name ?? '尚未开通'}</b></div><div><span>到期时间</span><b>{account?.entitlement ? dateTime(account.entitlement.ends_at) : '—'}</b></div><button className="text-button" onClick={onDataChange}><RefreshCw size={14} />刷新状态</button></section>}<div className="notice notice-neutral"><CreditCard size={16} /><span>本地沙箱演示：确认套餐后可模拟付款，系统会自动准备固定订阅并刷新会员到期时间；不会产生真实扣款。</span></div>
    <section className="panel"><SectionTitle title="可选套餐" detail={plans ? plans.length + ' 种方案' : '载入中'} />{!plans ? <LoadingBlock /> : !plans.length ? <Empty title="暂无上架套餐" detail="套餐上架后会显示在这里。" /> : <div className="plan-grid">{plans.map(plan => <div className="plan-row" key={plan.id}><div className="plan-period"><span className="plan-icon"><BadgeCheck size={17} /></span><span><b>{plan.name}</b><small>{plan.period_label}有效</small></span></div><div><b>{money(plan.price_cents)}</b><small>套餐价格</small></div><div><b>{plan.traffic_gb} GB</b><small>套餐流量</small></div><div><b>{plan.max_devices} 台</b><small>同时在线</small></div>{role === 'member' ? <button className="secondary-button" onClick={() => { setError(''); setSelection({ plan, key: crypto.randomUUID() }); }}>选择套餐</button> : <StatusPill status="会员端可测试下单" tone="gray" />}</div>)}</div>}</section>
    <section className="panel"><SectionTitle title="权益一致性" detail="唯一事实源设计" /><div className="service-strip"><ServiceItem label="官网展示" status="共用套餐 API" icon={Globe2} /><ServiceItem label="自有 App" status="共用账户权益" icon={Smartphone} /><ServiceItem label="Clash 订阅" status="服务端校验" icon={Link2} /><ServiceItem label="运营后台" status="审计可查" icon={ShieldCheck} /></div></section>
    {selection && <Modal title="确认套餐" detail="确认所选套餐并继续付款" onClose={() => { if (!busy) setSelection(null); }}>
      <dl className="detail-grid"><div><dt>套餐</dt><dd>{selection.plan.name}</dd></div><div><dt>金额</dt><dd>{money(selection.plan.price_cents)}</dd></div><div><dt>有效期</dt><dd>{selection.plan.duration_days} 天</dd></div><div><dt>套餐流量</dt><dd>{selection.plan.traffic_gb} GB</dd></div></dl>
      <div className="notice notice-neutral"><CreditCard size={16} /><span>本地沙箱会创建测试订单；下一步可模拟支付并检查会员到期时间与固定订阅链接。</span></div>
      {error && <p className="order-error" role="alert">{error}</p>}
      <div className="modal-actions"><button className="secondary-button" disabled={busy} onClick={() => setSelection(null)}>返回</button><button className="primary-button" disabled={busy} onClick={() => void create()}>{busy ? '提交中…' : '创建测试订单'}</button></div>
    </Modal>}
    {orderId && <OrderDialog id={orderId} role={role} onClose={() => setOrderId(null)} onChange={onDataChange} notify={notify} />}
  </div>;
}

function AdminPlansPage({ notify }: { notify: (value: string) => void }) {
  const blank = { name: '', period_label: '', duration_days: '30', price_yuan: '29.80', traffic_gb: '100', max_devices: '2' };
  const [rows, setRows] = useState<ManagedPlan[] | null>(null);
  const [planModal, setPlanModal] = useState<{ mode: 'create' } | { mode: 'edit' | 'detail'; row: ManagedPlan } | null>(null);
  const [form, setForm] = useState(blank);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<ManagedPlan[]>('/api/v1/admin/plans').then(setRows).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load]);
  const edit = (row: ManagedPlan) => {
    setForm({ name: row.name, period_label: row.period_label, duration_days: String(row.duration_days), price_yuan: (row.price_cents / 100).toFixed(2), traffic_gb: String(row.traffic_gb), max_devices: String(row.max_devices) });
    setPlanModal({ mode: 'edit', row });
  };
  const createPlan = () => { setForm(blank); setPlanModal({ mode: 'create' }); };
  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!planModal || planModal.mode === 'detail') return;
    setBusy(true);
    try {
      const payload = { name: form.name.trim(), period_label: form.period_label.trim(), duration_days: Number(form.duration_days), price_cents: Math.round(Number(form.price_yuan) * 100), traffic_gb: Number(form.traffic_gb), max_devices: Number(form.max_devices) };
      const familyId = planModal.mode === 'edit' ? planModal.row.family_id : null;
      await api('/api/v1/admin/plans' + (familyId ? '/' + encodeURIComponent(familyId) : ''), { method: familyId ? 'PUT' : 'POST', body: JSON.stringify(payload) });
      notify(familyId ? '新版本已发布，历史权益保持原值' : '套餐已创建');
      setPlanModal(null);
      setForm(blank);
      await load();
    } catch (error) { notify((error as Error).message); } finally { setBusy(false); }
  };
  const changeStatus = async (row: ManagedPlan) => {
    try {
      await api('/api/v1/admin/plans/' + encodeURIComponent(row.family_id) + '/status', { method: 'PATCH', body: JSON.stringify({ active: !row.active }) });
      notify(row.active ? '套餐已下架' : '套餐已上架');
      await load();
    } catch (error) { notify((error as Error).message); }
  };
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="套餐管理" subtitle="设置价格、期限、流量和设备数；修改套餐会创建新版本，已有订单和会员权益仍引用旧版本。" action={<button className="primary-button" onClick={createPlan}><CreditCard size={15} /> 创建套餐</button>} />
    <section className="panel"><SectionTitle title="套餐列表" detail={rows ? rows.length + ' 种套餐' : '读取中'} />
      {!rows ? <LoadingBlock /> : !rows.length ? <Empty title="暂无套餐" detail="点击右上角创建第一个套餐。" /> : <div className="table-wrap"><table><thead><tr><th>套餐</th><th>版本</th><th>价格</th><th>期限</th><th>流量</th><th>设备数</th><th>状态</th><th>操作</th></tr></thead><tbody>{rows.map(row => <tr key={row.family_id}><td>{row.name}</td><td>v{row.version}</td><td>{money(row.price_cents)}</td><td>{row.duration_days} 天</td><td>{row.traffic_gb} GB</td><td>{row.max_devices} 台</td><td><StatusPill status={row.active ? '已上架' : '已下架'} tone={row.active ? 'green' : 'gray'} /></td><td><div className="row-actions"><button className="text-button" onClick={() => setPlanModal({ mode: 'detail', row })}>详情</button><button className="text-button" onClick={() => edit(row)}>编辑</button><button className="text-button" onClick={() => void changeStatus(row)}>{row.active ? '下架' : '上架'}</button></div></td></tr>)}</tbody></table></div>}
    </section>
    {planModal && <Modal title={planModal.mode === 'create' ? '创建套餐' : planModal.mode === 'edit' ? '编辑套餐 · 发布新版本' : '套餐详情'} detail={planModal.mode === 'detail' ? '当前版本与权益配置' : '仅影响本地演示目录'} onClose={() => setPlanModal(null)} wide={planModal.mode !== 'detail'}>
      {planModal.mode === 'detail' ? <><dl className="detail-grid">
        <div><dt>套餐名称</dt><dd>{planModal.row.name}</dd></div><div><dt>周期说明</dt><dd>{planModal.row.period_label}</dd></div>
        <div><dt>当前版本</dt><dd>v{planModal.row.version}</dd></div><div><dt>上架状态</dt><dd>{planModal.row.active ? '已上架' : '已下架'}</dd></div>
        <div><dt>有效期限</dt><dd>{planModal.row.duration_days} 天</dd></div><div><dt>价格</dt><dd>{money(planModal.row.price_cents)}</dd></div>
        <div><dt>套餐流量</dt><dd>{planModal.row.traffic_gb} GB</dd></div><div><dt>同时在线设备</dt><dd>{planModal.row.max_devices} 台</dd></div>
      </dl><div className="modal-actions"><button className="secondary-button" onClick={() => setPlanModal(null)}>关闭</button><button className="primary-button" onClick={() => edit(planModal.row)}>编辑套餐</button></div></> :
      <form className="plan-editor" onSubmit={save}>
        <label>套餐名称<input required maxLength={60} value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} /></label>
        <label>周期说明<input required maxLength={30} value={form.period_label} onChange={event => setForm({ ...form, period_label: event.target.value })} /></label>
        <label>有效天数<input required type="number" min="1" max="3650" step="1" value={form.duration_days} onChange={event => setForm({ ...form, duration_days: event.target.value })} /></label>
        <label>价格（元）<input required type="number" min="0" max="999999" step="0.01" value={form.price_yuan} onChange={event => setForm({ ...form, price_yuan: event.target.value })} /></label>
        <label>流量（GB）<input required type="number" min="1" max="100000" step="0.1" value={form.traffic_gb} onChange={event => setForm({ ...form, traffic_gb: event.target.value })} /></label>
        <label>同时在线设备<input required type="number" min="1" max="100" step="1" value={form.max_devices} onChange={event => setForm({ ...form, max_devices: event.target.value })} /></label>
        <div className="plan-editor-actions"><button type="button" className="secondary-button" onClick={() => setPlanModal(null)}>取消</button><button className="primary-button" disabled={busy}>{planModal.mode === 'edit' ? '发布新版本' : '创建套餐'}</button></div>
      </form>}
    </Modal>}
  </div>;
}

function OrdersPage({ role, refresh, notify, onDataChange }: { role: User['role']; refresh: number; notify: (value: string) => void; onDataChange: () => void }) {
  const [rows, setRows] = useState<OrderSummary[] | null>(null);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [error, setError] = useState('');
  const sequence = useRef(0);
  const base = '/api/v1/' + (role === 'admin' ? 'admin' : 'me') + '/orders';
  const load = useCallback(async (signal?: AbortSignal) => {
    const current = ++sequence.current;
    try {
      const result = await apiEnvelope<OrderSummary[]>(base + '?page=' + page + '&per_page=20' + (status ? '&status=' + status : ''), { signal });
      if (signal?.aborted || current !== sequence.current) return;
      setRows(result.data); setMeta(result.meta ?? null); setError('');
    } catch (error) { if (!signal?.aborted && current === sequence.current) setError((error as Error).message); }
  }, [base, page, status]);
  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal);
    const timer = window.setInterval(() => void load(controller.signal), 60000);
    return () => { controller.abort(); ++sequence.current; window.clearInterval(timer); };
  }, [load, refresh]);
  return <div className="page-stack"><PageHeading eyebrow="订单中心" title={role === 'admin' ? '订单管理' : '订单记录'} subtitle={role === 'admin' ? '查看用户订单、套餐快照与状态记录。' : '查看自己的订单与处理记录。'} />
    <div className="notice notice-neutral"><CreditCard size={16} /><span>测试订单不参与真实支付、会员发放或佣金计算；已支付样例用于展示历史记录。</span></div>
    <section className="panel"><SectionTitle title="订单列表" detail={meta ? meta.total + ' 条记录' : '读取中'} action={<div className="order-toolbar"><label>订单状态<select aria-label="订单状态" value={status} onChange={e => { setStatus(e.target.value); setPage(1); setRows(null); }}><option value="">全部状态</option>{Object.entries(orderStatusText).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><button className="secondary-button" onClick={() => void load()}><RefreshCw size={14} /> 刷新</button></div>} />
      {error && <p className="order-error" role="alert">{error}</p>}
      {!rows ? (error ? <Empty title="订单读取失败" detail="请点击刷新重试。" /> : <LoadingBlock />) : !rows.length ? <Empty title="没有符合条件的订单" detail="可调整状态筛选；会员可前往套餐权益创建测试订单。" /> : <div className="table-wrap"><table><thead><tr><th>订单号</th>{role === 'admin' && <th>用户</th>}<th>套餐</th><th>金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><code title={row.id}>{row.id.slice(0, 18)}</code></td>{role === 'admin' && <td>{row.user_name}</td>}<td>{row.plan_name}</td><td>{money(row.total_cents)}</td><td><StatusPill status={orderStatusText[row.status] ?? row.status} tone={orderTone(row.status)} /></td><td>{dateTime(row.created_at)}</td><td><button className="text-button" onClick={() => setSelectedId(row.id)}>详情</button></td></tr>)}</tbody></table></div>}
      {meta && meta.total_pages > 1 && <div className="support-pagination"><button className="text-button" disabled={page === 1} onClick={() => setPage(page - 1)}>上一页</button><span>{page} / {meta.total_pages}</span><button className="text-button" disabled={page >= meta.total_pages} onClick={() => setPage(page + 1)}>下一页</button></div>}
    </section>
    {selectedId && <OrderDialog id={selectedId} role={role} onClose={() => setSelectedId(null)} onChange={() => { onDataChange(); void load(); }} notify={notify} />}
  </div>;
}

function OrderDialog({ id, role, onClose, onChange, notify }: { id: string; role: User['role']; onClose: () => void; onChange: () => void; notify: (value: string) => void }) {
  const [detail, setDetail] = useState<OrderDetail | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [confirmPayment, setConfirmPayment] = useState(false);
  const [subscriptionUrl, setSubscriptionUrl] = useState('');
  const sequence = useRef(0);
  const base = '/api/v1/' + (role === 'admin' ? 'admin' : 'me') + '/orders/';
  useEffect(() => {
    if (busy) return;
    const controller = new AbortController();
    const load = async () => {
      const current = ++sequence.current;
      try {
        const result = await api<OrderDetail>(base + id, { signal: controller.signal });
        if (current === sequence.current && !controller.signal.aborted) { setDetail(result); setError(''); }
      } catch (error) { if (!controller.signal.aborted && current === sequence.current) setError((error as Error).message); }
    };
    void load();
    const timer = window.setInterval(() => { if (!busy) void load(); }, 30000);
    return () => { controller.abort(); ++sequence.current; window.clearInterval(timer); };
  }, [base, id, busy]);
  const cancel = async () => {
    if (busy) return;
    setBusy(true); setError(''); ++sequence.current;
    try {
      const updated = await api<OrderDetail>('/api/v1/me/orders/' + id + '/cancel', { method: 'POST' });
      setDetail(updated); setConfirmCancel(false); onChange(); notify('测试订单已取消');
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  };
  const copySubscription = async () => {
    try {
      await navigator.clipboard.writeText(new URL(subscriptionUrl, window.location.origin).toString());
      notify('已复制固定订阅地址');
    } catch {
      notify('剪贴板不可用，请手动选择上方链接');
    }
  };
  const simulatePayment = async () => {
    if (busy) return;
    setBusy(true); setError(''); ++sequence.current;
    try {
      const payment = await api<{ subscription: { url: string } | null }>('/api/v1/me/orders/' + id + '/sandbox-payment', { method: 'POST' });
      setSubscriptionUrl(payment.subscription?.url ?? '');
      const updated = await api<OrderDetail>(base + id);
      setDetail(updated); setConfirmPayment(false); onChange(); notify('本地测试付款已记录，固定订阅链接已准备好');
    } catch (error) {
      setError((error as Error).message);
      try { setDetail(await api<OrderDetail>(base + id)); } catch { /* Keep the last visible order state if refresh also fails. */ }
    }
    finally { setBusy(false); }
  };
  return <Modal title="订单详情" detail="套餐快照与状态记录" onClose={() => { if (!busy) onClose(); }} wide>
    {error && <p className="order-error" role="alert">{error}</p>}
    {!detail ? (error ? <Empty title="无法读取订单" detail="请关闭弹框后重试。" /> : <LoadingBlock />) : <>
      <dl className="detail-grid"><div className="order-full"><dt>订单号</dt><dd><code>{detail.id}</code></dd></div><div><dt>订单状态</dt><dd><StatusPill status={orderStatusText[detail.status] ?? detail.status} tone={orderTone(detail.status)} /></dd></div><div><dt>订单金额</dt><dd>{money(detail.total_cents)}</dd></div><div><dt>套餐版本</dt><dd>{detail.plan_name} · v{detail.plan_snapshot.version}</dd></div><div><dt>权益快照</dt><dd>{detail.plan_snapshot.duration_days} 天 / {detail.plan_snapshot.traffic_gb} GB / {detail.plan_snapshot.max_devices} 台</dd></div><div><dt>创建时间</dt><dd>{dateTime(detail.created_at)}</dd></div><div><dt>支付期限</dt><dd>{dateTime(detail.expires_at)}</dd></div>{role === 'admin' && <div className="order-full"><dt>下单用户</dt><dd>{detail.user_name} · {detail.user_email}</dd></div>}</dl>
      <div className="notice notice-neutral"><CreditCard size={16} /><span>{detail.payment?.status === 'accepted_test' ? '本地沙箱模拟事件已记录，不代表真实渠道付款。此测试权益不产生真实服务或佣金。' : detail.status === 'paid_test' ? '这是预置的已支付样例，并未通过支付渠道扣款。' : '真实支付尚未接入；本地沙箱模拟不会扣款或生成佣金。'}</span></div>
      {role === 'member' && subscriptionUrl && detail.payment?.status === 'accepted_test' && <div className="subscription-qr-layout order-subscription-access"><div className="subscription-qr"><QRCodeSVG value={absoluteSubscriptionUrl(subscriptionUrl)} size={164} level="M" title="Clash 客户端订阅二维码" /><b>手机客户端扫码导入</b><span>{subscriptionQrNote()}</span></div><div className="secret-result"><div><span className="eyebrow">固定订阅链接 · 续费沿用</span><code>{absoluteSubscriptionUrl(subscriptionUrl)}</code></div><div className="subscription-import-actions"><button className="secondary-button" onClick={() => void copySubscription()}><Clipboard size={15} /> 复制链接</button><a className="secondary-button" href={clashVergeImportUrl(subscriptionUrl)}><ArrowUpRight size={15} /> 导入 Clash Verge Rev</a></div></div></div>}
      {detail.payment && <dl className="detail-grid order-payment"><div><dt>沙箱事件</dt><dd>{detail.payment.status === 'accepted_test' ? '已模拟接受' : '待核验'}</dd></div><div><dt>记录金额</dt><dd>{money(detail.payment.amount_cents)} {detail.payment.currency}</dd></div><div className="order-full"><dt>事件编号</dt><dd><code>{detail.payment.id}</code></dd></div></dl>}
      {detail.entitlement_grant && <div className="order-grant"><BadgeCheck size={16} /><span>本地测试权益：{dateTime(detail.entitlement_grant.starts_at)} 至 {dateTime(detail.entitlement_grant.ends_at)}</span></div>}
      <section className="order-history"><h3>状态记录</h3>{detail.events.length ? <ol>{detail.events.map(event => <li key={event.id}><span><b>{event.from_status ? (orderStatusText[event.from_status] ?? event.from_status) + ' → ' : ''}{orderStatusText[event.to_status] ?? event.to_status}</b><small>{event.actor_name ?? '系统'} · {dateTime(event.created_at)}</small></span></li>)}</ol> : <p>历史样例暂无事件记录。</p>}{detail.status === 'expired_test' && detail.events.at(-1)?.to_status === 'pending_test' && <p>支付期限已到，超时事件将在到期任务运行后记录。</p>}</section>
      {role === 'member' && detail.is_test && detail.status === 'pending_test' && <div className="modal-actions"><button className="secondary-button" disabled={busy} onClick={() => setConfirmCancel(value => !value)}>取消测试订单</button><button className="primary-button" disabled={busy} onClick={() => setConfirmPayment(value => !value)}>模拟沙箱付款</button></div>}
      {confirmCancel && <div className="order-cancel-confirm"><p>确认取消此测试订单？取消后可重新选择套餐下单。</p><div className="modal-actions"><button className="secondary-button" disabled={busy} onClick={() => setConfirmCancel(false)}>保留订单</button><button className="primary-button" disabled={busy} onClick={() => void cancel()}>{busy ? '处理中…' : '确认取消订单'}</button></div></div>}
      {confirmPayment && <div className="order-payment-confirm"><p>只在本机写入模拟付款事件与测试权益。不会向支付渠道请求、不会扣款，也不代表真实支付完成。</p><div className="modal-actions"><button className="secondary-button" disabled={busy} onClick={() => setConfirmPayment(false)}>返回</button><button className="primary-button" disabled={busy} onClick={() => void simulatePayment()}>{busy ? '处理中…' : '确认模拟付款'}</button></div></div>}
    </>}
  </Modal>;
}

const ticketStatusText: Record<TicketStatus, string> = { open: '待处理', in_progress: '处理中', resolved: '已解决' };
const ticketCategoryText: Record<string, string> = { connection: '连接问题', subscription: '订阅配置', payment: '订单与付款', account: '账户安全', other: '其他问题' };

function SupportPage({ role, refresh, notify }: { role: User['role']; refresh: number; notify: (value: string) => void }) {
  const base = '/api/v1/' + (role === 'admin' ? 'admin' : 'me') + '/tickets';
  const [rows, setRows] = useState<TicketSummary[] | null>(null);
  const [detail, setDetail] = useState<TicketDetail | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState('connection');
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [reply, setReply] = useState('');
  const [busy, setBusy] = useState(false);
  const loadList = useCallback(async () => {
    try {
      const result = await apiEnvelope<TicketSummary[]>(base + '?page=' + page + '&per_page=20');
      setRows(result.data);
      setMeta(result.meta ?? null);
    } catch (error) { notify((error as Error).message); }
  }, [base, page, notify]);
  const loadDetail = useCallback(async (id: string) => {
    try { setDetail(await api<TicketDetail>(base + '/' + id)); }
    catch (error) { setSelectedId(current => current === id ? null : current); notify((error as Error).message); }
  }, [base, notify]);
  useEffect(() => { void loadList(); }, [loadList, refresh]);
  useEffect(() => { if (selectedId) void loadDetail(selectedId); else setDetail(null); }, [selectedId, loadDetail, refresh]);
  const create = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    try {
      const created = await api<{ id: string }>('/api/v1/me/tickets', { method: 'POST', body: JSON.stringify({ category, subject, description }) });
      setSubject(''); setDescription(''); setPage(1); setDetail(null); setSelectedId(created.id); setShowCreate(false);
      if (page === 1) await loadList();
      notify('工单已创建');
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const sendReply = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!detail) return;
    setBusy(true);
    try {
      await api(base + '/' + detail.id + '/replies', { method: 'POST', body: JSON.stringify({ body: reply }) });
      setReply('');
      await Promise.all([loadList(), loadDetail(detail.id)]);
      notify('回复已发送');
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const changeStatus = async (status: TicketStatus) => {
    if (!detail) return;
    setBusy(true);
    try {
      await api(base + '/' + detail.id, { method: 'PATCH', body: JSON.stringify({ status }) });
      await Promise.all([loadList(), loadDetail(detail.id)]);
      notify('工单状态已更新');
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  return <div className="page-stack">
    <PageHeading eyebrow="服务支持" title="帮助工单" subtitle={role === 'admin' ? '查看本地会员问题并跟进处理；所有状态变更均留有审计。' : '遇到订阅、连接、订单或账户问题，可在这里提交并跟踪处理进度。'} action={role === 'member' && <button className="primary-button" onClick={() => setShowCreate(true)}>新建工单 <ArrowUpRight size={14} /></button>} />
    <div className="notice notice-warning"><ShieldCheck size={16} /><span><b>信息保护</b> 请勿在工单中填写密码、完整订阅地址或支付凭证。当前仅接收本地演示数据。</span></div>
    <section className="panel support-list"><SectionTitle title={role === 'admin' ? '会员工单' : '我的工单'} detail={meta ? '共 ' + meta.total + ' 条' : '载入中'} />
        {!rows ? <LoadingBlock /> : rows.length === 0 ? <Empty title="暂无工单" detail="新工单会显示在这里。" /> : <div className="support-tickets">{rows.map(row => <button key={row.id} className="support-ticket" onClick={() => { setDetail(null); setSelectedId(row.id); }}>
          <span><b>{row.subject}</b><small>{role === 'admin' ? row.user_name + ' · ' : ''}{ticketCategoryText[row.category]} · {shortDate(row.updated_at)}</small></span>
          <StatusPill status={ticketStatusText[row.status]} tone={row.status === 'resolved' ? 'green' : row.status === 'in_progress' ? 'amber' : 'gray'} />
        </button>)}</div>}
        {meta && meta.total_pages > 1 && <div className="support-pagination"><button className="text-button" disabled={page === 1} onClick={() => setPage(page - 1)}>上一页</button><span>{page} / {meta.total_pages}</span><button className="text-button" disabled={page >= meta.total_pages} onClick={() => setPage(page + 1)}>下一页</button></div>}
    </section>
    {showCreate && <Modal title="新建工单" detail="描述遇到的问题，方便客服跟进" onClose={() => setShowCreate(false)}>
      <form className="support-form" onSubmit={create}>
          <label>问题类别<select value={category} onChange={event => setCategory(event.target.value)}>{Object.entries(ticketCategoryText).map(([key, value]) => <option key={key} value={key}>{value}</option>)}</select></label>
          <label>标题<input value={subject} onChange={event => setSubject(event.target.value)} maxLength={100} minLength={3} placeholder="简要描述遇到的问题" required /></label>
          <label>问题描述<textarea value={description} onChange={event => setDescription(event.target.value)} maxLength={2000} minLength={10} rows={4} placeholder="请写明发生时间、系统和客户端版本、脱敏错误码" required /></label>
          <button className="primary-button" disabled={busy}>提交工单 <ArrowUpRight size={14} /></button>
      </form>
    </Modal>}
    {selectedId && <Modal title="工单详情" detail="处理记录与回复" onClose={() => { setSelectedId(null); setDetail(null); setReply(''); }} wide>
      {!detail || detail.id !== selectedId ? <LoadingBlock /> : <>
        <div className="support-detail-head"><span className="eyebrow">{ticketCategoryText[detail.category]} · {detail.id.slice(0, 8)}</span><h2>{detail.subject}</h2><div><StatusPill status={ticketStatusText[detail.status]} tone={detail.status === 'resolved' ? 'green' : detail.status === 'in_progress' ? 'amber' : 'gray'} /><small>创建于 {new Date(detail.created_at).toLocaleString('zh-CN')}</small></div></div>
        {role === 'admin' && <div className="support-actions">
          {detail.status === 'open' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('in_progress')}>开始处理</button>}
          {detail.status === 'in_progress' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('open')}>退回待处理</button>}
          {detail.status === 'resolved' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('in_progress')}>重新处理</button>}
          {detail.status !== 'resolved' && <button className="primary-button" disabled={busy} onClick={() => void changeStatus('resolved')}>标记已解决</button>}
        </div>}
        <div className="support-events">{detail.events.map(item => item.kind === 'status_changed' ? <div key={item.id} className="support-event system">{item.actor_name} 将状态从 {ticketStatusText[item.from_status!]} 改为 {ticketStatusText[item.to_status!]} · {new Date(item.created_at).toLocaleString('zh-CN')}</div> : <div key={item.id} className={'support-event' + (item.actor_role === 'admin' ? ' staff' : '')}><div><b>{item.actor_name}</b><small>{new Date(item.created_at).toLocaleString('zh-CN')}</small></div><p>{item.body}</p></div>)}</div>
        <form className="support-reply" onSubmit={sendReply}><label htmlFor="support-reply">{role === 'admin' ? '回复会员' : '补充说明'}</label><textarea id="support-reply" value={reply} onChange={event => setReply(event.target.value)} maxLength={2000} minLength={2} rows={3} placeholder="请勿填写密码或完整订阅地址" required /><button className="primary-button" disabled={busy}>发送回复 <ArrowUpRight size={14} /></button></form>
      </>}
    </Modal>}
  </div>;
}

function AdminClientsPage({ notify }: { notify: (value: string) => void }) {
  const blank = (): ClientForm => ({ platform: 'android', name: '', version: '', description: '', download_url: '', qr_code_url: '', tutorial_url: '', compatibility_status: 'pending', sort_order: '100', is_active: true });
  const [rows, setRows] = useState<ManagedClient[] | null>(null);
  const [modal, setModal] = useState<{ mode: 'create' } | { mode: 'edit' | 'detail'; row: ManagedClient } | null>(null);
  const [form, setForm] = useState<ClientForm>(blank);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<ManagedClient[]>('/api/v1/admin/clients').then(setRows).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load]);

  const edit = (row: ManagedClient) => {
    setForm({
      platform: row.platform,
      name: row.name,
      version: row.version ?? '',
      description: row.description ?? '',
      download_url: row.download_url ?? '',
      qr_code_url: row.qr_code_url ?? '',
      tutorial_url: row.tutorial_url ?? '',
      compatibility_status: row.compatibility_status,
      sort_order: String(row.sort_order),
      is_active: row.is_active,
    });
    setModal({ mode: 'edit', row });
  };
  const create = () => { setForm(blank()); setModal({ mode: 'create' }); };
  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!modal || modal.mode === 'detail') return;
    setBusy(true);
    const payload = {
      ...form,
      name: form.name.trim(),
      version: form.version.trim() || null,
      description: form.description.trim() || null,
      download_url: form.download_url.trim() || null,
      qr_code_url: form.qr_code_url.trim() || null,
      tutorial_url: form.tutorial_url.trim() || null,
      sort_order: Number(form.sort_order),
    };
    try {
      await api('/api/v1/admin/clients' + (modal.mode === 'edit' ? '/' + encodeURIComponent(modal.row.id) : ''), {
        method: modal.mode === 'edit' ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      });
      setModal(null);
      notify(modal.mode === 'edit' ? '客户端目录已更新' : '客户端目录已创建');
      await load();
    } catch (error) {
      notify((error as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const changeStatus = async (row: ManagedClient) => {
    try {
      await api('/api/v1/admin/clients/' + encodeURIComponent(row.id) + '/status', { method: 'PATCH', body: JSON.stringify({ is_active: !row.is_active }) });
      notify(row.is_active ? '客户端已下架' : '客户端已上架');
      await load();
    } catch (error) {
      notify((error as Error).message);
    }
  };
  const platformLabel = (value: ClientPlatform) => clientPlatforms.find(item => item.id === value)?.label ?? value;
  const compatibility = (value: ClientCompatibility) => ({
    pending: { label: '待验证', tone: 'amber' },
    verified: { label: '已验证', tone: 'green' },
    maintenance: { label: '维护中', tone: 'amber' },
    unsupported: { label: '暂不支持', tone: 'gray' },
  } as const)[value];

  return <div className="page-stack">
    <PageHeading eyebrow="运营管理" title="客户端目录" subtitle="维护各平台客户端、版本、下载来源与兼容验证状态。" action={<button className="primary-button" onClick={create}><Monitor size={15} /> 添加客户端</button>} />
    <div className="notice notice-neutral"><ShieldCheck size={16} /><span>客户端下载页只展示已上架条目；未配置的下载地址会保持关闭。请在实际客户端验证订阅导入后，再将兼容状态改为“已验证”。</span></div>
    <section className="panel"><SectionTitle title="客户端列表" detail={rows ? rows.length + ' 个条目' : '读取中'} />
      {!rows ? <LoadingBlock /> : !rows.length ? <Empty title="暂无客户端" detail="点击右上角添加客户端目录项。" /> : <div className="table-wrap"><table><thead><tr><th>平台</th><th>客户端 / 版本</th><th>兼容状态</th><th>下载与教程</th><th>目录状态</th><th>操作</th></tr></thead><tbody>{rows.map(row => {
        const status = compatibility(row.compatibility_status);
        const configuredLinks = [row.download_url, row.qr_code_url, row.tutorial_url].filter(Boolean).length;
        return <tr key={row.id}><td>{platformLabel(row.platform)}</td><td><b>{row.name}</b><small className="table-sub">{row.version ? '版本 ' + row.version : '版本待配置'}</small></td><td><StatusPill status={status.label} tone={status.tone} /></td><td>{configuredLinks} 项已配置</td><td><StatusPill status={row.is_active ? '已上架' : '已下架'} tone={row.is_active ? 'green' : 'gray'} /></td><td><div className="row-actions"><button className="text-button" onClick={() => setModal({ mode: 'detail', row })}>详情</button><button className="text-button" onClick={() => edit(row)}>编辑</button><button className="text-button" onClick={() => void changeStatus(row)}>{row.is_active ? '下架' : '上架'}</button></div></td></tr>;
      })}</tbody></table></div>}
    </section>
    {modal && <Modal title={modal.mode === 'create' ? '添加客户端' : modal.mode === 'edit' ? '编辑客户端' : '客户端详情'} detail={modal.mode === 'detail' ? '客户端目录配置与发布状态' : '验证后再配置公开下载来源'} onClose={() => { if (!busy) setModal(null); }} wide={modal.mode !== 'detail'}>
      {modal.mode === 'detail' ? <><dl className="detail-grid">
        <div><dt>平台</dt><dd>{platformLabel(modal.row.platform)}</dd></div><div><dt>客户端名称</dt><dd>{modal.row.name}</dd></div>
        <div><dt>版本</dt><dd>{modal.row.version || '未配置'}</dd></div><div><dt>兼容状态</dt><dd>{compatibility(modal.row.compatibility_status).label}</dd></div>
        <div><dt>目录状态</dt><dd>{modal.row.is_active ? '已上架' : '已下架'}</dd></div><div><dt>排序值</dt><dd>{modal.row.sort_order}</dd></div>
        <div><dt>说明</dt><dd>{modal.row.description || '未配置'}</dd></div>
        <div><dt>下载地址</dt><dd>{modal.row.download_url ? <a href={modal.row.download_url} target="_blank" rel="noopener noreferrer">打开下载来源</a> : '未配置'}</dd></div>
        <div><dt>二维码图片</dt><dd>{modal.row.qr_code_url ? <a href={modal.row.qr_code_url} target="_blank" rel="noopener noreferrer">打开二维码</a> : '未配置'}</dd></div>
        <div><dt>使用教程</dt><dd>{modal.row.tutorial_url ? <a href={modal.row.tutorial_url} target="_blank" rel="noopener noreferrer">打开教程</a> : '未配置'}</dd></div>
      </dl><div className="modal-actions"><button className="secondary-button" onClick={() => setModal(null)}>关闭</button><button className="primary-button" onClick={() => edit(modal.row)}>编辑</button></div></> :
      <form className="client-editor" onSubmit={save}>
        <label>设备平台<select value={form.platform} onChange={event => setForm({ ...form, platform: event.target.value as ClientPlatform })}>{clientPlatforms.map(item => <option key={item.id} value={item.id}>{item.label}</option>)}</select></label>
        <label>客户端名称<input required minLength={2} maxLength={80} value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} /></label>
        <label>版本号<input maxLength={40} value={form.version} onChange={event => setForm({ ...form, version: event.target.value })} placeholder="例如 1.2.3" /></label>
        <label>兼容状态<select value={form.compatibility_status} onChange={event => setForm({ ...form, compatibility_status: event.target.value as ClientCompatibility })}><option value="pending">待验证</option><option value="verified">已验证</option><option value="maintenance">维护中</option><option value="unsupported">暂不支持</option></select></label>
        <label>排序值<input required type="number" min="0" max="100000" step="1" value={form.sort_order} onChange={event => setForm({ ...form, sort_order: event.target.value })} /></label>
        <label className="client-active-toggle"><input type="checkbox" checked={form.is_active} onChange={event => setForm({ ...form, is_active: event.target.checked })} /> 上架显示</label>
        <label className="client-editor-wide">客户端说明<textarea maxLength={1000} rows={3} value={form.description} onChange={event => setForm({ ...form, description: event.target.value })} /></label>
        <label className="client-editor-wide">HTTPS 下载地址<input type="url" value={form.download_url} onChange={event => setForm({ ...form, download_url: event.target.value })} placeholder="https://..." /></label>
        <label className="client-editor-wide">HTTPS 二维码图片地址<input type="url" value={form.qr_code_url} onChange={event => setForm({ ...form, qr_code_url: event.target.value })} placeholder="https://..." /></label>
        <label className="client-editor-wide">HTTPS 使用教程地址<input type="url" value={form.tutorial_url} onChange={event => setForm({ ...form, tutorial_url: event.target.value })} placeholder="https://..." /></label>
        <div className="plan-editor-actions"><button type="button" className="secondary-button" disabled={busy} onClick={() => setModal(null)}>取消</button><button className="primary-button" disabled={busy}>{modal.mode === 'edit' ? '保存修改' : '创建客户端'}</button></div>
      </form>}
    </Modal>}
  </div>;
}

function UsersPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Array<Record<string, unknown>> | null>(null);
  useEffect(() => { api<Array<Record<string, unknown>>>('/api/v1/admin/users').then(setRows).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="用户与会员" subtitle="只展示本地演示用户；真实用户信息不会连接或复制。" action={<button className="secondary-button"><ArrowDownToLine size={15} /> 导出列表</button>} /><section className="panel"><SectionTitle title="用户账户" detail={rows ? rows.length + ' 个账户' : '读取中'} /><div className="table-wrap"><table><thead><tr><th>用户</th><th>账户状态</th><th>当前方案</th><th>流量使用</th><th>权益到期</th></tr></thead><tbody>{rows?.map(row => <tr key={String(row.id)}><td><span className="user-cell"><span className="profile-avatar">{String(row.name).slice(0, 1)}</span><span><b>{String(row.name)}</b><small>{String(row.email)}</small></span></span></td><td><StatusPill status={String(row.status)} tone="green" /></td><td>{String(row.plan_name ?? '无有效权益')}</td><td>{row.data_used_gb == null ? '—' : Number(row.data_used_gb).toFixed(1) + ' / ' + row.traffic_gb + ' GB'}</td><td>{shortDate(row.ends_at as string | null)}</td></tr>)}</tbody></table></div></section></div>;
}

type Deployment = { id: string; name: string; provider: string; deployment_key: string; node_id: string; stack_name: string; phase: string; instance_type: string; expires_at: string; created_at: string; released_at: string | null; verified_at: string | null; last_error: string | null; events?: Array<{ id: string; phase: string; message: string; created_at: string }> };
const deploymentPhase: Record<string, string> = { queued: '已排队', provisioning: '创建服务器', bootstrapping: '安装服务', verifying: '验证真实连接', available: '测试可用', draining: '等待排空', releasing: '释放并核对资源', released: '已全部释放' };
function CloudNodes({ notify }: { notify: (value: string) => void }) {
  const [state, setState] = useState<{ enabled: boolean; max_nodes: number; items: Deployment[] } | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [name, setName] = useState('美国测试节点');
  const [size, setSize] = useState('t3.micro');
  const [minutes, setMinutes] = useState(15);
  const [requestId, setRequestId] = useState('');
  const [detail, setDetail] = useState<Deployment | null>(null);
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    try { setState(await api('/api/v1/admin/node-deployments')); }
    catch (error) { notify((error as Error).message); }
  }, [notify]);
  useEffect(() => { void load(); const timer = setInterval(() => void load(), 10000); return () => clearInterval(timer); }, [load]);
  useEffect(() => {
    if (!detail) return;
    const timer = setInterval(() => { api<Deployment>('/api/v1/admin/node-deployments/' + detail.id).then(setDetail).catch(error => notify((error as Error).message)); }, 10000);
    return () => clearInterval(timer);
  }, [detail?.id, notify]);
  const open = async (id: string) => {
    try { setDetail(await api('/api/v1/admin/node-deployments/' + id)); setConfirmation(''); }
    catch (error) { notify((error as Error).message); }
  };
  const create = async (event: React.FormEvent) => {
    event.preventDefault(); setBusy(true);
    try {
      const row = await api<Deployment>('/api/v1/admin/node-deployments', { method: 'POST', body: JSON.stringify({ name, request_id: requestId, instance_type: size, lifetime_minutes: minutes }) });
      setCreateOpen(false); await load(); await open(row.id); notify('任务已排队，系统将自动创建、安装和验证');
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const retire = async () => {
    if (!detail) return;
    setBusy(true);
    try {
      await api('/api/v1/admin/node-deployments/' + detail.id + '/retire', { method: 'POST', body: JSON.stringify({ confirmation }) });
      await load(); await open(detail.id); notify('已申请永久下架，排空后自动释放资源');
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  return <section className="panel">
    <div className="section-title"><div><h2>云节点自动化</h2><span>美国西部 · 独立服务器 · 最多 {state?.max_nodes ?? 2} 台 · 每 10 秒更新</span></div><button className="primary-button" disabled={!state?.enabled} onClick={() => { setRequestId(crypto.randomUUID()); setCreateOpen(true); }}><Server size={15} /> 自动部署测试节点</button></div>
    <div className="notice notice-neutral"><ShieldCheck size={16} /><span>真实服务器按量计费；仅允许配置的管理员 IP 测试。到期由 AWS 自动删除整栈，电脑关机仍生效。会员鉴权与流量计量尚未接入。</span></div>
    {!state ? <LoadingBlock /> : <><p className="modal-copy">{state.enabled ? '新建已启用' : '新建未启用'}；已有节点的到期回收继续执行。pd01、pd02 不在此管理范围。</p><div className="table-wrap"><table><thead><tr><th>节点 / 服务商 / 规格</th><th>任务阶段</th><th>自动回收时间</th><th>创建时间</th><th>操作</th></tr></thead><tbody>{state.items.map(row => <tr key={row.id}><td><b>{row.name}</b><small className="table-sub">{row.provider.toUpperCase()} · {row.instance_type}</small></td><td><StatusPill status={deploymentPhase[row.phase] ?? row.phase} tone={row.phase === 'available' ? 'green' : row.phase === 'released' ? 'gray' : 'amber'} />{row.last_error && <small className="table-sub">{row.last_error}</small>}</td><td>{dateTime(row.expires_at)}</td><td>{dateTime(row.created_at)}</td><td><button className="text-button" onClick={() => void open(row.id)}>详情 / 下架</button></td></tr>)}</tbody></table></div>{!state.items.length && <Empty title="暂无云节点任务" detail="创建后自动安装节点服务，并以真实协议验证出网。" />}</>}
    {createOpen && <Modal title="自动部署测试节点" detail="创建独立 AWS 服务器，到期自动回收全部节点资源" onClose={() => { if (!busy) setCreateOpen(false); }}><form className="node-create-form" onSubmit={create}><label>节点名称<input required minLength={2} maxLength={60} value={name} onChange={event => setName(event.target.value)} /></label><label>服务器规格<select value={size} onChange={event => setSize(event.target.value)}><option value="t3.micro">t3.micro · 2 核 / 1GB</option><option value="t3.small">t3.small · 2 核 / 2GB</option></select></label><label>测试有效期<select value={minutes} onChange={event => setMinutes(Number(event.target.value))}>{[15, 30, 60].map(value => <option key={value} value={value}>{value} 分钟</option>)}</select></label><p className="modal-copy">区域固定为美国西部；不开 SSH，测试端口仅允许管理员来源 IP。真实连接验证通过后才会显示“测试可用”。</p><div className="modal-actions"><button type="button" className="secondary-button" disabled={busy} onClick={() => setCreateOpen(false)}>取消</button><button className="primary-button" disabled={busy}>创建并自动部署</button></div></form></Modal>}
    {detail && <Modal title="云节点任务详情" detail={detail.provider.toUpperCase() + ' · ' + detail.deployment_key} onClose={() => { if (!busy) setDetail(null); }} wide><dl className="detail-grid"><div><dt>服务商</dt><dd>{detail.provider.toUpperCase()}</dd></div><div><dt>阶段</dt><dd>{deploymentPhase[detail.phase] ?? detail.phase}</dd></div><div><dt>到期回收</dt><dd>{dateTime(detail.expires_at)}</dd></div><div><dt>协议验证</dt><dd>{dateTime(detail.verified_at)}</dd></div><div><dt>资源释放完成</dt><dd>{dateTime(detail.released_at)}</dd></div></dl>{detail.last_error && <div className="notice notice-warning">{detail.last_error}</div>}{detail.phase === 'available' && <a className="secondary-button" href={'/api/v1/admin/node-deployments/' + detail.id + '/profile'} download>下载管理员 Clash 测试配置</a>}{!['draining', 'releasing', 'released'].includes(detail.phase) && <div className="node-transition"><label>永久下架并释放<input value={confirmation} placeholder="输入：永久下架并释放" onChange={event => setConfirmation(event.target.value)} /></label><button className="primary-button" disabled={busy || confirmation !== '永久下架并释放'} onClick={() => void retire()}>确认下架并回收</button></div>}<div className="node-history"><h3>自动化任务时间线</h3><p className="modal-copy">后台按对应服务商的实际状态、端口检查和协议验证记录流程节点；这不是服务商原始操作日志。AWS 节点的原始资源事件请查 CloudFormation Stack Events，API 调用身份和结果请查 CloudTrail。</p>{detail.events?.map(event => <div key={event.id}><b>{event.message}</b><span>{dateTime(event.created_at)}</span></div>)}</div></Modal>}
  </section>;
}

function NodesPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Node[] | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<NodeDetail | null>(null);
  const [nextStatus, setNextStatus] = useState<NodeStatus | ''>('');
  const [name, setName] = useState('');
  const [region, setRegion] = useState('');
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<Node[]>('/api/v1/admin/nodes').then(nodes => setRows(nodes.filter(node => !node.automation_managed))).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load, refresh]);
  const openDetail = async (id: string) => {
    setSelectedId(id);
    setDetail(null);
    try {
      const node = await api<NodeDetail>('/api/v1/admin/nodes/' + id);
      setDetail(node);
      setNextStatus('');
    } catch (error) { setSelectedId(null); notify((error as Error).message); }
  };
  const create = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    try {
      const node = await api<Node>('/api/v1/admin/nodes', { method: 'POST', body: JSON.stringify({ name: name.trim(), region: region.trim() }) });
      setShowCreate(false); setName(''); setRegion('');
      notify('演示节点已加入候选目录');
      await load();
      await openDetail(node.id);
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const change = async () => {
    if (!detail || !nextStatus) return;
    setBusy(true);
    try {
      await api('/api/v1/admin/nodes/' + detail.id, { method: 'PATCH', body: JSON.stringify({ status: nextStatus }) });
      notify('演示目录状态已更新');
      await load();
      await openDetail(detail.id);
    } catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  return <div className="page-stack">
    <PageHeading eyebrow="运营管理" title="节点运维" subtitle="自动部署独立测试服务器，验证真实连接并按期回收资源。" action={<div className="heading-actions"><button className="secondary-button" onClick={() => void load()}><RefreshCw size={14} /> 刷新列表</button><button className="primary-button" onClick={() => setShowCreate(true)}><Server size={14} /> 新建测试记录</button></div>} />
    <div className="notice notice-warning"><ShieldCheck size={16} /><span><b>目录记录</b> 下方演示记录只更新目录；真实测试服务器请通过“云节点自动化”创建和永久下架。</span></div>
    <CloudNodes notify={notify} /><section className="panel"><SectionTitle title="节点目录" detail={rows ? rows.length + ' 个记录' : '载入中'} />
      {!rows ? <LoadingBlock /> : !rows.length ? <Empty title="暂无节点记录" detail="点击右上角新建本地测试记录。" /> : <div className="table-wrap"><table><thead><tr><th>节点</th><th>协议 / 测试地址</th><th>样例容量</th><th>目录状态</th><th>健康探测</th><th>配置版本</th><th>操作</th></tr></thead><tbody>{rows.map(node => <tr key={node.id}>
        <td><span className="node-cell"><span className={'node-indicator' + (node.status === 'available' ? '' : ' inactive')} /><span><b>{node.name}</b><small>{node.region} · {node.is_test ? '演示记录' : '只读记录'}</small></span></span></td>
        <td><code>{node.protocol}</code><small className="table-sub">{node.endpoint_host}:{node.endpoint_port}</small></td>
        <td>{node.active_sessions} / {node.max_sessions}</td><td><StatusPill status={nodeStatus(node.status)} tone={nodeTone(node.status)} /></td>
        <td><StatusPill status={node.health_status === 'not_connected' ? '未接入' : node.health_status} tone="gray" /></td><td>v{node.config_version}</td>
        <td><button className="text-button" onClick={() => void openDetail(node.id)}>详情</button></td>
      </tr>)}</tbody></table></div>}
    </section>
    <div className="metric-grid compact-metrics"><Metric label="目录记录" value={rows?.length ?? '—'} note="仅本地演示" icon={Server} tone="green" /><Metric label="标记可用" value={rows?.filter(item => item.status === 'available').length ?? '—'} note="不代表真实在线" icon={Users} tone="teal" /><Metric label="健康探测" value="未接入" note="无实时监控数据" icon={Activity} tone="amber" /><Metric label="变更历史" value="已记录" note="状态切换可追溯" icon={ShieldCheck} tone="blue" /></div>
    {showCreate && <Modal title="新建测试节点" detail="仅创建本地目录记录，不部署服务器" onClose={() => { if (!busy) setShowCreate(false); }}>
      <form className="node-create-form" onSubmit={create}><label>节点名称<input required minLength={2} maxLength={60} value={name} onChange={event => setName(event.target.value)} placeholder="例如：演示节点 02" /></label><label>测试区域<input required minLength={2} maxLength={40} value={region} onChange={event => setRegion(event.target.value)} placeholder="例如：测试区域 B" /></label>
        <p className="modal-copy">系统自动分配 .invalid 测试地址，初始为候选状态，健康探测显示未接入。</p><div className="modal-actions"><button type="button" className="secondary-button" disabled={busy} onClick={() => setShowCreate(false)}>取消</button><button className="primary-button" disabled={busy}>创建记录</button></div>
      </form>
    </Modal>}
    {selectedId && <Modal title="节点详情" detail="本地演示目录与状态变更历史" onClose={() => { if (!busy) { setSelectedId(null); setDetail(null); } }} wide>
      {!detail || detail.id !== selectedId ? <LoadingBlock /> : <><dl className="detail-grid">
        <div><dt>节点名称</dt><dd>{detail.name}</dd></div><div><dt>测试区域</dt><dd>{detail.region}</dd></div>
        <div><dt>协议 / 测试地址</dt><dd>{detail.protocol} · {detail.endpoint_host}:{detail.endpoint_port}</dd></div><div><dt>目录状态</dt><dd>{nodeStatus(detail.status)}</dd></div>
        <div><dt>健康探测</dt><dd>{detail.health_status === 'not_connected' ? '未接入' : detail.health_status}</dd></div><div><dt>最近探测</dt><dd>{detail.last_health_check_at ? new Date(detail.last_health_check_at).toLocaleString('zh-CN') : '尚无探测数据'}</dd></div>
        <div><dt>配置版本</dt><dd>v{detail.config_version}</dd></div><div><dt>样例会话 / 容量</dt><dd>{detail.active_sessions} / {detail.max_sessions}</dd></div>
      </dl><div className="node-transition"><label>切换目录状态<select value={nextStatus} onChange={event => setNextStatus(event.target.value as NodeStatus)} disabled={!detail.allowed_transitions.length || busy}><option value="">选择目标状态</option>{detail.allowed_transitions.map(status => <option value={status} key={status}>{nodeStatus(status)}</option>)}</select></label><button className="primary-button" disabled={!nextStatus || busy} onClick={() => void change()}>确认变更</button></div>
      <div className="node-history"><h3>状态变更记录</h3>{detail.changes.length ? detail.changes.map(item => <div key={item.id}><b>{nodeStatus(item.from_status)} → {nodeStatus(item.to_status)}</b><span>{item.actor_name} · {new Date(item.created_at).toLocaleString('zh-CN')}</span></div>) : <p>暂无状态变更。</p>}</div></>}
    </Modal>}
  </div>;
}
function nodeStatus(status: NodeStatus) { return ({ candidate: '候选', available: '标记可用', draining: '排空中', maintenance: '维护中', isolated: '已隔离', retired: '已退役', disabled: '已停用（旧）' } as const)[status]; }
function nodeTone(status: NodeStatus) { return status === 'available' ? 'green' : status === 'draining' || status === 'maintenance' ? 'amber' : status === 'isolated' ? 'red' : 'gray'; }

function CommissionsPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Commission[] | null>(null);
  useEffect(() => { api<Commission[]>('/api/v1/admin/commissions').then(setRows).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  const total = useMemo(() => (rows ?? []).filter(row => row.status === 'pending').reduce((sum, row) => sum + row.amount_cents, 0), [rows]);
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="代理结算" subtitle="可追溯订单关联的演示佣金；不连接现有结算渠道，也不执行付款。" /><div className="notice notice-neutral"><ShieldCheck size={16} /><span>流水仅用于验证后台界面。正式账务应从现行结算事实源接入，不能在此创建第二本生产账。</span></div><div className="metric-grid compact-metrics"><Metric label="待核对流水" value={rows?.length ?? '—'} note="本地样例" icon={ArrowUpRight} tone="amber" /><Metric label="待结算合计" value={rows ? money(total) : '—'} note="不会实际打款" icon={CreditCard} tone="blue" /></div><section className="panel"><SectionTitle title="佣金流水" detail={rows ? rows.length + ' 条记录' : '读取中'} /><div className="table-wrap"><table><thead><tr><th>渠道</th><th>关联订单</th><th>金额</th><th>状态</th><th>创建时间</th></tr></thead><tbody>{rows?.map(row => <tr key={row.id}><td>{row.partner_name}</td><td><code>{row.order_id}</code></td><td>{money(row.amount_cents)}</td><td><StatusPill status={row.status === 'pending' ? '待核对' : row.status} tone="amber" /></td><td>{new Date(row.created_at).toLocaleString('zh-CN')}</td></tr>)}</tbody></table></div></section></div>;
}

function StatusPill({ status, tone }: { status: string; tone: string }) { return <span className={'status-pill ' + tone}><i />{status}</span>; }
