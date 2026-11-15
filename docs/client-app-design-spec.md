# 迅连宝多端客户端设计规格

## 当前设计资产

- Figma 文件：[迅连宝 VPN 多端客户端设计](https://www.figma.com/design/sT2OMdbcxZbWd5qcrKCYU2)
- 设计系统页：`00 · 品牌与设计系统`
- 目标平台页：`01 · Windows & macOS`、`02 · Android`
- 兼容与扩展页：`03 · Extension & Compatibility`，包含 Clash/Mihomo 桌面控制面和浏览器扩展订阅弹窗
- 当前 Figma 设计稿以品牌色、字体、连接状态和订阅流程为主；Starter 方案的 MCP 写入额度用尽后，桌面和 Android 画板改由本地实现预览继续推进。
- 2026-11-13 已在 Professional 计划下补齐并优化 `01 · Windows & macOS`、`02 · Android`：桌面端扩大内容区并统一盾牌/地球/订阅/设置矢量图标；Android 端放大品牌标识和正文层级，并明确“扫码导入 Clash / Mihomo”。
- 本机 Clash Verge Rev 版本为 `2.5.10`；实际 Mihomo 核心构建号、系统代理/TUN 权限和真实节点连接仍需在隔离测试环境验收。

## 视觉基线

- 品牌主色：`#147C66`；连接状态使用薄荷绿 `#59D0AD`。
- 深色画布：`#141D2D`；深色表面：`#202B3E`。
- 浅色画布：`#F5F8F6`；正文：`#182523`；辅助文字不低于 12px。
- 中文规格使用 Noto Sans SC；生产端优先使用系统中文无衬线字体，跟随系统字体缩放。
- 连接状态始终使用“状态文字 + 颜色 + 图形”三重表达，不能只依赖颜色。

## 共用信息架构

### 连接首页

展示连接状态、当前地区/节点、延迟、套餐流量、网络锁状态。主操作只有“连接/断开”，线路切换是次级操作。

### 线路选择

支持搜索、地区筛选、低延迟/流媒体/常用标签、智能线路。每个节点返回健康状态、延迟、负载和最近探测时间；没有健康数据时显示“待验证”，不显示“可用”。

### 会员与订阅

显示套餐到期时间、周期累计流量、设备数、固定订阅地址、二维码、复制链接、轮换凭证和兼容客户端。购买成功后由服务端自动准备固定订阅，客户端不提供“手工创建订阅”作为正常路径。

### 登录与账户同步

登录、注册、找回密码、设备列表、退出所有设备、主题切换。桌面端和 Android 共用账户 API，但平台凭证必须存储在系统安全存储中。

## 平台实现边界

| 平台 | UI/壳 | 网络层 | 需要单独验收的系统能力 |
| --- | --- | --- | --- |
| Windows | Tauri + React，参考 Clash Verge Rev 的窗口和托盘交互 | Mihomo sidecar | TUN/系统代理、驱动或服务权限、自启动、卸载清理、Authenticode 签名 |
| macOS | Tauri + React，保留原生窗口、菜单栏和深浅主题 | Mihomo sidecar | Network Extension/System Extension、沙盒权限、Developer ID 签名、公证 |
| Android | Kotlin/Compose 或现有 CMFA 封装，React 只承载可复用业务视图 | Mihomo Android 集成 | `VpnService` 授权、前台服务、通知、后台限制、Keystore、扫码唤起 |
| iOS（后续） | SwiftUI 原生壳 | Network Extension/Packet Tunnel Provider | Apple entitlement、审核、Keychain、App Store 合规 |

桌面 UI 不能直接当作 Android 客户端；Mihomo 是内核，系统代理/TUN/VPNService 仍需平台原生封装。

## 业务接口约定

客户端只访问业务 API，不持有云厂商密钥和节点管理权限：

- `POST /api/v1/auth/login`：登录并返回会话。自有客户端保留 session cookie；写请求先取 `/api/v1/auth/csrf` 并发送 `X-CSRF-TOKEN`，cookie 应保存在平台安全存储中。
- `GET /api/v1/me/overview`：账户、权益、流量、设备摘要。
- `GET /api/v1/me/subscriptions`：固定订阅凭证列表。
- `POST /api/v1/me/subscriptions`：幂等获取固定订阅；已存在有效凭证时返回原凭证。
- `POST /api/v1/me/subscriptions/{id}/rotate`：轮换并立即撤销旧凭证。
- `GET /api/v1/me/clients`：客户端目录、版本、下载源、二维码和教程地址。
- `GET /subscribe/{token}`：返回经会员权益和节点策略过滤后的 Clash/Mihomo 配置。
- `POST /api/v1/client/heartbeat`：会话认证后，按已登记的 `installation_id` 上报客户端版本和最近出现时间，不接收连接状态，也不上传明文订阅 token。该回报只证明客户端请求到达控制面，不证明节点握手或 VPN 已连接。

订阅配置接口需要支持 `User-Agent`、客户端格式和版本协商，并返回配置版本、过期时间、节点列表和策略组。节点真实开放前必须接入用户鉴权、流量计量和健康检查。

## 连接状态机

`idle → preparing → authorizing → connecting → connected → disconnecting → idle`

异常状态：`auth_required`、`node_unavailable`、`subscription_expired`、`permission_denied`、`config_invalid`、`network_blocked`。每个异常都要提供可执行的恢复动作，例如重新登录、切换节点、打开系统授权或刷新订阅。

## 后续开发顺序

1. 已在 React 本地预览实现深浅主题、桌面/窄屏、订阅格式/三模式默认配置、二维码、配置检查及扩展会员入口；实际实现与后续验收见 `client-compatibility-and-node-operations.md`。
2. 建立 Mihomo sidecar 管理协议：启动、健康检查、配置写入、日志读取、连接/断开、优雅停止。
3. 先完成 Windows/macOS 的本地假内核适配，再接真实 Mihomo；验证订阅更新、TUN/系统代理和断网保护。
4. 使用 CMFA 的 Android VPNService 封装实现扫码导入、订阅更新、前台服务和断线恢复。
5. 接入固定订阅、设备限制、流量计量和客户端心跳；通过真实设备验收后再把目录状态标记为“已验证”。
6. 生成可复现构建、SBOM、签名和更新清单，分别提交 Windows、macOS、Android 的杀毒/应用商店审核材料。

## 验收条件

- 未登录、会员过期、节点不可用和订阅轮换时，四个端的提示与恢复路径一致。
- 购买成功后不需要用户再次手工创建订阅；固定链接在续费后继续有效。
- 扫码导入、复制链接、Clash/Mihomo 配置刷新、断开和重新连接可在真实设备完成。
- 任何“已验证”状态都要有版本、设备、时间、配置版本和连接日志证据。
- 设计稿中的示例节点和流量必须保持演示标识，不能被误解为生产服务。
