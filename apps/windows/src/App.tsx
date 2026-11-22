import { FormEvent, useEffect, useState } from 'react';
import { Activity, ArrowDownToLine, BadgeCheck, CircleHelp, LoaderCircle, LogOut, RefreshCw, ShieldCheck, UsersRound, WifiOff } from 'lucide-react';
import { AccountSnapshot, native } from './native';

function dateLabel(value: string) {
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? '—' : new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium' }).format(date);
}

function App() {
  const [account, setAccount] = useState<AccountSnapshot | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [localCredentialsAvailable, setLocalCredentialsAvailable] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    void Promise.all([native.restore(), native.localTestCredentials()])
      .then(([snapshot, credentials]) => {
        if (!active) return;
        setAccount(snapshot);
        setLocalCredentialsAvailable(credentials !== null);
      })
      .catch((reason: unknown) => {
        if (active) setError(reason instanceof Error ? reason.message : '无法连接会员服务');
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => { active = false; };
  }, []);

  async function refreshAccount() {
    setWorking(true);
    setError('');
    try {
      const snapshot = await native.restore();
      setAccount(snapshot);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '账户状态读取失败');
    } finally {
      setWorking(false);
    }
  }

  async function submitLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setWorking(true);
    setError('');
    try {
      setAccount(await native.login(email, password));
      setPassword('');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '登录失败，请检查网络后重试');
    } finally {
      setWorking(false);
    }
  }

  async function fillLocalCredentials() {
    setError('');
    try {
      const credentials = await native.localTestCredentials();
      if (!credentials) {
        setLocalCredentialsAvailable(false);
        return;
      }
      setEmail(credentials.email);
      setPassword(credentials.password);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '本地测试账号不可用');
    }
  }

  async function signOut() {
    setWorking(true);
    setError('');
    try {
      await native.logout();
      setAccount(null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : '退出失败，本机凭据已清除');
      setAccount(null);
    } finally {
      setWorking(false);
    }
  }

  if (loading) {
    return <main className="loading-screen"><LoaderCircle className="spin" size={22} /><span>正在恢复账户会话</span></main>;
  }

  if (!account) {
    return (
      <main className="login-screen">
        <section className="login-panel">
          <div className="brand-mark"><Activity size={20} /></div>
          <p className="eyebrow">XLB DESKTOP</p>
          <h1>登录会员账户</h1>
          <p className="login-copy">使用会员中心账户查看权益与设备状态。</p>
          {error && <div role="alert" className="notice error-notice">{error}</div>}
          <form onSubmit={submitLogin} className="login-form">
            <label htmlFor="email">邮箱</label>
            <input id="email" type="email" autoComplete="username" value={email} onChange={event => setEmail(event.target.value)} required />
            <label htmlFor="password">密码</label>
            <input id="password" type="password" autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} required />
            <button className="primary-button login-submit" type="submit" disabled={working}>
              {working ? <LoaderCircle className="spin" size={17} /> : <ShieldCheck size={17} />}
              登录
            </button>
          </form>
          {localCredentialsAvailable && <button className="local-test-button" type="button" onClick={() => void fillLocalCredentials()}>填入本地测试账号</button>}
          <div className="security-caption"><ShieldCheck size={15} /><span>会话凭据由本机安全存储保护</span></div>
        </section>
      </main>
    );
  }

  const entitlement = account.entitlement;
  const usagePercent = entitlement && entitlement.traffic_gb > 0
    ? Math.min(100, Math.max(0, (entitlement.data_used_gb / entitlement.traffic_gb) * 100))
    : 0;

  return (
    <main className="app-shell">
      <aside className="sidebar">
        <div className="brand-lockup"><span className="brand-mark"><Activity size={19} /></span><span><b>XLB</b><small>会员客户端</small></span></div>
        <div className="nav-label">工作区</div>
        <div className="nav-item active"><Activity size={17} /><span>账户总览</span></div>
        <button className="nav-item nav-button" type="button" onClick={() => document.getElementById('device-list')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}><UsersRound size={17} /><span>设备管理</span></button>
        <div className="sidebar-spacer" />
        <div className="sidebar-help"><CircleHelp size={17} /><span>连接能力尚未开放</span></div>
        <div className="account-chip"><span className="avatar">{account.user.name.slice(0, 1)}</span><span className="account-copy"><b>{account.user.name}</b><small>{account.user.account_type === 'anonymous' ? '匿名账号 · 未绑定邮箱' : account.user.email ?? '会员账户'}</small></span><button type="button" aria-label="退出账户" title="退出账户" onClick={() => void signOut()} disabled={working}><LogOut size={16} /></button></div>
      </aside>

      <section className="main-pane">
        <header className="topbar"><div><span className="eyebrow">账户 / 总览</span><h1>会员中心</h1></div><button className="icon-button" type="button" onClick={() => void refreshAccount()} disabled={working} aria-label="刷新账户状态" title="刷新账户状态">{working ? <LoaderCircle className="spin" size={18} /> : <RefreshCw size={18} />}</button></header>
        {error && <div role="alert" className="notice error-notice">{error}</div>}

        <div className="status-banner"><span className="status-icon"><WifiOff size={19} /></span><span><b>服务线路暂不可用</b><small>此版本仅提供账户与设备状态；线路和连接功能尚未接入。</small></span><button type="button" disabled aria-disabled="true">连接</button></div>
        <div className="authorization-status" role="status">节点授权：{account.nodeAuthorization === 'ready' ? '节点授权已就绪' : account.nodeAuthorization === 'provisioning' ? '正在申请节点授权' : '暂不可用'}。这不代表 VPN 已连接。</div>

        <section className="section-heading"><div><h2>会员权益</h2><p>账户资料由会员服务实时读取</p></div><span className="live-label"><span />API 同步</span></section>
        {entitlement ? (
          <section className="membership-panel">
            <div className="membership-main"><span className="membership-icon"><BadgeCheck size={20} /></span><span><small>当前套餐</small><b>{entitlement.plan_name}</b></span><span className="entitlement-state">{entitlement.status === 'active' ? '有效' : entitlement.status}</span></div>
            <div className="membership-facts"><div><small>有效期至</small><b>{dateLabel(entitlement.ends_at)}</b></div><div><small>已用流量</small><b>{entitlement.data_used_gb.toFixed(1)} <i>/ {entitlement.traffic_gb} GB</i></b></div><div><small>设备额度</small><b>{account.devices.registered_count} <i>/ {account.devices.max_devices} 台</i></b></div></div>
            <div className="usage-track" role="progressbar" aria-label="会员流量使用比例" aria-valuenow={Math.round(usagePercent)} aria-valuemin={0} aria-valuemax={100}><span style={{ width: `${usagePercent}%` }} /></div>
          </section>
        ) : (
          <section className="membership-panel empty-membership"><span className="membership-icon"><BadgeCheck size={20} /></span><span><b>暂无有效会员权益</b><small>开通权益后，套餐信息会显示在这里。</small></span></section>
        )}

        <section className="section-heading device-heading"><div><h2>已登记设备</h2><p>设备额度按会员 API 返回值展示</p></div><span className="device-count">{account.devices.registered_count} / {account.devices.max_devices} 台</span></section>
        <section className="device-list" id="device-list">
          {account.devices.devices.length ? account.devices.devices.map(device => (
            <article className="device-row" key={device.id}><span className="device-icon"><ArrowDownToLine size={17} /></span><span className="device-name"><b>{device.name}</b><small>{device.platform} · {dateLabel(device.created_at)}</small></span><span className="device-state">{device.status === 'registered' ? '已登记' : device.status}</span></article>
          )) : <div className="empty-devices">当前没有已登记设备</div>}
          <footer className="device-footer"><span>{account.devices.available_slots} 个可用名额</span><span>本阶段不支持添加或移除设备</span></footer>
        </section>

        <footer className="workspace-footer"><span>账号 {account.user.email ?? account.user.id}</span><span>连接与线路状态不在本页面模拟</span></footer>
      </section>
    </main>
  );
}

export default App;
