import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Activity, ArrowDownToLine, ArrowLeftRight, ArrowUpRight, BadgeCheck,
  Bell, ChevronDown, CircleHelp, CircleUserRound, Clipboard, Command,
  CreditCard, Gauge, Globe2, KeyRound, LayoutDashboard, LifeBuoy, Link2,
  LogOut, Menu, Monitor, MoreHorizontal, Network, RefreshCw, Search,
  Server, Settings2, ShieldCheck, Smartphone, Users, X,
} from 'lucide-react';

type User = { id: string; email: string; name: string; role: 'admin' | 'member' };
type Plan = { id: string; name: string; period_label: string; duration_days: number; price_cents: number; traffic_gb: number; max_devices: number };
type Overview = {
  user: User;
  entitlement: null | { status: string; ends_at: string; data_used_gb: number; plan_name: string; traffic_gb: number; max_devices: number };
  activeSubscriptions: number;
  recentOrders: Array<{ id: string; plan_name: string; total_cents: number; status: string; created_at: string }>;
};
type Node = { id: string; name: string; region: string; protocol: string; endpoint_host: string; endpoint_port: number; status: 'available' | 'maintenance' | 'disabled'; is_test: number; max_sessions: number; active_sessions: number; latency_ms: number | null; updated_at: string };
type AdminSummary = { memberCount: number; activeEntitlements: number; activeSubscriptions: number; availableNodes: number; pendingCommissionCents: number; recentAudit: Array<{ id: string; action: string; target_type: string; target_id: string; created_at: string; actor: string }> };
type Subscription = { id: string; name: string; status: string; created_at: string; expires_at: string; last_used_at: string | null; url?: string };
type Commission = { id: string; partner_name: string; amount_cents: number; status: string; order_id: string; created_at: string };
type TicketStatus = 'open' | 'in_progress' | 'resolved';
type TicketSummary = { id: string; category: string; subject: string; status: TicketStatus; created_at: string; updated_at: string; user_name: string };
type TicketEvent = { id: string; kind: 'message' | 'status_changed'; body: string | null; from_status: TicketStatus | null; to_status: TicketStatus | null; created_at: string; actor_name: string; actor_role: User['role'] };
type TicketDetail = TicketSummary & { events: TicketEvent[]; resolved_at: string | null };
type PageMeta = { total: number; page: number; per_page: number; total_pages: number };

const apiEnvelope = async <T,>(path: string, init?: RequestInit): Promise<{ data: T; meta?: PageMeta }> => {
  const response = await fetch(path, { ...init, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', ...init?.headers } });
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

const nav = [
  { id: 'overview', label: '总览', icon: LayoutDashboard },
  { id: 'subscriptions', label: '订阅与设备', icon: Link2 },
  { id: 'plans', label: '套餐权益', icon: CreditCard },
  { id: 'orders', label: '订单记录', icon: ArrowLeftRight },
  { id: 'support', label: '帮助工单', icon: LifeBuoy },
];
const adminNav = [
  { id: 'users', label: '用户与会员', icon: Users },
  { id: 'nodes', label: '节点运维', icon: Server },
  { id: 'commissions', label: '代理结算', icon: ArrowUpRight },
];

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [view, setView] = useState('overview');
  const [mobileMenu, setMobileMenu] = useState(false);
  const [toast, setToast] = useState('');
  const [loginEmail, setLoginEmail] = useState('admin@xlb.local');
  const [password, setPassword] = useState('admin-local-2026');
  const [loading, setLoading] = useState(true);
  const [nonce, setNonce] = useState(0);
  const refresh = useCallback(() => setNonce(value => value + 1), []);
  const notify = useCallback((message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(''), 2600);
  }, []);

  useEffect(() => {
    api<User | null>('/api/v1/auth/session').then(setUser).catch(() => setUser(null)).finally(() => setLoading(false));
  }, []);
  useEffect(() => {
    if (!user) return;
    api<User | null>('/api/v1/auth/session').then(setUser).catch(() => setUser(null));
  }, [nonce, user?.id]);

  const login = async (event: React.FormEvent) => {
    event.preventDefault();
    try {
      const next = await api<User>('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: loginEmail, password }) });
      setUser(next);
      setView('overview');
      notify('已进入本地演示环境');
    } catch (error) { notify((error as Error).message); }
  };
  const logout = async () => {
    await api('/api/v1/auth/logout', { method: 'POST' });
    setUser(null);
    setView('overview');
  };

  if (loading) return <main className="boot"><span className="brand-mark">X</span><span>迅连宝控制台</span></main>;
  if (!user) return <Login email={loginEmail} setEmail={setLoginEmail} password={password} setPassword={setPassword} onSubmit={login} />;

  return <div className="app-shell">
    <Sidebar view={view} setView={setView} role={user.role} mobile={mobileMenu} close={() => setMobileMenu(false)} logout={logout} user={user} />
    {mobileMenu && <button className="scrim" aria-label="关闭菜单" onClick={() => setMobileMenu(false)} />}
    <div className="workspace">
      <header className="topbar">
        <button className="icon-button menu-toggle" onClick={() => setMobileMenu(true)} aria-label="打开导航"><Menu size={19} /></button>
        <div className="crumb"><span>控制台</span><span className="crumb-slash">/</span><strong>{labelFor(view)}</strong></div>
        <div className="top-actions">
          <span className="environment"><i /> 本地开发环境</span>
          <button className="icon-button notification" aria-label="通知"><Bell size={17} /><span /></button>
          <button className="profile-trigger" onClick={() => setView(user.role === 'admin' ? 'users' : 'overview')}>
            <span className="profile-avatar">{user.name.slice(0, 1)}</span><span className="profile-name">{user.name}</span><ChevronDown size={14} />
          </button>
        </div>
      </header>
      <main className="main-content">
        {view === 'overview' && <OverviewPage user={user} refresh={nonce} setView={setView} notify={notify} />}
        {view === 'subscriptions' && <SubscriptionsPage refresh={nonce} notify={notify} />}
        {view === 'plans' && <PlansPage refresh={nonce} notify={notify} />}
        {view === 'orders' && <OrdersPage refresh={nonce} notify={notify} />}
        {view === 'support' && <SupportPage role={user.role} refresh={nonce} notify={notify} />}
        {view === 'users' && user.role === 'admin' && <UsersPage refresh={nonce} notify={notify} />}
        {view === 'nodes' && user.role === 'admin' && <NodesPage refresh={nonce} notify={notify} />}
        {view === 'commissions' && user.role === 'admin' && <CommissionsPage refresh={nonce} notify={notify} />}
      </main>
      <footer className="app-footer"><span>迅连宝控制台</span><span>本地重建 · 数据仅存于本机</span><button onClick={refresh}><RefreshCw size={13} /> 刷新数据</button></footer>
    </div>
    {toast && <div className="toast" role="status">{toast}</div>}
  </div>;
}

function labelFor(view: string) {
  return [...nav, ...adminNav].find(item => item.id === view)?.label ?? '总览';
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
      {nav.map(item => <NavItem key={item.id} {...item} active={view === item.id} onClick={() => change(item.id)} />)}
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

function Login({ email, setEmail, password, setPassword, onSubmit }: {
  email: string; setEmail: (value: string) => void; password: string; setPassword: (value: string) => void; onSubmit: (event: React.FormEvent) => void;
}) {
  return <main className="login-shell">
    <div className="login-aside">
      <div className="login-logo"><span className="brand-mark">X</span><span className="brand-word">迅连宝<small>VPN</small></span></div>
      <div className="login-copy"><span className="eyebrow"><i /> SERVICE CONTROL PLANE</span><h1>连接服务，<br />一处管理。</h1><p>账户、订阅、节点和结算状态，在同一工作区清楚呈现。</p></div>
      <div className="login-art"><div className="orbit orbit-one" /><div className="orbit orbit-two" /><div className="orbit-core"><ShieldCheck size={30} /></div><div className="orbit-node node-one" /><div className="orbit-node node-two" /><div className="orbit-node node-three" /><div className="art-label label-one"><span>API</span> 控制面在线</div><div className="art-label label-two"><span>01</span> 测试节点</div></div>
      <div className="login-aside-foot"><span>XL B · LOCAL PLATFORM</span><span>安全 · 可审计 · 可维护</span></div>
    </div>
    <section className="login-panel"><div className="login-panel-top"><span className="environment"><i /> 本地开发环境</span><button className="icon-button"><CircleHelp size={17} /></button></div>
      <form className="login-form" onSubmit={onSubmit}><span className="eyebrow">欢迎回来</span><h2>登录你的工作区</h2><p>请选择一个本地演示账户继续。</p>
        <label>邮箱地址<input type="email" autoComplete="username" value={email} onChange={e => setEmail(e.target.value)} required /></label>
        <label>登录密码<input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)} required /></label>
        <div className="form-meta"><label className="remember"><input type="checkbox" defaultChecked /> 保持登录</label><span>本机演示账号</span></div>
        <button className="primary-button login-submit">登录控制台 <ArrowUpRight size={16} /></button>
        <div className="demo-hint"><ShieldCheck size={15} /><span>默认管理员：<b>admin@xlb.local</b> / <b>admin-local-2026</b></span></div>
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
    { label: '在线节点', value: summary.availableNodes + ' / 1', note: '测试节点', icon: Server, tone: 'amber' },
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
  return <section className="member-hero"><div className="member-main"><div className="member-tag"><BadgeCheck size={14} /> 当前有效会员</div><h2>{entitlement.plan_name}</h2><p>有效至 {shortDate(entitlement.ends_at)} · 最多 {entitlement.max_devices} 台设备</p><button className="member-link" onClick={() => setView('plans')}>查看会员权益 <ArrowUpRight size={14} /></button></div><div className="usage"><div className="usage-top"><span>已用流量</span><b>{entitlement.data_used_gb.toFixed(1)} <small>/ {entitlement.traffic_gb} GB</small></b></div><div className="usage-bar"><span style={{ width: used + '%' }} /></div><div className="usage-foot"><span>剩余 {(entitlement.traffic_gb - entitlement.data_used_gb).toFixed(1)} GB</span><span>{used}%</span></div></div><div className="member-side"><span>当前连接</span><b><i /> 未连接</b><small>演示状态 · 无真实节点</small></div></section>;
}
function AuditList({ rows }: { rows?: AdminSummary['recentAudit'] }) {
  if (!rows) return <LoadingBlock />;
  if (!rows.length) return <Empty title="暂无操作记录" detail="管理员操作会记录在这里。" />;
  return <div className="activity-list">{rows.map(row => <div className="activity-row" key={row.id}><span className="activity-icon"><Settings2 size={15} /></span><span className="activity-copy"><b>{auditLabel(row.action)}</b><small>{row.actor} · {row.target_type} · {row.target_id.slice(0, 12)}</small></span><time>{shortDate(row.created_at)}</time></div>)}</div>;
}
function auditLabel(action: string) {
  return ({ 'node.status_changed': '更新测试节点状态', 'subscription.created': '创建 Clash 订阅', 'subscription.rotated': '轮换订阅凭证', 'subscription.revoked': '撤销订阅凭证' } as Record<string, string>)[action] ?? action;
}
function OrderRows({ rows }: { rows?: Overview['recentOrders'] }) {
  if (!rows) return <LoadingBlock />;
  if (!rows.length) return <Empty title="还没有订单记录" detail="订单状态会显示在这里。" />;
  return <div className="activity-list">{rows.map(row => <div className="activity-row" key={row.id}><span className="activity-icon"><CreditCard size={15} /></span><span className="activity-copy"><b>{row.plan_name}</b><small>{row.id} · 本地演示记录</small></span><b className="order-amount">{money(row.total_cents)}</b><StatusPill status={row.status === 'paid_test' ? '演示已支付' : row.status} tone="green" /></div>)}</div>;
}
function PlatformStatus({ summary, setView }: { summary: AdminSummary | null; setView: (view: string) => void }) {
  return <div className="quick-actions"><QuickAction icon={Server} label="测试节点" detail={summary ? summary.availableNodes + ' 个节点可用 · 0 个真实节点' : '载入中'} onClick={() => setView('nodes')} /><QuickAction icon={Link2} label="订阅凭证" detail={summary ? summary.activeSubscriptions + ' 个有效链接' : '载入中'} onClick={() => setView('subscriptions')} /><QuickAction icon={ArrowUpRight} label="代理结算" detail={summary ? money(summary.pendingCommissionCents) + ' 待结算演示金额' : '载入中'} onClick={() => setView('commissions')} /></div>;
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
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => api<Subscription[]>('/api/v1/me/subscriptions').then(setRows).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load, refresh]);
  const create = async () => {
    setBusy(true);
    try { const item = await api<Subscription>('/api/v1/me/subscriptions', { method: 'POST' }); setCreatedUrl(item.url ?? ''); notify('订阅链接已生成，请立即复制保存'); await load(); }
    catch (error) { notify((error as Error).message); }
    finally { setBusy(false); }
  };
  const revoke = async (row: Subscription) => {
    try { await api('/api/v1/me/subscriptions/' + row.id + '/revoke', { method: 'POST' }); notify('订阅凭证已撤销'); await load(); }
    catch (error) { notify((error as Error).message); }
  };
  const rotate = async (row: Subscription) => {
    try { const next = await api<Subscription>('/api/v1/me/subscriptions/' + row.id + '/rotate', { method: 'POST' }); setCreatedUrl(next.url ?? ''); notify('旧链接已撤销，新链接仅显示一次'); await load(); }
    catch (error) { notify((error as Error).message); }
  };
  const copy = async (value: string) => {
    const absolute = new URL(value, window.location.origin).toString();
    try { await navigator.clipboard.writeText(absolute); notify('已复制订阅地址'); }
    catch { setCreatedUrl(absolute); notify('无法访问剪贴板，请手动复制'); }
  };
  return <div className="page-stack">
    <PageHeading eyebrow="账户连接" title="订阅与设备" subtitle="生成、轮换或撤销 Clash 兼容订阅。订阅链接等同账户凭证，请妥善保管。" action={<button className="primary-button" disabled={busy} onClick={create}><Link2 size={15} /> 新建订阅</button>} />
    <div className="notice notice-warning"><ShieldCheck size={16} /><span><b>本地开发订阅</b> 仅用于查看 Mihomo 配置格式，不含真实可连接节点。不要导入生产客户端或分享真实凭证。</span></div>
    {createdUrl && <div className="secret-result"><div><span className="eyebrow">新凭证 · 仅显示本次</span><code>{new URL(createdUrl, window.location.origin).toString()}</code></div><button className="secondary-button" onClick={() => void copy(createdUrl)}><Clipboard size={15} /> 复制链接</button><button className="icon-button" aria-label="关闭" onClick={() => setCreatedUrl('')}><X size={16} /></button></div>}
    <section className="panel"><SectionTitle title="订阅凭证" detail={rows ? rows.length + ' 条记录' : '读取中'} action={<button className="secondary-button" onClick={() => void load()}><RefreshCw size={14} /> 刷新</button>} />
      {!rows ? <LoadingBlock /> : !rows.length ? <Empty title="尚未创建订阅" detail="创建订阅后，可在本机查看测试配置格式。" /> : <div className="table-wrap"><table><thead><tr><th>名称</th><th>状态</th><th>创建时间</th><th>到期时间</th><th>最近访问</th><th /></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><span className="table-main"><Link2 size={15} />{row.name}</span><small className="table-sub">{row.id.slice(0, 14)}</small></td><td><StatusPill status={row.status === 'active' ? '有效' : '已撤销'} tone={row.status === 'active' ? 'green' : 'gray'} /></td><td>{shortDate(row.created_at)}</td><td>{shortDate(row.expires_at)}</td><td>{shortDate(row.last_used_at)}</td><td><div className="row-actions">{row.status === 'active' && <><button className="text-button" onClick={() => void rotate(row)}>轮换</button><button className="text-button danger-text" onClick={() => void revoke(row)}>撤销</button></>}</div></td></tr>)}</tbody></table></div>}
    </section>
    <div className="two-column"><section className="panel"><SectionTitle title="连接客户端" detail="外部客户端兼容性仍需逐版本验证" /><div className="client-list"><div><span className="client-icon"><Monitor size={17} /></span><span><b>Clash Verge Rev</b><small>Mihomo 内核 · 计划首批验证</small></span><StatusPill status="待验证" tone="amber" /></div><div><span className="client-icon"><Smartphone size={17} /></span><span><b>Clash Mi</b><small>应用名称与平台待确认</small></span><StatusPill status="待确认" tone="gray" /></div></div></section><section className="panel"><SectionTitle title="使用状态" detail="权限与接入边界" /><div className="policy-list"><p><ShieldCheck size={15} /> 订阅地址作为密钥存储，只保留摘要。</p><p><RefreshCw size={15} /> 轮换会立即废止旧链接。</p><p><Activity size={15} /> 用量由服务端策略执行，不依赖配置文案。</p><p><Globe2 size={15} /> 本地测试节点使用保留地址，不提供连接服务。</p></div></section></div>
  </div>;
}

function PlansPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  useEffect(() => { api<Plan[]>('/api/v1/catalog/plans').then(setPlans).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  return <div className="page-stack"><PageHeading eyebrow="套餐中心" title="套餐权益" subtitle="Web、App 与第三方客户端将共享同一权益数据源。" /><div className="notice notice-neutral"><CreditCard size={16} /><span>本地样例套餐仅用于界面和 API 验证；此环境没有接入支付，不会产生真实订单。</span></div>
    <section className="panel"><SectionTitle title="会员方案" detail={plans ? plans.length + ' 种方案' : '载入中'} /><div className="plan-grid">{plans?.map(plan => <div className="plan-row" key={plan.id}><div className="plan-period"><span className="plan-icon"><BadgeCheck size={17} /></span><span><b>{plan.name}</b><small>{plan.period_label}有效</small></span></div><div><b>{money(plan.price_cents)}</b><small>样例标价</small></div><div><b>{plan.traffic_gb} GB</b><small>套餐流量</small></div><div><b>{plan.max_devices} 台</b><small>同时在线</small></div><StatusPill status="本地样例" tone="gray" /></div>)}</div></section>
    <section className="panel"><SectionTitle title="权益一致性" detail="唯一事实源设计" /><div className="service-strip"><ServiceItem label="官网展示" status="共用套餐 API" icon={Globe2} /><ServiceItem label="自有 App" status="共用账户权益" icon={Smartphone} /><ServiceItem label="Clash 订阅" status="服务端校验" icon={Link2} /><ServiceItem label="运营后台" status="审计可查" icon={ShieldCheck} /></div></section>
  </div>;
}

function OrdersPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Overview['recentOrders'] | null>(null);
  useEffect(() => { api<Overview['recentOrders']>('/api/v1/me/orders').then(setRows).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  return <div className="page-stack"><PageHeading eyebrow="账户记录" title="订单记录" subtitle="查看当前账户的本地演示订单；此环境不会发起支付。" /><section className="panel"><SectionTitle title="全部订单" detail={rows ? rows.length + ' 条记录' : '读取中'} />{!rows ? <LoadingBlock /> : !rows.length ? <Empty title="没有订单记录" detail="演示账户暂时没有订单。" /> : <div className="table-wrap"><table><thead><tr><th>订单号</th><th>方案</th><th>金额</th><th>状态</th><th>创建时间</th></tr></thead><tbody>{rows.map(row => <tr key={row.id}><td><code>{row.id}</code></td><td>{row.plan_name}</td><td>{money(row.total_cents)}</td><td><StatusPill status={row.status === 'paid_test' ? '本地演示' : row.status} tone="green" /></td><td>{new Date(row.created_at).toLocaleString('zh-CN')}</td></tr>)}</tbody></table></div>}</section></div>;
}

const ticketStatusText: Record<TicketStatus, string> = { open: '待处理', in_progress: '处理中', resolved: '已解决' };
const ticketCategoryText: Record<string, string> = { connection: '连接问题', subscription: '订阅配置', payment: '订单与付款', account: '账户安全', other: '其他问题' };

function SupportPage({ role, refresh, notify }: { role: User['role']; refresh: number; notify: (value: string) => void }) {
  const base = '/api/v1/' + (role === 'admin' ? 'admin' : 'me') + '/tickets';
  const [rows, setRows] = useState<TicketSummary[] | null>(null);
  const [detail, setDetail] = useState<TicketDetail | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
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
      setSelectedId(current => current && result.data.some(row => row.id === current) ? current : (result.data[0]?.id ?? null));
    } catch (error) { notify((error as Error).message); }
  }, [base, page, notify]);
  const loadDetail = useCallback(async (id: string) => {
    try { setDetail(await api<TicketDetail>(base + '/' + id)); }
    catch (error) { notify((error as Error).message); }
  }, [base, notify]);
  useEffect(() => { void loadList(); }, [loadList, refresh]);
  useEffect(() => { if (selectedId) void loadDetail(selectedId); else setDetail(null); }, [selectedId, loadDetail, refresh]);
  const create = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    try {
      const created = await api<{ id: string }>('/api/v1/me/tickets', { method: 'POST', body: JSON.stringify({ category, subject, description }) });
      setSubject(''); setDescription(''); setPage(1); setSelectedId(created.id);
      await loadDetail(created.id);
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
    <PageHeading eyebrow="服务支持" title="帮助工单" subtitle={role === 'admin' ? '查看本地会员问题并跟进处理；所有状态变更均留有审计。' : '遇到订阅、连接、订单或账户问题，可在这里提交并跟踪处理进度。'} />
    <div className="notice notice-warning"><ShieldCheck size={16} /><span><b>信息保护</b> 请勿在工单中填写密码、完整订阅地址或支付凭证。当前仅接收本地演示数据。</span></div>
    <div className="support-layout">
      <section className="panel support-list"><SectionTitle title={role === 'admin' ? '会员工单' : '我的工单'} detail={meta ? '共 ' + meta.total + ' 条' : '载入中'} />
        {role === 'member' && <form className="support-form" onSubmit={create}>
          <label>问题类别<select value={category} onChange={event => setCategory(event.target.value)}>{Object.entries(ticketCategoryText).map(([key, value]) => <option key={key} value={key}>{value}</option>)}</select></label>
          <label>标题<input value={subject} onChange={event => setSubject(event.target.value)} maxLength={100} minLength={3} placeholder="简要描述遇到的问题" required /></label>
          <label>问题描述<textarea value={description} onChange={event => setDescription(event.target.value)} maxLength={2000} minLength={10} rows={4} placeholder="请写明发生时间、系统和客户端版本、脱敏错误码" required /></label>
          <button className="primary-button" disabled={busy}>提交工单 <ArrowUpRight size={14} /></button>
        </form>}
        {!rows ? <LoadingBlock /> : rows.length === 0 ? <Empty title="暂无工单" detail="新工单会显示在这里。" /> : <div className="support-tickets">{rows.map(row => <button key={row.id} className={'support-ticket' + (selectedId === row.id ? ' selected' : '')} onClick={() => setSelectedId(row.id)}>
          <span><b>{row.subject}</b><small>{role === 'admin' ? row.user_name + ' · ' : ''}{ticketCategoryText[row.category]} · {shortDate(row.updated_at)}</small></span>
          <StatusPill status={ticketStatusText[row.status]} tone={row.status === 'resolved' ? 'green' : row.status === 'in_progress' ? 'amber' : 'gray'} />
        </button>)}</div>}
        {meta && meta.total_pages > 1 && <div className="support-pagination"><button className="text-button" disabled={page === 1} onClick={() => setPage(page - 1)}>上一页</button><span>{page} / {meta.total_pages}</span><button className="text-button" disabled={page >= meta.total_pages} onClick={() => setPage(page + 1)}>下一页</button></div>}
      </section>
      <section className="panel support-detail">{!detail ? <Empty title="选择一张工单" detail="点击左侧记录查看处理过程。" /> : <>
        <div className="support-detail-head"><span className="eyebrow">{ticketCategoryText[detail.category]} · {detail.id.slice(0, 8)}</span><h2>{detail.subject}</h2><div><StatusPill status={ticketStatusText[detail.status]} tone={detail.status === 'resolved' ? 'green' : detail.status === 'in_progress' ? 'amber' : 'gray'} /><small>创建于 {new Date(detail.created_at).toLocaleString('zh-CN')}</small></div></div>
        {role === 'admin' && <div className="support-actions">
          {detail.status === 'open' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('in_progress')}>开始处理</button>}
          {detail.status === 'in_progress' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('open')}>退回待处理</button>}
          {detail.status === 'resolved' && <button className="secondary-button" disabled={busy} onClick={() => void changeStatus('in_progress')}>重新处理</button>}
          {detail.status !== 'resolved' && <button className="primary-button" disabled={busy} onClick={() => void changeStatus('resolved')}>标记已解决</button>}
        </div>}
        <div className="support-events">{detail.events.map(item => item.kind === 'status_changed' ? <div key={item.id} className="support-event system">{item.actor_name} 将状态从 {ticketStatusText[item.from_status!]} 改为 {ticketStatusText[item.to_status!]} · {new Date(item.created_at).toLocaleString('zh-CN')}</div> : <div key={item.id} className={'support-event' + (item.actor_role === 'admin' ? ' staff' : '')}><div><b>{item.actor_name}</b><small>{new Date(item.created_at).toLocaleString('zh-CN')}</small></div><p>{item.body}</p></div>)}</div>
        <form className="support-reply" onSubmit={sendReply}><label htmlFor="support-reply">{role === 'admin' ? '回复会员' : '补充说明'}</label><textarea id="support-reply" value={reply} onChange={event => setReply(event.target.value)} maxLength={2000} minLength={2} rows={3} placeholder="请勿填写密码或完整订阅地址" required /><button className="primary-button" disabled={busy}>发送回复 <ArrowUpRight size={14} /></button></form>
      </>}</section>
    </div>
  </div>;
}

function UsersPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Array<Record<string, unknown>> | null>(null);
  useEffect(() => { api<Array<Record<string, unknown>>>('/api/v1/admin/users').then(setRows).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="用户与会员" subtitle="只展示本地演示用户；真实用户信息不会连接或复制。" action={<button className="secondary-button"><ArrowDownToLine size={15} /> 导出列表</button>} /><section className="panel"><SectionTitle title="用户账户" detail={rows ? rows.length + ' 个账户' : '读取中'} /><div className="table-wrap"><table><thead><tr><th>用户</th><th>账户状态</th><th>当前方案</th><th>流量使用</th><th>权益到期</th></tr></thead><tbody>{rows?.map(row => <tr key={String(row.id)}><td><span className="user-cell"><span className="profile-avatar">{String(row.name).slice(0, 1)}</span><span><b>{String(row.name)}</b><small>{String(row.email)}</small></span></span></td><td><StatusPill status={String(row.status)} tone="green" /></td><td>{String(row.plan_name ?? '无有效权益')}</td><td>{row.data_used_gb == null ? '—' : Number(row.data_used_gb).toFixed(1) + ' / ' + row.traffic_gb + ' GB'}</td><td>{shortDate(row.ends_at as string | null)}</td></tr>)}</tbody></table></div></section></div>;
}

function NodesPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Node[] | null>(null);
  const load = useCallback(() => api<Node[]>('/api/v1/admin/nodes').then(setRows).catch(error => notify((error as Error).message)), [notify]);
  useEffect(() => { void load(); }, [load, refresh]);
  const change = async (node: Node, status: Node['status']) => {
    try { await api('/api/v1/admin/nodes/' + node.id, { method: 'PATCH', body: JSON.stringify({ status }) }); notify('测试节点状态已更新'); await load(); }
    catch (error) { notify((error as Error).message); }
  };
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="节点运维" subtitle="本地节点目录与状态控制。本页不会登录或操作任何真实服务器。" action={<button className="secondary-button" onClick={() => void load()}><RefreshCw size={14} /> 刷新探测</button>} /><div className="notice notice-warning"><ShieldCheck size={16} /><span><b>安全边界</b> 当前仅有保留测试地址的演示记录。部署、SSH、密钥管理和真实节点控制尚未接入。</span></div><section className="panel"><SectionTitle title="节点目录" detail={rows ? rows.length + ' 个测试记录' : '载入中'} /><div className="table-wrap"><table><thead><tr><th>节点</th><th>协议 / 地址</th><th>在线会话</th><th>延迟</th><th>状态</th><th>变更</th></tr></thead><tbody>{rows?.map(node => <tr key={node.id}><td><span className="node-cell"><span className="node-indicator" /><span><b>{node.name}</b><small>{node.region} · {node.is_test ? '测试记录' : '节点'}</small></span></span></td><td><code>{node.protocol}</code><small className="table-sub">{node.endpoint_host}:{node.endpoint_port}</small></td><td>{node.active_sessions} / {node.max_sessions}</td><td>{node.latency_ms ?? '—'} ms</td><td><StatusPill status={nodeStatus(node.status)} tone={node.status === 'available' ? 'green' : 'amber'} /></td><td><select aria-label={node.name + ' 状态'} value={node.status} onChange={event => void change(node, event.target.value as Node['status'])}><option value="available">可用</option><option value="maintenance">维护</option><option value="disabled">停用</option></select></td></tr>)}</tbody></table></div></section><div className="metric-grid compact-metrics"><Metric label="在线节点" value={rows?.filter(item => item.status === 'available').length ?? '—'} note="仅计算演示数据" icon={Server} tone="green" /><Metric label="分配会话" value={rows?.reduce((sum, item) => sum + item.active_sessions, 0) ?? '—'} note="样例计数" icon={Users} tone="teal" /><Metric label="监控探针" value="未接入" note="阶段 3 交付" icon={Activity} tone="amber" /><Metric label="变更审计" value="已启用" note="本地数据库" icon={ShieldCheck} tone="blue" /></div></div>;
}
function nodeStatus(status: Node['status']) { return ({ available: '可用', maintenance: '维护中', disabled: '已停用' } as const)[status]; }

function CommissionsPage({ refresh, notify }: { refresh: number; notify: (value: string) => void }) {
  const [rows, setRows] = useState<Commission[] | null>(null);
  useEffect(() => { api<Commission[]>('/api/v1/admin/commissions').then(setRows).catch(error => notify((error as Error).message)); }, [refresh, notify]);
  const total = useMemo(() => (rows ?? []).filter(row => row.status === 'pending').reduce((sum, row) => sum + row.amount_cents, 0), [rows]);
  return <div className="page-stack"><PageHeading eyebrow="运营管理" title="代理结算" subtitle="可追溯订单关联的演示佣金；不连接现有结算渠道，也不执行付款。" /><div className="notice notice-neutral"><ShieldCheck size={16} /><span>流水仅用于验证后台界面。正式账务应从现行结算事实源接入，不能在此创建第二本生产账。</span></div><div className="metric-grid compact-metrics"><Metric label="待核对流水" value={rows?.length ?? '—'} note="本地样例" icon={ArrowUpRight} tone="amber" /><Metric label="待结算合计" value={rows ? money(total) : '—'} note="不会实际打款" icon={CreditCard} tone="blue" /></div><section className="panel"><SectionTitle title="佣金流水" detail={rows ? rows.length + ' 条记录' : '读取中'} /><div className="table-wrap"><table><thead><tr><th>渠道</th><th>关联订单</th><th>金额</th><th>状态</th><th>创建时间</th></tr></thead><tbody>{rows?.map(row => <tr key={row.id}><td>{row.partner_name}</td><td><code>{row.order_id}</code></td><td>{money(row.amount_cents)}</td><td><StatusPill status={row.status === 'pending' ? '待核对' : row.status} tone="amber" /></td><td>{new Date(row.created_at).toLocaleString('zh-CN')}</td></tr>)}</tbody></table></div></section></div>;
}

function StatusPill({ status, tone }: { status: string; tone: string }) { return <span className={'status-pill ' + tone}><i />{status}</span>; }
