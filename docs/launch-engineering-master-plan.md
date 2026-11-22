# 上线工程总体规划：批量部署 / 快速上下线 / 压测负载均衡 / 四端研发 / 多云长期演进

日期：2026-11-22（执行状态复核）
状态：**滚动执行计划**。路线与验收门在本文维护；具体阶段证据以 `docs/member-connection-prd.md` 顶部和链接验收记录为准。本文不改任何线上资源，不代表生产已上线或可发布。

## 0. 这份文档解决什么

用户 2026-11-20 提出的六个问题，现有文档分散在多处、没有一份统一作战图。本文把它们收口成一份可执行的总体规划，并**明确指出既有规划的缺口**：

1. 上线后服务器如何**批量/快速部署**，节点如何**快速上下线**；
2. **压测、负载均衡**是否漏了、是否缺乏规划；
3. **各终端**开发测试——前期 Android、Windows 优先，其次 macOS、iPhone；
4. 如何保证**不在上线第一天崩塌**；
5. 后续会买**别的服务器厂商**的机器，要做**长期可移植规划**；
6. 与既有里程碑（M1–M11）、既有文档的衔接关系。

> 结论先行：目前已从单纯本地业务原型推进到隔离节点协议证据、支付沙箱账本、Android Debug 客户端和 M9 多端账号状态；**离上线仍有技术放行、合规与运营门槛**。CI、Docker 构建文件、k6 场景和 smoke 脚本已入库，但没有实测压测报告、staging、生产部署/回滚演练、生产监控告警或批量节点编排。下方 §0.1 是当前执行快照；旧章节中的全仓"无脚本/无工作流/客户端未实现"等基线文字应以该快照和源码复核为准。

### 0.1 当前完成度与工作队列（2026-11-22）

| 工作流 / 里程碑 | 当前有证据的完成部分 | 尚未完成与依赖 |
| --- | --- | --- |
| M1–M2 产品与盘点 | PRD、边界、账号/节点/支付现状盘点已固化 | 随新实现继续维护，不代表发布审查完成 |
| M3–M4 会员控制面 | Android Debug 登录、读取权益、设备 challenge 绑定；Laravel 设备、订阅和撤权 outbox 有本地测试 | member profile/真实节点授权、生产凭证和真实数据面尚未接通 |
| M5 / M7 隔离协议与回收 | 本地并发/额度内核测试；2026-11-21 获批隔离节点上合成 peer 并发、复用拒绝、独立 peer、撤权回执/最终流量测试通过；资源按计划回收核查 | 只证明限定 harness 与隔离节点；不能外推生产数据库、会员客户端或长期容量 |
| M6 双身份与会员线路 | 合成 peer 隔离协议测试通过；ALT-AN00 与 VOG-AL10 当前安装均已完成 local/testing 沙箱登录和 P-256 challenge 绑定；SQLite 最新两条设备记录 installation ID 不同、公钥指纹互异、均 registered 且 bound_at 非空；总计 4 条记录，旧两条保留；两台足够，无需第三台 | 两机连接中心均显示会员权益有效、节点授权/服务线路不可用且 VPN 未连接。会员真机复制拒绝、真实 member profile 隧道、匹配会员撤权回执未完成。AWS 会话已由用户重新登录，`ops/aws-cli` STS 只读复核有效；旧 lab 栈及实验资源已回收，现有 node-manager access 栈保持不动。11-21 合成 live acceptance 通过且 peer-store/data-plane 代码未变，本轮不为重复测试重建资源；真实 member profile/节点端材料仍是后续 M6 硬依赖 |
| M8 proof bridge | Mihomo renderer/解析 fixture、Go `crypto.Signer` 合同和本地隔离数据面有测试；Android Keystore raw-digest 与 test-only Java/JNI→Go TLS 1.3 mTLS signer callback 已完成 Debug/Release NDK build，并在专用 `xlb-m9-recovery` AVD 上 instrumentation 10/10 通过；Android `MemberDeviceProof` capability gate 已合入 `33da257`；Windows CNG signer、签名能力注入和 server-authorized peer capability fail-closed gate 也已验收；Android supervisor 的生命周期操作已改为短锁状态保护和锁外进程回调，并通过 7 项定向并发/状态测试 | Android/Windows capability 均未接真实 process/sidecar，服务端撤权刷新、macOS Keychain、production runtime/mTLS 与 member 节点 handshake 未完成；两端 bridge/TUN 仍关闭，账户 `READY` 或 capability 存在都不代表 VPN connected。此 AVD/JVM 结果不等于真机 member peer 或生产隧道验收 |
| M9 账号统一 | 匿名注册 API（明确同意、恢复密钥哈希、幂等与限流）；Android Debug 同意入口、匿名账号幂等恢复及显式退出后关闭自动恢复；Web/扩展可辨识匿名账号并保留权益状态；AndroidX Keystore 状态与 API instrumentation 已在隔离 API 35 arm64 emulator 上通过；Windows Tauri/React 账户壳已合入 `f74c270`，账户 API origin 与 Credential Manager hosted run `44945777899` 全绿；Android 授权状态快照保留已本机 JVM 验收；Windows CNG challenge/authorize 合入 `ada4de2`，Windows Rust 35/35、Credential Manager smoke、MSVC build 与主 CI run `44977351487` / `44977351461` 全绿 | 邮箱/手机升级、跨端登录和 PC 扫码授权仍未实现；Windows 新 enrollment 只展示授权状态，bridge/TUN 仍未接线；Android/Windows 均未验收真实会员线路、撤权断流或严格设备复用拒绝；不得清除现有会员设备数据 |
| M10 智能路由 | 加权候选、熔断恢复、SQLite CAS 健康仓库、默认关闭的健康命令/条件 15 秒 scheduler/route filter、PHP opt-in HTTPS/mTLS transport 与 Go opt-in loopback TLS 1.3 listener 均有离线测试；PHP→Go testdata listener 跨进程 TLS 1.3/mTLS、证书与随机 challenge echo 已在隔离回环测试通过 | runnable Go Agent 仍无真实 `DataPlaneProbeExecutor`，合法探测请求固定返回 `503 probe_unavailable`；probe 与 route filter 默认关闭，scheduler 仅在显式开启且 driver 已绑定时调度。下一项须在获批节点身份/endpoint/server cert/client cert/CA 与 canary 目标下实现/验收真实 executor、健康持久化、超时/证书失败和恢复；`/healthz` 只代表 liveness。D3 自动切换范围待用户定，严格设备线路不得绕过 peer revoke/provision/receipt |
| M11 与 E0 工程基础 | GitHub Actions 覆盖 Web/API、PHP/API/Web/扩展测试、Go race/vet/build、mock-only ops 安全测试，以及 Android pinned NDK/Go 下的真实 arm64 Mihomo JNI Debug APK、JVM tests 与 lint；metadata-only APK manifest hosted run `44943107063` 全绿；本机 loopback 控制面 smoke 已测；Windows MSVC/Credential Manager run `44945777899` 全绿 | 本轮 pnpm full audit 与 production audit 无已知漏洞，API/Web/Windows tests/build 通过；Android manifest 在 hosted job 实际产出。M11 80 请求/并发1与4的本机结果见验收报告，只是 PHP dev server + 临时 SQLite smoke，不是生产容量门。staging、生产签名制品/回滚、S3/CDN、监控告警与成本预算仍缺 |

| 发布阶段 | 状态 | 下一退出门 |
| --- | --- | --- |
| E0 地基 | 部分完成（Android native APK 检查及 metadata-only 清单 hosted run `44943107063` 全绿） | 补生产制品签名/SBOM、staging/回滚；合规与 D 决策保留为发布门 |
| E1 会员数据面放行 | 未通过 | 真实 member profile、双真机复用拒绝、节点撤权回执/最终流量和连接验收 |
| E2 工程化 | 未完成 | 批量编排、常驻受控 worker、实测容量与热备/回收演练 |
| E3 四端 | 部分完成 | Android 会员真机数据面闭环；Windows 账户壳与 MSVC/Credential Manager smoke 已通过 hosted run，下一步才是 Mihomo/proof bridge/TUN 与 Windows 运行验收；macOS/iOS 条件与后续验收 |
| E4 分发监控 | 未开始 | 用户批准资源/预算后接静态资源 CDN 与可触发告警；CDN 不承载 VPN 数据面 |
| E5 试运行 | 未开始 | 合规书面门槛、E1–E4、对账/SLO/支持流程均通过后再试点 |
| E6 多云 / E7 放量 | 未开始 | 第二家厂商 conformance 和蓝绿释放证据；依据容量/成本逐级放量 |

**当前执行队列**（可并行项共享契约先定；每个分支只拥有其模块，独立测试/commit 后由主控串行合并）：

| 工作包 | 文件/模块边界 | 依赖与退出条件 |
| --- | --- | --- |
| A. M10 真实 probe executor 与运行验收 | `apps/node-agent/**`、`apps/php-api/**` 分别限定 Agent executor/runtime 与 PHP transport/config/Feature tests | PHP configured HTTPS/mTLS transport 已在 PHP loopback TLS 1.3 server 与 test-only Go loopback listener 上验证 node ID/challenge echo；健康状态 SQLite CAS、一次性命令、条件 15 秒 scheduler 与默认关闭 route filter 均存在。runnable Agent 缺真实 executor，返回 `503 probe_unavailable`；`health_check_ttl_seconds=300` 与 probe route-state TTL=30 秒是不同状态源。下一阶段需获批的 node ID/endpoint/server cert/client cert/key/CA 与 canary 目标，验真实 executor、受控 scheduler、超时/证书失败、状态新鲜度和恢复；不得让 liveness 或 fake echo 写入健康成功 |
| B. E0 制品可追溯性 | `.github/workflows/**`、`ops/build_artifact_manifest.py`、Android Gradle | Debug APK 来源/摘要清单及 6 项测试已实现，hosted run `44943107063` 全绿；CI 新增 Syft 源码/锁文件 SBOM 与 Android Release runtime CycloneDX SBOM，均保留 7 天 Actions artifact 且禁止 Release 上传，本地生成已验证、hosted run 待确认。SBOM 未签名/attestation；生产制品签名、可信存储/保留与发布审批仍未实现，不部署、不签发生产包 |
| C. M11 本地负载分层 | `loadtest/**`、验收报告 | 80请求的 loopback smoke 已完成；后续需在获批 staging 测控制面阶梯/长稳并单独审批，现有结果不能外推生产容量或 VPN 节点 |
| D. Windows CNG verifier/真实 sidecar adapter | `apps/windows/src-tauri/**`，Rust supervisor/lifecycle contract 与 CNG signer 已合入 | CNG P-256 signer hosted 验收（29/29，`44959472000`）与 server-authorized peer capability 门禁已完成（36/36、MSVC build、UI tests/build，全绿，`44994703324`）；门禁核对完整 READY 字段、挑战前后公钥一致和设备快照，并不暴露凭据/Profile。下一步定义实际撤权重验与进程 adapter，并与 server peer protocol 接线；桥接仍默认关闭，不得将此能力外推为 Mihomo/TUN/会员线路/严格限设备验收 |
| E. M6/M8 真机与节点闭环 | Android/Node Agent 契约分开验收 | 两台现有手机的当前 installation 已各自完成沙箱登录与独立 P-256 绑定，旧设备行保留；总计 4 条 registered 记录。VOG 随后已由用户登录进入首页；本机 ADB 当前可见两台真机。2026-11-22 `xlb-ops` STS 已由项目包装验证有效；只读复核确认指定实验资源已无残留，管理栈未改。继续前仍按批准范围复核新 plan；真正会员数据面仍缺 server-verified member profile/节点端参数及匹配回执，不能宣称线路/隧道或撤权断流 |
| F. Android 授权状态快照保留（2026-11-22 已完成） | `apps/android/app/src/main/java/com/xlb/vpn/data/PlatformApi.java`、`apps/android/app/src/test/java/com/xlb/vpn/data/NodeAuthorizationStatusTest.java` | overview 合并后仍保留 `READY` 与 `PROVISIONING`；Android JVM 定向/全套测试、`lintDebug`、`assembleDebug` 通过。只验证客户端纯数据快照，不表示服务端授权或 VPN 已连接 |
| G. Windows CNG challenge/authorize 设备状态（2026-11-22 已完成） | `apps/windows/**`，独立 worktree `codex/windows-device-enrollment` | `106b14a` 合入 `ada4de2`；按 API origin 持久化 installation UUID、challenge 签名/授权状态 fail closed；React 6/6、pnpm build、hosted Windows Rust 35/35、Credential Manager smoke、MSVC build 与主 CI 通过。bridge/TUN 保持关闭，不外推为会员 VPN 或严格限设备验收 |
| H. Android Keystore digest signer 合同（2026-11-22，已合入） | `apps/android/**`、`apps/node-agent/internal/androidsigner/**` | `73c82cd` 等效合入 raw-digest `NONEwithECDSA`/Go `CryptoSignerAdapter`；专用 API 35 AVD instrumentation 3/3，legacy SHA256-only alias fail closed；Go full/race 106、vet/build，Android assemble/JVM/lint 通过。`3751174` 已合入 Gradle `connectedDebugAndroidTest`/`installDebugAndroidTest` pre-install AVD-only guard；fake adb 覆盖多设备/离线设备/错 serial/错 AVD/非 emulator。未接生产 bridge/VPN |
| I. Android Keystore→Go TLS 1.3 mTLS signer callback 合同（2026-11-22，专用 AVD 10/10 通过） | `apps/android/**`，主线 `33da257` | instrumentation-only Java Keystore signer→JNI→Go `crypto.Signer` 完成临时 TLS 1.3 mTLS handshake；Go host race test 用软件 signer 覆盖证书/签名链和 key mismatch；Keystore instrumentation 覆盖真实 Keystore 身份、另一 key 拒绝和 callback 异常失败关闭。Android Debug/Release NDK build、JVM、AndroidTest compile、lint、Go tagged race/vet 与 fake guard 通过；专用 harness 经隔离 ADB 5038 运行且只见 `xlb-m9-recovery`，共 10/10。未接 `ProofBridgeSupervisor`/`XlbVpnService`/Mihomo/TUN；下一步依 server-verified peer artifact/profile delivery 实现生产接线 |

M9 的 D2（邮件/手机/扫码跨端方式）、M10 的 D3（智能路由适用账号级还是逐设备线路）、E1 高可用拓扑/SLO 和 E4 资源费用是需用户拍板的项目决策；各自之外的离线契约、测试和模块工作继续推进。合规书面评估、生产 mTLS/运行身份、真实支付商户资料属于上线外部门槛，不阻止本地实现。每个工作包以最新 `origin/main` 开独立分支，阶段通过后 commit，并由主控运行 `ops/merge-worktree.sh <branch>` 合并推送；禁止提交 `.env`、私钥、数据库和临时产物。

### 0.2 近期依赖顺序与并行边界（2026-11-22）

上一轮的全量审计以 `3751174` 为基线，曾检查 26 个 worktree 和 25 条功能分支；这是历史快照，不代表本轮所有 worktree 已重新核验。当前 `main` 与 `origin/main` 为 `475059b`。主 worktree 保留未跟踪 `.playwright-cli/` 与 `output/`，本轮不清理或提交。当前另有 `codex/android-node-auth-status=12b464f` 的旧 worktree；其授权状态保留行为已由主线后续 `MemberDeviceProof` 改动覆盖，未将旧分支倒合回主线。ADB 5037 当前可见两台会员真机和专用 AVD；本地 API 4175 正在监听。`xlb-ops` STS 当前有效；只读盘点确认 M6 实验栈及带实验标签的实例、卷、ENI、安全组、调度器、IAM 角色/配置文件和地址均无残留，现存管理栈未触碰。无云写入。

近期按“共用契约先锁定、平台实现并行、真实节点串行验收”推进：

1. **Android M8 离线证明阶段（2026-11-22 已完成）**：专用隔离 AVD instrumentation 已验证同 installation ID 稳定复用 P-256 公钥、不同 installation ID 得到不同公钥、challenge 签名可由对应公钥验证、私钥不可导出；完整 instrumentation 6/6 通过。此阶段不证明硬件 backed provenance，也未接入会员隧道。
2. **Windows M8 平台密钥阶段（2026-11-22 hosted 已通过）**：Windows-only CNG P-256 signer 使用直接 `windows-sys 0.61.2` bindings，existing key 每次打开均核对 signing-only/zero-export/P-256；Windows hosted 29 tests 与 MSVC build 均通过（run `44959472000`）。本机无 Cargo/Rust；bridge 仍关闭，不能外推为 sidecar/TUN/会员线路验收。两个平台只共享 `xlb-proof-v1` wire/身份语义，不共享私钥或把一个平台的测试结果外推到另一个平台。
3. **M10 Agent probe 阶段（PHP 配置型 transport 与 Agent listener 契约已落地，真实 transport 未验收）**：PHP 新增按 node ID 配置 endpoint 与逐节点 CA bundle 的 `ConfiguredNodeDataPlaneProbeTransport`，强制 HTTPS origin、mTLS client cert/key、证书校验、禁 redirect 和随机 challenge/节点 ID 复核；仅显式选择 `NODE_HEALTH_PROBE_TRANSPORT=http` 注册，探测与 route filter 保持默认关闭。Go Agent listener 是 opt-in 独立 loopback TLS 1.3 mTLS，但 runnable Agent 无真实 executor，返回 `503 probe_unavailable`。PHP fake tests 不证明 HTTP fake 中的 TLS/mTLS，也不能证明真实节点健康。下一步接入已审核的实际节点身份/证书来源与真实 transport，并在批准隔离节点核验 challenge echo；不得让 liveness、fake executor 或订阅拉取刷新健康时间。
4. **M6 会员节点阶段（设备身份后，云端验收前串行）**：两台物理设备足够，不需要第三台。2026-11-22 重装后 ALT 当前安装已在沙箱登录并新建独立 P-256 绑定；VOG 当前安装尚未登录，旧 VOG 绑定不算当前安装证据，旧设备行均保留。下一步完成 VOG 当前安装登录/challenge 并核实两条当前身份互不相同；再等待可用隔离 member profile/节点线路，执行有效连接、双机同时与错峰凭证复制拒绝、匹配 node/peer/request/generation 的撤权回执和最终流量单调验证。AWS `xlb-ops` session expired，任何云端步骤前需用户本人 `aws login`，之后通过 `ops/aws-cli` 只读盘点、复核获批 plan 后才可 `--apply`；无节点回执不能宣称断流。
5. **E1–E7 放行链**：真实 mTLS/密钥注入、合规书面意见、staging/签名与回滚、分级容量数据、监控与成本预算、多云 conformance、试运行均为独立放行门；不得用本机 smoke、CI 绿灯、fake worker 或 M6 合成 peer 替代。

**下一轮执行波次**（最多两个独立 worktree 同时开发；共享的 `xlb-proof-v1` 身份语义保持一致，主控逐阶段验收和串行 merge/push）：

| 波次 | 并行工作 | 文件边界 | 独立退出条件 | 外部门 |
| --- | --- | --- | --- | --- |
| 1A | Android generic debug tunnel 的 loopback proof-bridge 失败关闭保护 | `apps/android/core-native/internal/debugprofile/**` | 已拒绝 IPv4/IPv6/mapped loopback、`localhost`/`.localhost`/`.localdomain` 常见形式；保留远端 SOCKS；此语法检查不覆盖任意自定义 DNS/hosts alias。Keystore signer 尚未接入专用 bridge，不能启动会员 TUN | 真实 member profile、server-verified peer binding 与两机隧道验收仍需隔离节点 |
| 1B | Android proof-bridge lifecycle/authorization 合同（2026-11-22 已完成并补并发保护） | `apps/android/app/src/main/java/com/xlb/vpn/tunnel/ProofBridgeSupervisor.java` 与 JVM tests | 开关默认 false；opaque private-constructor proof、只提供 signer 能力；短锁只保护状态，adapter/process 回调在锁外，并发 stop 不重复清理；失败时保留 handle；Android JVM 40/40、`lintDebug`、`assembleDebug` 通过。未接 `XlbVpnService`/Mihomo/TUN，无合法 verifier proof 生产路径 | 后续需 server-verified peer artifact、Keystore→Go signer JNI 与隔离节点复测 |
| 1C | Android M3/M4 node_authorization status normalization/UI（2026-11-22 已完成） | `apps/android/.../data/PlatformApi.java`, `presentation/MainActivity.java`, JVM tests | 只保留 `UNAVAILABLE/PROVISIONING/READY` 封闭状态；未知值 fail closed；不保存 credential/profile/peer ID；READY 文案明示 tunnel 未连接；JVM 33/33 + lint/build；CI `44973911153` 全绿 | server-verified peer artifact/JNI 未就绪，不能接 tunnel |
| 1D | Windows CNG signer 到 verified-proof/sidecar adapter 的离线合同 | `apps/windows/**` | 普通字符串不能构造 verified capability；默认关闭；Windows hosted 测试与 build 通过；不宣称 sidecar/TUN 已运行 | Windows 真实系统运行和签名另验 |
| 1E | Android Keystore raw-digest signer 语义兼容（2026-11-22 已完成） | `apps/android/**`、`apps/node-agent/internal/androidsigner/**` | 新 key `DIGEST_NONE`/`NONEwithECDSA` 对齐 Go `crypto.Signer`，专用 AVD 3/3 与 Go 向量验签通过；legacy SHA256-only alias raw-digest fail closed；Android/Go 定向与完整离线检查通过。未接 JNI/bridge/TUN，不能外推为 member peer 或会员线路 |
| 2 | M10 Node Agent loopback TLS 1.3/mTLS probe 集成合同（2026-11-22 已完成） | `apps/node-agent/server_test.go` | 临时证书、随机 challenge 和 test-only echo executor 覆盖受信客户端成功、缺失/不受信客户端证书握手失败、node/challenge 精确回显与 nil executor `503 probe_unavailable`；全套 Go test/race/vet/build 通过 | runnable Agent 仍无 executor，不能报告节点健康 |
| 3 | M10 PHP transport ↔ Go Agent 跨进程 mTLS loopback 互通（2026-11-22 已完成） | `apps/php-api/tests/**`、`apps/node-agent/testdata/**`、CI tests job Go setup | 临时证书、test-only loopback helper、真实 PHP transport 与 Go handler 完成 TLS/mTLS challenge echo；Laravel 240 tests（239 passed、1 existing skip、1,991 assertions），Go race/vet/build 均通过；Go fixture build 由 PATH timeout utility 限时 60 秒；不注入 runnable executor、不写健康观测 | 最终主线 CI `44971639457` 全绿；真实节点仍需独立获批验证 |
| 4 | M6 两台现有会员真机数据面验收 | Android/API/验收记录，云资源另按计划 | 两机同时/错峰复制均拒绝；有效会员连接成功；退款撤权有匹配回执且最终流量单调 | 两台设备登录和独立绑定均已具备，不需要第三台手机；AWS 会话已恢复且实验资源已清空。真实会员 profile、节点身份/端点/证书及可核对的节点回执仍缺；其到位前继续 Android/Windows 离线 adapter 与失败关闭测试 |

M11 隔离控制面负载工具已在主线，包含最多 100 个本地请求、并发 1/4、loopback-only、临时 SQLite、无 Cookie 重放及 health preflight。它只形成开发机控制面基线，不得外推到生产容量或 VPN 隧道稳定性。D2 跨端登录方式、D3 智能线路范围、高可用拓扑/SLO 与 E4 资源费用仍需用户决策；可并行的离线工作不因此暂停。任何阶段碰到外部门时，继续执行其他无依赖离线工作。

## 1. 与既有文档的关系（不重复）

| 既有文档 | 已覆盖 | 本文补充 |
| --- | --- | --- |
| `vpn-business-operations-roadmap.md` | 业务闭环、合规门槛、节点生命周期原则、发布 checkpoints、值班节奏 | 上线首日工程保障、CI/CD、批量部署工具链 |
| `node-operations-runbook.md` | 单节点创建/释放、上下线状态机、流量分配口径、pd02 降配 | 批量/并发编排、分钟级 SLA 的工具支撑、容量数字 |
| `platform-gaps-and-multicloud.md` | 多云薄弱点、服务商契约、蓝绿替换放行门、第二家厂商实验 | 可移植抽象层落地、多厂商选型与迁移路径 |
| `growth-architecture-and-roadmap.md` | 账号统一 M9、调度 M10、分发/压测/监控 M11、D1–D4 决策 | 压测具体基线、四端优先级、CI/CD 与发布工程 |
| `client-compatibility-and-node-operations.md` | 客户端兼容矩阵、订阅分发、节点上下线状态机、监控最低集合 | 四端研发测试排期与自动化矩阵 |
| `m5-m6-isolated-node-acceptance-plan.md` | 隔离节点验收计划、$1 预算、60 分钟窗口 | 与批量部署流水线的衔接 |

## 2. 缺口清单（按风险排序）

| 优先级 | 缺口 | 现状证据 | 风险 | 本文方案 |
| --- | --- | --- | --- | --- |
| **P0** | 发布链不完整 | CI 已覆盖 Web/API/PHP/扩展/Go/ops 与 Android native Debug APK；本轮补 Debug APK commit/SHA-256 清单；Dockerfiles 已存在。Windows 原生检查、staging、生产签名制品/回滚流程仍缺 | 开发检查通过仍不能证明生产交付和回滚可靠 | §3 |
| **P0** | 无批量部署/编排 | `ops/node_lab.py`、`node_manager.py` 是**单节点测试/管理**脚本，无多节点并发编排、无蓝绿一键任务 | 节点上量时手工操作不可控，扩容/缩容慢，故障恢复靠人 | §4 |
| **P0** | 压测尚无容量基线 | 已有 80 请求 loopback smoke 与本地 P50/P95/错误率记录；它只量 PHP dev server + SQLite，尚无分级 staging 数据、生产数据库/节点容量模型；公网/云端压测须审批 | 本机 smoke 不能用于预测生产承载量，容量规划不能以目标并发替代测量 | §5 |
| **P1** | 四端实现与验收不平衡 | Android 有 Debug 原型，Web/扩展有本地会话客户端，Windows 有已合入账户壳；Android/Windows 数据面闭环与 macOS/iOS 客户端仍未完成 | 先聚焦 Android/Windows，不把账号 UI 或模拟节点当成隧道验收 | §6 |
| **P1** | 无上线首日保障 runbook | 发布 checkpoints 有，但没有"上线日 T-1 到 T+1"的具体 checklist、SLO/错误预算、冻结窗口、值班表 | 上线当天手忙脚乱，故障无明确降级/回滚决策 | §7 |
| **P1** | 多云只有原则无可移植层 | `NodeProvider` 抽象存在但只有 AWS 驱动；无第二家、无可移植构建产物、无跨厂商配置格式 | 换厂商=重写，被单厂商锁定，成本/覆盖受制于人 | §8 |

## 3. CI/CD 流水线（补 P0-1）

### 3.1 目标
任何上线产物都从**代码审查通过的不可变版本**构建，携带可追溯元数据（commit、依赖锁文件、产物 SHA256、配置版本、迁移版本、审批人），一键部署、一键回滚。

### 3.2 流水线设计与现状（2026-11-22）

```
push/PR → ①静态检查 → ②单元/集成测试 → ③构建不可变产物 → ④制品入库 → ⑤预发布部署 → ⑥冒烟+契约测试 → ⑦人工审批门 → ⑧生产灰度发布
```

| 阶段 | 内容 | 现有基础 | 剩余工作 |
| --- | --- | --- | --- |
| ① 静态 | Web/API/PHP、Pint、TypeScript、依赖扫描 | `.github/workflows/ci.yml` 已跑多模块检查；依赖审计和构建已有本地/CI 证据 | 固化发布分支保护与审计策略 |
| ② 测试 | PHP 单元/集成、Go race/vet、Mihomo profile、Android JVM/instrumentation 编译、Windows hosted tests | 相关测试已进入 CI；Android Keystore AVD、Windows CNG 与 Node Agent 阶段有各自 hosted 证据 | 继续补真实 Android/Windows bridge、跨进程和数据面集成覆盖 |
| ③ 构建 | Web/API/Agent、Android Debug APK、Windows 客户端检查 | Android pinned NDK/Go 下 native Mihomo arm64 Debug APK 已构建；Windows MSVC build 已在 hosted job 验证 | 生产平台镜像/多架构制品、签名、SBOM 与可回滚发布制品 |
| ④ 制品 | 来源、摘要、入库与签名 | `ops/build_artifact_manifest.py` 生成 commit/ref/run/path/size/SHA-256 元数据并写入 CI summary；不上传 APK、不创建 release | 不可变制品库、保留策略、依赖 SBOM、生产签名与验证 |
| ⑤ 预发布 | 隔离账号/域名/数据库/支付沙箱 staging | 当前未配置 staging，不执行部署 | 经批准的 staging 环境与可重复部署脚本 |
| ⑥ 冒烟 | 登录、权益、订阅、支付沙箱、节点探测、客户端连接 | API/Web/支付沙箱有离线自动化；本机控制面 smoke 和 isolated harness 已有记录 | 自动化 staging 端到端套件；真实节点探测/会员数据面仍单独验收 |
| ⑦ 审批门 | 生产发布人工批准、高风险双人复核 | 尚未配置生产环境保护或审批流 | GitHub Environment protection/等价审批与审计记录 |
| ⑧ 灰度 | 线路/应用灰度与 SLO 门禁 | 控制面有线路版本灰度基础，尚无生产部署链 | 与受审 CI/CD 打通，并验证灰度、停止和回滚 |

### 3.3 关键原则
- **配置与密钥不进镜像**：密钥走 secrets manager（AWS Secrets Manager / SSM Parameter Store），镜像只含代码；UserData/Git/工单禁止写密钥（沿用 runbook 口径）。
- **数据库迁移 expand/contract**：先加兼容字段，发布读写，观察后移除旧结构；迁移可回滚性单独验证（沿用业务路线图 §4）。
- **不可变 + 可回滚**：每次发布保留上一版本产物与配置，回滚=切回已验证产物版本，不是现场改代码。

### 3.4 Debug APK 来源清单（2026-11-22，hosted 全绿）

`ops/build_artifact_manifest.py` 仅接受没有已跟踪修改或非忽略未跟踪文件的 checkout，制品须在仓库内；输出必须不存在且位于 Git 忽略目录。Android CI 在构建后执行以下命令，并把 JSON 追加到该次 workflow summary：

```sh
python3 ops/build_artifact_manifest.py \
  --output apps/android/app/build/outputs/apk/debug/build-manifest.json \
  apps/android/app/build/outputs/apk/debug/app-debug.apk
```

清单记录 commit、ref、CI run ID/attempt、UTC 时间、相对路径、字节数及 SHA-256。不读取 env 凭据、不上传 APK、不创建 release；工作目录有既存未跟踪产物时拒绝执行，使用干净 checkout 验证，不能为了生成清单清理用户文件。该工具不能证明构建可复现或代码可信，也不能代替依赖许可证/SBOM、制品签名、受信构建环境和发布审批。回归覆盖摘要、来源状态、越界路径与输出覆盖保护；ops 34 项通过。

## 4. 批量部署与节点快速上下线（补 P0-2）

### 4.1 现状
- `ops/node_lab.py` / `node_manager.py`：单节点创建、SSM 就绪、公网出网验证、到期终止、整栈回收，已验收（见 `aws-node-lab-acceptance`、`node-retirement-and-reclamation`）。
- 上线/下线状态机、5%→20%→50%→100% 灰度、冷启动 5–10 分钟 / 预热 1–3 分钟目标——**已定义但未编排成一键任务**（`platform-gaps-and-multicloud.md` 已列为 P1"节点快速替换"缺口）。

### 4.2 批量部署工具链（新增）

在现有单节点脚本之上加一层**编排器**（建议 `ops/node_fleet.py`，复用 `node_manager.py` 的 provider 适配器）：

| 能力 | 实现要点 |
| --- | --- |
| 批量创建 | 读取节点规格清单（区域/厂商/规格/协议/数量），并发创建但有并发上限（如 3–5）与失败隔离；每个节点走同一 conformance suite |
| 幂等 | 每批带 `batch_id` + 每节点 `request_id`，重跑不重复建；失败节点进"失败待回收"自动清理 |
| 分批灰度 | 编排器驱动线路版本发布：新节点全部通过验收→加入候选→按 5/20/50/100 放量，每级观察窗口 ≥15 分钟 + 一个业务高峰 |
| 一键蓝绿 | 新节点池就绪 → 发布新线路版本 → 观察 → 旧节点排空 → 回滚开关全程可用（对应 `platform-gaps` §4） |
| 批量下线 | 先确认替代容量 → 停调度 → 发配置 → 等排空窗口 → 批量撤销 peer + 释放 → 逐项核对账单/EIP/盘/ENI/SG，残留告警 |
| 快速恢复 | 维持**最小热备池**（每主力区域 1–2 台预热节点），故障切流到热备（1–3 分钟），冷启动仅作补充——"不要指望故障后才买机器" |

### 4.3 快速上下线的量化目标（写入验收门）
- 热备节点切流：≤3 分钟加入调度并通过握手；
- 冷启动节点上线：5–10 分钟完成创建→验收→灰度；
- 下线排空：第三方客户端按 15 分钟自动更新、30 分钟迁移窗口；不更新者延长排空或走稳定入口切换（沿用 runbook §4）；
- 每批操作全程可暂停、可回滚、可审计（变更单、批准人、前后指标）。

### 4.4 红线
- 回收器拒绝 `pd01`/`pd02`/`XlbRole=control-plane`/共享主机/未知标签/非批准区域账户（沿用既有文档）。
- 生产自动化跑在**线上常驻 worker**，不依赖个人电脑开机、Codex 对话或本机定时器（`client-compatibility` §节点运维已明确）。

## 5. 压测与负载均衡（补 P0-3）

### 5.1 压测现状与缺口
- 现状：`loadtest/k6/` 有登录/overview、订阅和显式沙箱 callback 场景；`loadtest/smoke.sh` 做 loopback 冒烟；`loadtest/reports/local-control-baseline-2026-11-22.md` 记录 PHP 开发服务器 + 临时 SQLite 的 80 请求、并发 1/4 控制面基线，错误率均为 0%，P95 约 3.7–3.9ms / 13.7–14.1ms。该报告不含登录态、生产数据库或 VPN 节点数据面。
- 缺口：没有 staging 阶梯运行数据、生产数据库/节点容量模型，也没有可外推的容量门。默认目标值仍只是待校准建议。

### 5.2 压测计划（k6，脚本入库）

**分层场景**（每场景独立报告，不混为一谈）：

| 层 | 场景 | 目标并发 | 观测指标 | 放行阈值（建议，待压测校准） |
| --- | --- | --- | --- | --- |
| 控制面 API | 登录、`/me/overview`、订阅拉取、下单/回调 | 500 / 1000 / 3000 阶梯 | P50/P95/P99 延迟、错误率、5xx、DB 连接池 | P95 < 500ms、错误率 < 1%、无连接池耗尽/OOM |
| 静态分发 | 安装包下载、站点资源（走 CDN） | 3000 | CDN 命中率、回源带宽、下载成功率 | 回源带宽≈0、成功率 > 99.9% |
| 数据面握手 | 节点协议握手、凭证签发/撤销 | 按节点容量×节点数 | 握手 P95、签发延迟、名额不超卖 | 握手 P95 < 1s、零超卖 |
| 稳定性 | 长时稳态（如 2–4 小时中等负载） | 稳态 30% 峰值 | 内存泄漏、FD 泄漏、延迟漂移 | 无单调增长、延迟漂移 < 20% |

**执行纪律**：先在隔离/预发布环境跑，不跑生产；短时按量、跑完即释放；压测数据脱敏，不打印凭证/私钥/订阅 URL（沿用既有红线）。压测脚本、参数、报告纳入仓库与验收记录，作为容量模型输入。

### 5.3 容量模型（压测输出驱动）
- 由压测得出"单控制面实例承载的 API QPS""单节点承载的连接数/带宽"，据此定**扩容阈值**（建议水位 ≥70% 触发扩容、留 30% 故障余量，与 runbook 一致）。
- 每季度用真实指标复核容量预测（`vpn-business-operations-roadmap` §6 已有节奏）。

### 5.4 负载均衡现状与缺口
- 现状：`node-operations-runbook` §5、`growth-architecture` §2.2 已定义"加权调度 + 会话散列 + 15 秒探测 + 3 次熔断 + 70% 水位停扩"，口径完整。
- 缺口：
  1. **生产前端/API 的高可用未落地**：网站/API 目前单副本、SQLite；生产需多副本 + 云数据库 + HTTPS 负载均衡（ALB），"MVP 不额外买 LB"的口径在上线阶段要重新决策（§7 待拍板）。
  2. **数据面分流依赖服务端**：Clash `url-test`/`fallback` 只做选路，不能保证按后台权重均匀分流；真正容量分配要服务端分配或协议兼容入口层——这是 M10 的核心，不能省。
  3. **关键矛盾**：严格设备绑定把订阅绑单 peer/单节点，智能线路切换必须走"撤销旧 peer→新节点重开→回执"（`growth-architecture` §1.2），否则绕过设备名额——排期时必须把 M6 回执闭环作为 M10 前置。

## 6. 四端研发测试排期（补 P1-4）

### 6.1 优先级（用户明确：Android、Windows 优先；macOS、iPhone 次之）

| 优先级 | 端 | 定位 | 关键依赖/门槛 | 排期（阶段，非日历） |
| --- | --- | --- | --- | --- |
| P0 | **Android 自有 App** | 主力移动端，已有 Java Debug 原型和固定 Mihomo 核心 | 登录/权益/设备 challenge/Keystore cookie 和匿名 Debug 同意入口已实现；待隔离 member profile 下真实逐设备授权、proof bridge/撤权回执、断线与 DNS/UDP 等真机全路径 | 第一批：从本地登录到真实节点的闭环；当前阻塞需隔离线路/节点侧 member profile |
| P0 | **Windows 自有/桌面桥接** | `apps/windows` 已有 Tauri/React 账户壳、Credential Manager hosted 验收和 current-user CNG P-256 signer hosted 验收 | verified-proof/peer binding 到真实 sidecar、Mihomo/TUN 接线、签名更新、崩溃恢复与卸载清理未验收 | 第一批：离线接好默认关闭的 proof adapter，再做 Windows 运行与隔离节点数据面验收 |
| P1 | **macOS** | 次主力桌面 | 与 Windows 共用桥接内核，差异在系统权限/签名/配置迁移 | 第二批：复用 Windows 成果适配 |
| P1 | **iPhone / iOS** | 次主力移动 | Network Extension entitlement、开发者账号/签名、系统连接审批、后台恢复、App Store 审核 | 第二批：条件明确后再进设备联调 |

### 6.2 每端的验收矩阵（统一模板）
按 `client-compatibility-and-node-operations.md` 的口径，每端逐项留证：

- 安装/更新/卸载清理；登录与会话持久化（重启保持）；
- 订阅导入/刷新（15 分钟自动更新）、凭证轮换后旧地址立即失效；
- 连接/断开/断线重连、节点故障切换；
- Rule/Global/Direct 或对应模式；DNS 与 UDP、IPv4/IPv6、MTU；
- 到期/退款/封禁后撤权断连；流量计量；
- 设备数限制（同时/错峰复制凭证均被拒）；
- 崩溃/异常上报（不打印明文凭证）；
- 每条记录含：设备、OS 版本、客户端/核心版本、配置版本、节点 ID、时间、脱敏错误码、证据链接。

### 6.3 自动化支撑
- Node Agent renderer 与 Mihomo `v1.19.34` 的共享 fixture 有解析测试；这是格式证据，不是连接证据。
- Android Keystore instrumentation 已在隔离 API 35 AVD 验收；Windows CNG signer 与 bridge lifecycle contract 各有 hosted/离线测试；这两条都尚未证明真实 sidecar、TUN 或会员节点连接。
- 四端兼容矩阵继续按真实设备与核心版本记录；"已验证"必须有对应平台的设备证据，格式能解析 ≠ 可连接。

## 7. 上线首日不崩塌保障（补 P1-5）

### 7.1 硬门槛（未满足不发版）
1. **合规先行**：律师/合规负责人书面确认经营主体、资质、服务范围、跨境数据、支付与商店政策（`vpn-business-operations-roadmap` §0）——这是上线总闸。
2. **放行门全绿**（产品规则 §7）：设备绑定授权 + 每设备独立凭证 + 节点并发控制 + 撤权回执 + "同时/错峰复制凭证均被拒"真机实测——缺一不可，不宣称可发布。
3. **P0 缺口关闭**：CI/CD 流水线跑通、批量部署编排可用、压测基线建立且达标、最小热备池就位。
4. **生产常驻 worker**：scheduler/queue worker 部署到受控环境，心跳/积压/失败告警可用；不依赖本机。
5. **可回滚**：应用/配置/路由/数据库前向修复四类回滚方案演练通过；备份可恢复抽测通过。

### 7.2 SLO 与错误预算（建议，待拍板）
- 端到端连接成功率 ≥ 99%（滚动 24h）；连接建立 P95 ≤ 1s；控制面 API 可用性 ≥ 99.9%；订阅拉取成功率 ≥ 99.5%。
- 定义错误预算耗尽动作：停止扩量/冻结发布、切热备、回滚上一版本、发公告。
- 注意：探测数不等于客户 SLA，每个指标注明分母/窗口/剔除条件（沿用业务路线图 §6）。

### 7.3 上线日 runbook（T-1 → T+1）
| 时点 | 动作 |
| --- | --- |
| T-1 | 变更冻结（除热修）；容量预检≥峰值×1.3；热备就绪；值班表与升级路径确认；告警通道/面板验证；回滚演练最后一次 |
| T-0 | 灰度 5%→20%→50%→100%，每级观察≥15 分钟；盯连接成功率、握手 P95、API 错误率、支付/权益对账、节点余量、工单、成本 |
| T+首高峰 | 观察一个完整业务高峰；错误预算消耗速率；客服 Top 问题；云出站成本 |
| 触发回滚 | 连接成功率/错误率越阈值、对账不平、数据损坏迹象、节点余量不足 → 立即停扩量并回滚（配置/路由/应用），支付账本不盲目回退 |
| T+1 | 关键路径抽测、支付对账、告警确认、工单复盘、变更签字关闭 |

### 7.4 监控告警（沿用最低集合，补齐落地）
- 指标：订阅拉取成功率、配置解析失败、连接成功率、握手 P50/P95、节点延迟/丢包、活动会话、节点容量、计量延迟、支付回调/权益队列、资源回收残留（已定义）。
- 告警通道：CloudWatch Alarm + SNS（邮件/企业 IM）；含节点/区域/供应商/配置版本/最近探测/失败阶段/工单字段。
- 错误追踪：前端 ErrorBoundary、`unhandledrejection`、Android 崩溃上报、Laravel 结构化日志；脱敏。
- 成本：AWS Budget + Billing Alarm；TTL 硬保护，账单延迟不作为"费用归零"证据。

## 8. 多云长期演进（补 P1-6）

### 8.1 目标
不被单厂商锁定：后续买别的厂商服务器时，**同一套部署档案、契约、构建产物、状态机**可复用，只换 provider 适配器。

### 8.2 分层与可移植抽象
| 层 | 现状 | 长期设计 |
| --- | --- | --- |
| 部署档案 | `NodeProvider` 抽象 + AWS 档案目录/固定校验已实现 | 档案统一字段：厂商、不可变档案 ID/版本、账号、区域、规格、协议能力、网络引用、凭证引用；任务保存目标快照，变更档案不改旧任务（沿用 `platform-gaps` §1） |
| Provider 适配器 | 仅 AWS PHP 适配器过本地假执行器契约 | 第二家驱动必须继承同一 conformance suite；覆盖 API 超时但云端成功、部分创建、残留盘/网卡、重复释放、未知状态映射、全量释放核对 |
| 基础设施即代码 | AWS CloudFormation 模板（node-lab / node-manager） | 抽象成厂商中立规格 → 各厂商模板（AWS CFN / 他云 ROS/Terraform），保持同一标签体系与回收语义 |
| 构建产物 | 无标准镜像 | 容器化控制面 + node-agent 多架构二进制，跨厂商可移植；密钥不进产物 |
| 调度/容错 | 单 AWS 区域为主 | 多故障域（区域/运营商/厂商）策略；区域隔离；按真实网络质量选型，不默认复制 AWS |

### 8.3 第二家厂商选型门槛（沿用 `platform-gaps` §3）
先比较：目标用户真实网络质量、区域、供应商 AUP、出口、带宽计费、API 配额、支持能力、删除语义。短时专用实例跑同一 conformance suite，核对服务器/磁盘/网卡/公网 IP/安全组/防火墙/密钥/计费项全部释放。**无真实实验结果时后台只能显示"未接入"**。

### 8.4 迁移路径（换厂商不中断）
1. 新厂商节点按 §4 批量创建 + conformance，加入候选池；
2. 通过 §5 压测与 §6 客户端验收；
3. §4 一键蓝绿切流，观察一个业务高峰；
4. 旧厂商节点按 §4 批量排空、释放、账单核对；
5. 档案版本升级但旧档案保留到关联资源全部释放。

## 9. 阶段路线图与依赖（汇总）

> 与 `growth-architecture-and-roadmap` 的 P0–P6 对齐，补齐工程化阶段；以"阶段+依赖"表达，不承诺日历日期。

| 阶段 | 内容 | 依赖 | 退出门 |
| --- | --- | --- | --- |
| E0 地基 | 合规书面确认、D1–D4 拍板、CI/CD 流水线、制品库、staging 环境 | 用户拍板 | 流水线跑通、产物可追溯可回滚 |
| E1 放行门 | M4–M6 完成（设备绑定/独立凭证/并发控制/撤权回执/真机限设备） | 隔离节点、proof 桥接 | 放行门全绿、数据面闭环 |
| E2 工程化 | 批量部署编排、热备池、压测基线与容量模型、生产 worker | E0/E1 | 批量上下线演练、压测达标、worker 告警可用 |
| E3 四端 | Android + Windows 优先（隧道/桥接/全路径真机）；macOS/iOS 次之 | E1、客户端验收矩阵 | 主力端全路径通过、次主力端条件就绪 |
| E4 分发监控 | S3/CDN、监控告警、错误追踪、账单告警 | 资源获批 | 下载走 CDN、告警可触发可定位 |
| E5 试运行 | 有限用户、冻结窗口、每日对账、SLO 观察 | E1–E4 | 连续观察期达标 |
| E6 多云扩容 | 第二家厂商实验、可移植层、多故障域 | E2 | 第二家 conformance 通过、蓝绿切流演练 |
| E7 逐步放量 | 按容量模型扩容、季度复审 | E5/E6 | 每次扩容有成本/余量/回滚 |

## 10. 需用户拍板的决策点（在既有 D1–D4 之外新增）

- **E1 生产前端/API 高可用**：上线阶段是否从单副本+SQLite 升级为多副本+云数据库+ALB？（"MVP 不买 LB"口径需重新决策）——建议升级，否则首日单点即全站不可用。
- **E2 压测阈值**：§5.2 的 P95/错误率数字为建议值，需结合业务目标确认。
- **E3 四端范围**：确认 Android/Windows 为首批、macOS/iOS 次批的资源分配；iOS 开发者账号/签名是否now准备。
- **E5 SLO/错误预算**：§7.2 数字需确认。
- **E6 第二家厂商**：选哪家、何时启动实验（与 `platform-gaps` D 一致）。
- **云资源与费用**：CDN/S3/监控/压测/第二家实验资源的创建与费用，逐项批准，不擅自创建。

## 11. 红线（继承，不可违背）
1. 严格设备限制不放宽；智能线路切换不得成为绕过设备名额的后门。
2. 带 Token 的订阅/profile 响应禁止公共 CDN 缓存（`Cache-Control: no-store`）。
3. 无节点回执不宣称断流/精确限设备/可发布；生产签发保持关闭。
4. 压测/监控数据脱敏，不打印凭证/私钥/profile/订阅 URL。
5. 云资源写操作、费用、域名/DNS 变更均需用户明确批准；账单延迟不作为费用归零证据。
6. 生产自动化不依赖个人电脑/Codex 对话常驻；回收器拒绝控制面与共享主机。
7. 合规门槛未过前，只做本地开发、测试与授权封闭试点，不对外售卖。

## 12. 本文性质
规划稿，不代表已实现。所有"现状"以源码实测为准，所有"已实现"以验收记录为准。任何阶段启动前先关闭对应决策点并获资源批准。
