import { useEffect, useRef, useState } from 'react';
import { Activity, ArrowUpRight, Check, Clipboard, FileText, Globe2, Monitor, QrCode, RefreshCw, Route, ShieldCheck, Smartphone } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import './subscription-tools.css';

type Subscription = { url: string | null; expires_at: string | null; last_used_at: string | null };
type Entitlement = null | { plan_name: string; ends_at: string; data_used_gb: number; traffic_gb: number; max_devices: number };
type Format = 'auto' | 'clash' | 'mihomo';
type Mode = 'rule' | 'global' | 'direct';
type Diagnosis = { status: 'idle' | 'checking' | 'ok' | 'error'; message: string; version?: string; checkedAt?: string };

function profileUrl(value: string, format: Format, mode: Mode): string {
  const url = new URL(value, window.location.origin);
  if (url.origin !== window.location.origin || !url.pathname.startsWith('/subscribe/')) throw new Error('订阅地址来源不受信任');
  if (format !== 'auto') url.searchParams.set('format', format);
  url.searchParams.set('mode', mode);
  return url.toString();
}

function importUrl(value: string): string {
  const url = new URL('clash-verge://install-config');
  url.searchParams.set('name', '迅连宝 · 本地格式测试');
  url.searchParams.set('url', value);
  return url.toString();
}

const date = (value: string) => new Date(value).toLocaleDateString('zh-CN');
const modes: Array<{ id: Mode; title: string; detail: string }> = [
  { id: 'rule', title: '规则', detail: '按配置中的规则分流' },
  { id: 'global', title: '全局', detail: '由客户端全局代理组接管' },
  { id: 'direct', title: '直连', detail: '直接访问，不经过代理' },
];

export function SubscriptionImport({ subscription, entitlement, notify, initialQr = false }: {
  subscription: Subscription; entitlement: Entitlement; notify: (message: string) => void; initialQr?: boolean;
}) {
  const query = new URLSearchParams(window.location.search);
  const [format, setFormat] = useState<Format>(() => ['clash', 'mihomo'].includes(query.get('format') ?? '') ? query.get('format') as Format : 'auto');
  const [mode, setMode] = useState<Mode>('rule');
  const [showQr, setShowQr] = useState(initialQr);
  const [reveal, setReveal] = useState(false);
  const [diagnosis, setDiagnosis] = useState<Diagnosis>({ status: 'idle', message: '可检查订阅接口和配置版本；不发起节点连接。' });
  const checkRef = useRef<AbortController | null>(null);
  const url = subscription.url ? profileUrl(subscription.url, format, mode) : '';
  const allowed = !!entitlement && entitlement.data_used_gb < entitlement.traffic_gb && (!subscription.expires_at || Date.parse(subscription.expires_at) > Date.now());

  useEffect(() => {
    setDiagnosis({ status: 'idle', message: '可检查订阅接口和配置版本；不发起节点连接。' });
    setReveal(false);
    return () => checkRef.current?.abort();
  }, [url]);

  const copy = async () => {
    try { await navigator.clipboard.writeText(url); notify('所选格式的固定订阅已复制'); }
    catch { notify('复制失败，请显示地址后手动复制'); }
  };
  const check = async () => {
    checkRef.current?.abort();
    const controller = new AbortController();
    checkRef.current = controller;
    let timedOut = false;
    const timeout = window.setTimeout(() => { timedOut = true; controller.abort(); }, 10000);
    setDiagnosis({ status: 'checking', message: '正在检查订阅接口…' });
    try {
      const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin', signal: controller.signal });
      if (!response.ok) throw new Error(response.status === 404 ? '订阅暂不可用：请检查会员权益、流量和节点状态。' : `配置检查失败（HTTP ${response.status}）`);
      if (!response.headers.get('Content-Type')?.includes('yaml') || response.headers.get('X-XLB-Profile-Scope') !== 'local-test') throw new Error('返回的配置类型或范围与当前测试环境不一致。');
      await response.text();
      setDiagnosis({ status: 'ok', message: '测试配置可下载；真实节点连接尚未验收。', version: response.headers.get('X-XLB-Config-Version') ?? '未提供', checkedAt: new Date().toLocaleTimeString('zh-CN') });
    } catch (reason) {
      if (timedOut) setDiagnosis({ status: 'error', message: '配置检查超时，请稍后重试。' });
      else if (!controller.signal.aborted) setDiagnosis({ status: 'error', message: (reason as Error).message });
    } finally {
      window.clearTimeout(timeout);
    }
  };

  return <div className="subscription-tools">
    <div className="tools-field-label"><b>订阅格式</b><span>同一凭证，续费后地址沿用</span></div>
    <div className="tools-formats" role="group" aria-label="订阅格式">
      {([{ id: 'auto', label: '自动识别' }, { id: 'clash', label: 'Clash' }, { id: 'mihomo', label: 'Mihomo' }] as const).map(item => <button key={item.id} type="button" aria-pressed={format === item.id} onClick={() => setFormat(item.id)}>{format === item.id && <Check size={16} />}{item.label}</button>)}
      <button type="button" disabled title="Sing-box JSON 格式尚未开放">Sing-box · 待支持</button>
    </div>
    <fieldset className="tools-mode-field"><legend><Route size={19} />导入时的默认模式</legend><div className="tools-modes">{modes.map(item => <label key={item.id} className={mode === item.id ? 'selected' : ''}><input type="radio" name="profile-mode" value={item.id} checked={mode === item.id} onChange={() => setMode(item.id)} /><span><b>{item.title}</b><small>{item.detail}</small></span></label>)}</div><p>此设置只影响订阅配置。当前网页不会更改本机 Clash Verge、系统代理或 TUN。</p></fieldset>
    <div className="tools-address"><code aria-label="固定订阅地址">{reveal ? url : `${window.location.origin}/subscribe/••••••••`}</code><button type="button" className="client-quiet-button" onClick={() => setReveal(!reveal)}>{reveal ? '隐藏' : '显示'}</button></div>
    <div className="tools-import-actions"><button type="button" className="client-primary" disabled={!url} onClick={() => void copy()}><Clipboard size={18} />复制链接</button><button type="button" className="client-secondary" disabled={!url} onClick={() => setShowQr(!showQr)}><QrCode size={19} />{showQr ? '收起二维码' : '扫码导入'}</button>{url && allowed && <button type="button" className="client-secondary" onClick={() => { window.location.href = importUrl(url); }}><ArrowUpRight size={18} />导入 Clash Verge</button>}</div>
    {!allowed && <p className="tools-warning">凭证可以提前保存；需要有效会员及剩余流量才能获取配置。</p>}
    {showQr && url && <div className="tools-qr"><div><QRCodeSVG value={url} size={176} level="M" title="所选格式的订阅二维码" /></div><span><b>在兼容客户端中扫码导入</b><small>{['127.0.0.1', 'localhost', '[::1]'].includes(window.location.hostname) ? '当前地址仅本机可达，手机扫码暂不可用。请在可访问的服务域名上线后验收。' : '扫描后核对订阅来源。二维码包含私密凭证，请勿分享。'}</small><small>若系统未唤起客户端，可使用“订阅 → 从 URL 导入”。</small></span></div>}
    <div className={'tools-diagnosis ' + diagnosis.status}><Activity size={21} /><span role="status"><b>{diagnosis.message}</b>{diagnosis.version && <small>配置版本 {diagnosis.version} · {diagnosis.checkedAt}</small>}</span><button type="button" className="client-secondary" disabled={!url || diagnosis.status === 'checking'} onClick={() => void check()}><RefreshCw size={16} />检查配置</button></div>
    <p className="tools-footnote">当前导出的是本地格式测试配置，含保留地址，不能用于真实上网。订阅地址等同凭证，请勿公开。</p>
  </div>;
}

export default function CompatibilityPanel({ subscription, entitlement, notify, onSubscription }: {
  subscription: Subscription | null; entitlement: Entitlement; notify: (message: string) => void; onSubscription: () => void;
}) {
  const ratio = entitlement && entitlement.traffic_gb > 0 ? Math.min(100, entitlement.data_used_gb / entitlement.traffic_gb * 100) : 0;
  return <div className="client-view-stack compatibility-view">
    <div className="client-page-title"><div><span>跨端连接</span><h1>兼容与连接</h1><p>为你的客户端选择订阅格式，检查导入前的准备情况。</p></div><a href="/account?view=clients" className="client-secondary"><Monitor size={19} />客户端下载<ArrowUpRight size={16} /></a></div>
    <div className="compatibility-summary">
      <section className="compatibility-card"><div className="compatibility-heading"><span className="tools-card-icon"><FileText size={25} /></span><span><h2>{entitlement?.plan_name ?? '尚未开通套餐'}</h2><p>{subscription?.url ? '固定订阅已就绪' : '等待准备订阅'}</p></span></div><div className="compatibility-usage"><b>{entitlement?.data_used_gb ?? 0}<small> / {entitlement?.traffic_gb ?? '—'} GB</small></b><span>套餐周期累计</span></div><div className="compatibility-progress" role="meter" aria-label="套餐流量用量" aria-valuemin={0} aria-valuemax={100} aria-valuenow={ratio}><i style={{ width: `${ratio}%` }} /></div><p>{entitlement ? `有效期至 ${date(entitlement.ends_at)}` : '前往会员中心选择套餐'}</p></section>
      <section className="compatibility-card"><div className="compatibility-heading"><span className="tools-card-icon blue"><Globe2 size={25} /></span><span><h2>当前连接</h2><p>状态以实际客户端为准</p></span></div><div className="compatibility-node"><b>尚未接入连接内核</b><span>当前没有会员可用线路、延迟和会话数据</span></div><div className="compatibility-platforms"><span><Monitor size={18} />Windows / macOS</span><span><Smartphone size={18} />Android</span></div></section>
    </div>
    <section className="compatibility-card"><div className="compatibility-heading"><span className="tools-card-icon blue"><Route size={25} /></span><span><h2>客户端导入与模式</h2><p>Clash / Mihomo YAML · 基础模式配置</p></span></div>{subscription?.url ? <SubscriptionImport subscription={subscription} entitlement={entitlement} notify={notify} /> : <div className="tools-empty"><p>先为当前账户获取固定订阅，即可选择格式、复制链接或扫码导入。</p><button type="button" className="client-primary" onClick={onSubscription}>前往订阅与设备<ArrowUpRight size={17} /></button></div>}</section>
    <section className="compatibility-card"><div className="compatibility-heading"><span className="tools-card-icon"><ShieldCheck size={25} /></span><span><h2>本机连接能力</h2><p>系统权限由安装的客户端负责申请和管理</p></span></div><div className="compatibility-runtime"><div><b>系统代理</b><span>网页无法控制</span></div><div><b>TUN 模式</b><span>等待原生客户端接入</span></div><div><b>流量与连接日志</b><span>尚无真实采集数据</span></div></div></section>
  </div>;
}
