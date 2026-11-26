# iGuge 内核边界与节点稳定性研究

初稿日期：2026-11-21；证据复核：2026-11-22；AWS 会话状态更新：2026-11-24。范围：根据仓库现有设计/验收记录、用户提供的 Clash Verge 截图和本地代码做桌面研究；未访问 iGuge 私有插件数据、未查询生产节点。本文描述证据能支持的结论，不把代码存在等同于生产部署。

## 结论摘要

- **客户端内核决策已定：维持 Mihomo。** 用户提供的 Clash Verge 设置截图显示 `alpha-69c1631 Mihomo`；这指向用户安装的 Clash Verge 客户端内核，不是 iGuge 服务端内核。它与本项目锁定验证的 Mihomo `v1.19.34` 属同一 Mihomo 家族。用户已明确选择继续使用 Mihomo。仅凭截图换内核没有收益，却会改变平台封装、proof bridge、配置、许可证与验收体系。
- **内核由客户端软件决定，不由订阅 URL 决定。** 订阅返回节点、规则和代理组等配置数据，不携带或安装内核。同一订阅可由 Clash Verge/Mihomo 解析，也可由 v2rayN 所使用的 Xray 或 sing-box 客户端解析，前提是格式和协议兼容。看到 Mihomo 是因为 Clash Verge 带有 Mihomo 核心，与 iGuge 返回的订阅内容无关。
- **iGuge 扩展不是 VPN 内核载体。** 项目既有材料只记录其可见账户、VIP、订阅分发和兼容客户端导入路径；无法据此核实扩展内部实现，更不能宣称扩展承担隧道数据面。iGuge 桌面/移动客户端使用何种服务端协议或节点内核，当前没有直接证据。
- **节点稳定性暂无生产结论。** 仓库里的 Node Access Agent 明确拒绝 production mode；除历史隔离 TCP 记录外，现已有 Go/PHP 本机 TLS 1.3/mTLS challenge 测试、持久健康状态 CAS、默认关闭的健康命令/15 秒 scheduler 与可选路由过滤，但 runnable Agent 没有真实 `DataPlaneProbeExecutor`，探测 listener 仍返回 `503 probe_unavailable`。这些证明代码合同而非已部署探测或生产会员节点长期稳定。协议封锁/抗审查原因仍是待证假设。

## A. 内核与产品形态

### 术语与证据边界

| 项目 | 结论 | 证据强度 |
| --- | --- | --- |
| Clash Verge 截图中的 `alpha-69c1631 Mihomo` | 用户侧 Clash Verge 客户端选用 Mihomo alpha 构建；`alpha` 是发布通道标签，`69c1631` 是构建提交标识，`Mihomo` 是核心名称 | 用户提供截图，直接用户侧证据；不代表本项目对该构建做过验收 |
| 本项目内核 | Mihomo `v1.19.34` 已用于本地配置解析联验；客户端运行和系统隧道仍有平台适配及实机放行门 | 仓库锁定版本、配置解析测试记录；不是全平台生产验收 |
| 订阅地址 | 传递配置数据，不决定使用何种核心 | 配置格式/客户端分层及现有订阅接口契约 |
| V2rayN | 开源图形客户端，不是协议，也不自带节点；按导入格式调用可用核心（如 Xray/sing-box）。所谓“iGuge 可用 V2rayN”通常指订阅可导入兼容客户端 | 通用产品术语与项目兼容性文档；没有据此证明某个 iGuge 节点协议 |
| iGuge 浏览器扩展 | 既有对标记录描述账户识别、VIP/付款入口和订阅分发。浏览器扩展负责分发不等于有 VPN 数据面内核 | 项目既有用户可见行为记录；本机 CLI 不能读取用户浏览器安装的闭源插件，也未核验其后台 |
| iGuge 服务端/节点内核与传输 | 从 Clash Verge 截图和订阅可导入 V2rayN 都无法推断。不要把客户端核心名称归因给 iGuge 服务端 | 无直接服务端证据 |

### 用户侧客户端内核与节点侧实现对照

| 层次 | Mihomo / Clash Verge | 本项目当前证据 | 判断 |
| --- | --- | --- | --- |
| 客户端外壳 | Clash Verge 管理配置、系统代理/TUN 和内置 Mihomo | 自有 App 计划以 Mihomo core-native + 原生平台隧道接口提供客户端；iGuge 扩展属于账户与分发层 | 外壳、核心、订阅和服务端协议分别验收 |
| 用户侧配置核心 | Mihomo；截图显示的 alpha commit 与项目 `v1.19.34` 同家族 | renderer fixture 已由 Mihomo `v1.19.34` 解析；未证明所有系统代理/TUN、DNS、UDP 或多端体验 | 沿用 Mihomo，固定版本矩阵；不因别的产品可导入 V2rayN 就换核心 |
| 节点传输/服务端 | 截图不包含服务端信息 | 2026-11-14 隔离公网节点用 sing-box `1.14.4` + Shadowsocks 2022，macOS/Android 指定客户端完成有限 HTTPS/TCP 验收；另有自研 Go Node Access Agent 的 TLS 1.3/HTTP2+mTLS proof 数据面隔离测试 | 这是两条不同测试路径；不能据此声称已有生产节点，也不能断言 iGuge 使用其中任一方案 |
| 设备授权 | 通用 Clash/Mihomo/V2rayN 导入本身不能签本项目的设备 challenge | 自研 proof bridge 用设备证书和不可导出 signer 证明设备身份；Agent 只允许 `isolated-test` | 严格限设备仍是核心要求；普通客户端不得标记为严格限设备已支持 |

### 已决的内核选择

**决策：本项目维持 Mihomo，不切换内核。** 依据是用户提供的 Clash Verge 截图显示 Mihomo，和仓库当前锁定的 Mihomo `v1.19.34` 同属 MetaCubeX/Mihomo 家族，且用户明确拍板继续使用。换内核会牵动 Android/桌面系统隧道适配、profile renderer、proof bridge 路由、依赖许可和整套回归验收；目前没有可验证收益。本决策关闭当前“是否换内核”研究项。

现有《客户端兼容、订阅分发与节点运维方案》的内核决策门仍适用于未来重大版本升级或新增平台：届时可做小型可替换验证，比较跨平台能力、系统隧道接口、更新维护、崩溃恢复、许可证与二进制分发义务；评审通过前不把多个内核同时嵌入正式客户端，不自行发明服务端加密/混淆协议，也不改变设备证明和撤权门槛。

## B. 节点频繁异常：根因清单

当前没有已发布的生产会员节点可供归因，因此下表把可证事实、可能影响和待验证假设分开。所谓“节点异常”必须进一步区分：订阅拉取、设备授权、隧道握手、会话续租、DNS/UDP、出口网络、撤权回执和客户端系统隧道，不能只用一个“连接失败”状态覆盖。

| 优先级 / 可能原因 | 已验证事实与证据 | 尚未验证 / 对体验的可能影响 | 下一步与验证方式 |
| --- | --- | --- | --- |
| P0：生产服务尚未部署完整 | `apps/node-agent/cmd/node-agent` 启动时强制 `NODE_AGENT_MODE=isolated-test`，否则退出；PRD 记 production mode、生产 mTLS 与 bridge 安全区交付未完成 | 因此仓库不能解释或保证某个真实会员生产节点的稳定性；若用户报告来自另一套服务，需要取得其版本/部署和脱敏事件才能归因 | 先建立节点/线路/客户端关联的故障事件，不把本地隔离测试等同生产诊断；需要正式接入前置外部材料和放行门 |
| P0：实验节点 TTL 到期 | `ops/aws-node-lab.template.json` 创建带 `ExpiresAt` 标签和 EventBridge Scheduler 的限时测试实例；runbook 明确到期终止实例、调度可能延迟、Scheduler 终止实例不自动删除整栈。2026-11-08 和 11-14 实验记录分别实际观察过终止/整栈回收 | 这是隔离实验资源生命周期，不是生产会员节点 TTL。若把实验线路当长期线路，连接中断是预期结果；目前没有证据显示 TTL 造成生产用户故障 | 离线：在 API/客户端区分测试线路和会员线路并展示截止时间。真实部署：核实资源标签、实例状态和 CloudTrail，不从旧资源记录推断当前状态 |
| P0：AWS 运维会话过期 | 2026-11-24 经项目包装器检查返回 session expired；2026-11-23 的成功身份与资源盘点仅是历史快照，不能证明当前会话/资源状态 | 会话过期会阻止管理面查询/部署/回收，不等于已运行节点的数据隧道自动断开；没有因该错误造成节点中断的证据 | CLI 始终用 `ops/aws-cli`；过期后由用户本人重新 `aws login`，随后先只读盘点和复核获批 plan。控制面区分“凭据失效”和“节点不健康”；真实节点故障时另查节点事件和客户端握手 |
| P1：健康状态新鲜度/真实探测执行器缺失 | `subscription_routes.health_check_ttl_seconds` 默认 300 秒，供既有节点健康字段筛选；独立探测路由状态 TTL 默认 30 秒。M10 已有 `ConfiguredNodeDataPlaneProbeTransport`、`MtlsNodeHealthProbeDriver`、SQLite CAS 状态仓库及 `nodes:probe-health` 命令；Laravel scheduler 仅在显式启用且 driver 已绑定时每 15 秒调度。PHP transport 已在 loopback TLS 1.3/mTLS listener 和 test-only Go listener 上做过集成测试。`cmd/node-agent` 对没有 executor 的探测 listener 返回 `503 probe_unavailable`；probe/route filter 默认关闭 | 两种 TTL 分属不同状态源；旧 300 秒 `last_health_check_at` 过期可使节点不可选，但不单独证明已有 TCP 流中断。代码中存在 scheduler 不证明受控运行环境确实常驻执行；HTTP fake、test-only echo 和本机 mTLS 互通也都不是节点健康观测 | 离线：保持 fail closed，补充 scheduler/状态新鲜度集成测试与部署运行合同，不用 fake executor 写成功健康时间。真实节点：由批准环境提供 node ID、endpoint、server cert、client cert/key 与 CA 映射，实现受信 executor/目标 challenge 交换；核对超时/证书失败、状态持久化、误报阈值和受控 scheduler 实际运行 |
| P1：故障切换、线路调度不足 | `SubscriptionRouteService` 支持固定版本、canary/published、fresh health 过滤、draining 和回滚；local/testing 账号级兼容 profile 现有 Mihomo `url-test`、`fallback` 与手动 `select` 组，候选来自解析后的 route version 节点；主线 CI `45287506548` 使用锁定 Mihomo v1.19.34 对生成配置运行 `-t`，CI `45289769190` 覆盖不健康/过期节点排除，每设备 peer profile 仍由服务端生成并原样交付。现有代码只在 `subscription-routes:reconcile` 收口到期 drain，不等于节点探测器 | 只有配置解析和候选健康过滤证据；没有真实健康节点或故障注入，不证明生产自动切换和时延 | 离线：补 route-version 故障注入 fixture。真实环境：同线路至少两台健康节点做故障注入和切换时延验收；每次严格 peer 线路切换仍需撤旧 peer、重开新 peer并取回执 |
| P1：会话租约或网络抖动 | Node Agent 隔离实现的 challenge 和 session lease 为 2 分钟；bridge 心跳间隔 20 秒，续租由 Agent 校验设备证书并检查 peer 状态。2026-11-26 本地 bridge 回归覆盖两个并发 SOCKS 流共享 session、关闭一个时另一条仍续租、最后一条结束时发送 disconnect 并关闭 idle HTTP/2 连接，以及空闲后重新建立 session；定向 race test 连续 20 次通过 | 这验证本机 isolated HTTP/2 生命周期，不覆盖生产网络丢包、Android 进程休眠/恢复、证书/时钟偏差或跨平台断网恢复；没有生产节点故障归因 | 离线：继续测试 heartbeat 超时/服务端拒绝和错误分类；真实设备/节点：前后台切换、Wi-Fi/蜂窝切换、时钟偏差和节点重启场景留证 |
| P1：容量或资源耗尽 | peer-store 在本地跨进程状态锁内原子检查容量；记录有 12 个进程竞争 3 个并发名额恰好 3 个成功。容量不足会返回明确拒绝。隔离协议 harness 的早期 M6 拒绝曾因未证明有空位而不能归因身份 | 该测试证明原子名额边界，不证明小规格实例长时 CPU/内存/连接数、文件描述符、带宽、连接跟踪表或真实并发行为；容量耗尽会表现为拒连但不是身份复制防护 | 离线：补有界压力模型、资源使用指标契约和稳定容量拒绝。真实节点：按目标容量做分阶段持续测试，记录 CPU、RSS、FD、活跃 peer、建立成功率与 p95/p99，不超 `$1` 已批准单小时窗口 |
| P2：协议被识别/出口或运营商质量 | 已有隔离 TCP/SS2022 的指定网络请求成功；没有国内多运营商、跨时段、UDP、IPv6、持续丢包或协议封锁测试 | DPI/主动探测、端口封锁、丢包、运营商互联、节点出口限速均是可能性，**当前无证据确认**。也不能由“某次连不上”推导被审查封锁 | 先测跨运营商 TCP/TLS 握手、丢包和出口；若证据指向协议阻断，再按兼容性决策门比较候选协议。Reality/uTLS 不在当前稳定性首要投入，不自研混淆 |
| P2：DNS/UDP/IPv6 与系统 VPN 集成 | 2026-11-14 实机记录明确未覆盖 UDP、IPv6、DNS 泄漏和自有 VPNService/TUN；本地 Mihomo 配置解析只验证 YAML/配置结构 | 特定应用失败可能是 DNS、UDP 或系统隧道，不一定是节点崩溃；当前未测不能视为支持 | 离线：完善客户端分层错误状态。真机：按 Android/Windows/macOS 分别验证 DNS、IPv4/6、UDP、系统代理/TUN 与失败恢复 |
| P2：探测链自身间歇超时 | pd02 维护验收共 310 次检查中 307 通过、3 次超时，全部发生在恢复健康后的收尾采样，后续 6 次重测通过；报告明确原因未完全定位。此为 Web/ALB 探测，不是 VPN 节点 | 它证明外部探测自身会间歇超时，不能归因 VPN 节点，也不能证明用户不可用 | 探测需记录 DNS、TCP connect、TLS、HTTP 分层耗时和目标，再与服务端指标关联；避免单次探针失败立刻引发线路抖动 |

## C. 稳定性改进优先级

| 优先级 | 改进 | 可离线推进 | 需真实节点/额外条件 |
| --- | --- | --- | --- |
| P0 | 统一故障分类与证据链：`auth_denied`、`capacity`、`health_stale`、`connect_timeout`、`tls_handshake`、`target_unavailable`、`quota_unavailable`、`client_tunnel`；关联脱敏 node/route/config/request ID 与时间 | Android 已有 allowlisted 本地 tunnel/API authorization support code；2026-11-25 PHP middleware 生成服务端 UUIDv4 `X-Request-Id` 并写入请求作用域日志上下文，Web 与 Android 错误均展示经 UUIDv4 校验的引用。Node provisioning/revoke/quota operation `request_id` 与 outbox/Agent receipt 有既有幂等校验；`node_operation_id` 现由 Android 显示，可直接查询该 peer operation。该 operation ID 尚未与入口 HTTP request ID 建立映射，亦无持久故障事件或 telemetry | 离线评估并测试 HTTP request→异步 outbox operation ID 的来源映射与可查询边界，重试沿用同一 operation ID；真实生产事件、监控及上传遥测还需部署/隐私审查与用户同意 |
| P0 | 把“看起来不稳定”拆成订阅、授权、握手、出口、DNS、流量和撤权状态；未知仍显示未知，不把健康探针通过等同隧道健康 | 文案/状态机/诊断摘要 | 用真实用户同意的脱敏事件验证可诊断性 |
| P1 | 将 M10 fail-closed 探测合同接入真实 Agent executor 与受控 scheduler；保留连续失败/恢复熔断、CAS 和独立状态 TTL | HTTP/TLS transport、driver、CAS、一次性命令和条件 15 秒调度均有离线测试；本地 Feature 已串联合成连续失败/恢复阈值、持久熔断状态与账号级 profile 过滤，确认不健康时不发线路、未达到恢复阈值前仍 fail-closed，且不污染 `nodes.health_status` 与 `last_health_check_at` | 必须有获批节点身份/端点/证书与真实数据面 canary 目标；先验证误报阈值，再考虑开启真实路由过滤 |
| P1 | route failover：`AUTO/url-test`、`fallback`、手动选择按版本化线路发布；故障节点停止新分配，旧线路受控 drain/rollback | local/testing 账号级 profile 的 Mihomo group 结构、route version 候选绑定和 pinned Mihomo parser 已验证；本地回归覆盖健康变化后下一次 profile 拉取剔除候选、管理 API 发布/回滚时订阅候选同步、canary 无健康候选时回退仍健康的 published route，以及拒绝把不健康上一版本重新发布。测试不执行 Mihomo 网络探测、不切换已建立隧道，也不测客户端更新周期或切换时延 | 最少两台隔离节点、持续故障注入、切换时延与 peer 撤销回执 |
| P1 | 确认生产 scheduler/worker 常驻、积压/租约/失败告警和资源逾期盘点；现在本地 worker 关闭不等于线上运行 | 部署清单、健康状态 API 与 worker 心跳测试 | 需专用受审环境和运维身份，不在本轮创建云资源 |
| P1 | 对本机 bridge 的心跳/重连增加有界指数退避与抖动，区分离线、凭据过期、peer 撤权、节点不可达；保持撤权/配额未知时失败关闭 | 可在 fake Agent 测断线/恢复/续租边界并 race-test | 真机前后台、网络切换、真实节点重启的跨平台验收 |
| P2 | 建立长稳/容量验收：分阶段增加连接和时长，固定请求速率与数据量，观测 CPU/RSS/FD/握手成功率/流量结算 | 可先扩展本地 fake load harness 和指标接口 | 真实节点规格及成本授权；不以小型本地测试推导生产容量 |
| P3 | 仅在多运营商证据支持时评估抗识别协议/传输 | 可做许可证/兼容性桌面研究 | 要用户明确产品方向、法务/供应商审查和独立隔离网络试验；不作为默认方案 |

## D. 后续放行界线

1. 不能以单次 HTTPS、M5 12 争 3、YAML parse、Mihomo UI 可启动或服务端 `health_status=healthy` 单独宣称“稳定”。
2. 稳定性验收至少包含持续探测、长时流量、主动断网/恢复、DNS/UDP/IPv6（支持时）、容量边界、两台 proof 身份、撤权最终回执和故障切换；未覆盖项明确标未验证。
3. AWS 创建前重新只读核对资源，再审查 `ops/node_lab.py` plan；只执行已批准的 us-west-2、单台 t3.small、最长 60 分钟和 `$1` 预算。测试后删栈并逐项核对，不扩大、不过期延长、不以 Scheduler 终止实例等同整栈回收。
4. 无匹配节点撤权回执不宣称已断流；账号级订阅、test Agent、管理员线路和 CDN 均不能替代严格设备证明或 VPN 数据通道。

## E. iGuge 式体验与本项目差距

状态基于本仓库代码/文档，不表示线上已开放。iGuge 侧仅对照项目记录的用户可见流程和用户截图；没有直接读取用户浏览器插件或验证 iGuge 后台。

| 用户可见维度 | iGuge 式体验参考 | 本项目状态 | 对应位置 | 可离线推进的下一步 |
| --- | --- | --- | --- | --- |
| 注册、扫码登录、跨端同账号 | 账户可在扩展/网站/客户端识别；扫码可用于设备间授权 | 部分：邮箱会话登录存在；扩展明确是账户/分发，扫码账号授权流程仍属路线图 | `docs/growth-architecture-and-roadmap.md`；`apps/php-api/routes/web.php`；`apps/android/app/src/main/java/com/xlb/vpn/data/PlatformApi.java` | 先明确扫码用于订阅导入还是账号授权；不把二维码用途混为一谈，补账号授权威胁模型和状态图 |
| 购买后长期固定订阅凭证 | 购买后稳定地址，续费只延长权益 | 部分：Laravel 有按设备固定凭证、轮换 outbox 与本地沙箱生命周期；真实支付未接入，生产签发保持关闭 | `apps/php-api/app/Services/SubscriptionCredentialService.php`；`docs/node-access-agent-contract.md` | 用沙箱覆盖建单、续费、轮换、迟到回执；商户资料到位前不宣称真实支付通过 |
| 订阅码/二维码导入多客户端、多格式 | 复制/二维码进入兼容客户端 | 部分：Web 有复制、二维码和 Clash Verge 深链；profile service 仅开放 Clash/Mihomo，Sing-box 被明确拒绝，生产 profile 不可用 | `apps/web/src/SubscriptionTools.tsx`；`apps/php-api/app/Services/SubscriptionProfileService.php` | 逐格式补 schema/parser 离线测试和教程；真实客户端按版本做导入、刷新、轮换验收 |
| 智能线路/手动切换 | 自动低延迟选择、故障切换、手动选择 | 部分：local/testing 账号级兼容 profile 现有 `url-test`/`fallback`/手动 `select` 组，候选绑定当前已发布 route version，不健康或过期候选被过滤；主线 CI `45287506548` 用锁定 Mihomo v1.19.34 做核心 `-t` 解析，`45289769190` 覆盖候选过滤。生产 profile/节点不可用，无真实故障注入证据；严格 peer profile 返回路径不变 | `apps/php-api/app/Services/SubscriptionRouteService.php`；`apps/php-api/app/Services/SubscriptionProfileService.php`；`docs/growth-architecture-and-roadmap.md` | 下一步构造确定性的 route-version 故障 fixture；真实严格 peer 线路切换必须先撤旧 peer、重开新 peer并取得回执 |
| 扩展识别账号与 VIP 付款入口 | 扩展显示当前账号、VIP/套餐和付款入口 | 部分/未开放：架构文档有入口设计；扩展 manifest/本地实现仍是开发态，没有商店发布与真实支付 | `docs/client-compatibility-and-node-operations.md`；`apps/extension/manifest.json`；`apps/extension/popup.js` | 按最小权限实现账户摘要、会员中心深链和支付跳转；只接当前登录主体，支付回调后重拉权益 |
| 续费只延权益，不换订阅 | 固定订阅地址继续有效 | 部分：本地权益/凭证逻辑和轮换 outbox 已覆盖部分场景；真实商户续费、跨端刷新与第三方客户端实导入未验收 | `apps/php-api/app/Services/SubscriptionCredentialService.php`；`docs/member-connection-prd.md` | 扩展续费前后状态机测试，证明 entitlement 改变时凭证保持不变；独立测试轮换时旧链接失效 |
| 网站/扩展/客户端同一状态 | 账号、套餐、订阅和设备状态一致 | 部分：共享 API 与账号模型已有；扩展未上架、生产支付/节点未接入、旧设备端列表有已记录快照差异 | `apps/php-api/routes/web.php`；`apps/web/src/`；`apps/extension/`；`apps/android/app/src/main/java/` | 为 overview/设备/订阅状态定义统一版本与刷新点；补跨端契约测试，不用静态 UI 预览冒充线上一致 |

### 产品边界与待确认范围

用户已经要求自有及第三方设备都严格限额，并确认本项目继续 Mihomo；这些决定不是待重新表决项。待确认的产品范围仅是“iGuge 式体验”要复用哪些流程和格式，以及普通通用客户端是否作为需要本机 proof bridge 的兼容入口。账号级通用订阅会削弱设备证明、逐设备撤权和断流可证明性，不能为追求外观相似而开放账号级凭证；普通 Clash/V2rayN 在没有 proof bridge 时不能标为严格限设备已支持。严格限设备是既定约束，不作为可放宽的待决项。

## 证据索引

- [客户端兼容、订阅分发与节点运维方案](client-compatibility-and-node-operations.md)：内核/协议决策门、Clash Verge 观察、兼容矩阵与 11-14 实机范围。
- [节点运维手册](node-operations-runbook.md)：测试节点 TTL、实例自动终止与整栈回收责任分离、排空及 worker 运行要求。
- [美国节点与 macOS/Android 实机验收](aws-client-acceptance-2026-11-14.md)：指定 sing-box/SS2022/TCP 请求、一次重连和明确未覆盖项。
- [pd02 降配验收](pd02-downsize-acceptance-2026-11-08.md)：310 个 Web 采样中的 3 次未定位超时，非 VPN 节点证据。
- [M5/M6 隔离节点计划](m5-m6-isolated-node-acceptance-plan.md) 与 [PRD](member-connection-prd.md)：M5 并发与 M6 失败/待复测边界；历史 AWS 资源记录以 PRD 中最新只读核验为准。
