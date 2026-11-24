# 上线工程总体规划：批量部署 / 快速上下线 / 压测负载均衡 / 四端研发 / 多云长期演进

日期：2026-11-25（滚动执行状态复核）
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

### 0.1 当前完成度与工作队列（2026-11-25）

| 工作流 / 里程碑 | 当前有证据的完成部分 | 尚未完成与依赖 |
| --- | --- | --- |
| M1–M2 产品与盘点 | PRD、边界、账号/节点/支付现状盘点已固化 | 随新实现继续维护，不代表发布审查完成 |
| M3–M4 会员控制面 | Android Debug 登录、读取权益、设备 challenge 绑定；Laravel 设备、订阅和撤权 outbox 有本地测试 | member profile/真实节点授权、生产凭证和真实数据面尚未接通 |
| M5 / M7 隔离协议与回收 | 本地并发/额度内核测试；2026-11-21 获批隔离节点上合成 peer 并发、复用拒绝、独立 peer、撤权回执/最终流量测试通过；资源按计划回收核查 | 只证明限定 harness 与隔离节点；不能外推生产数据库、会员客户端或长期容量 |
| M6 双身份与会员线路 | 合成 peer 隔离协议测试通过；ALT-AN00 与 VOG-AL10 当前安装均曾以 local/testing 沙箱完成登录和 P-256 challenge 绑定；PRD 记录两台 installation ID 与公钥互异；两台足够，无需第三台 | 会员真机复制拒绝、真实 member profile 隧道、匹配会员撤权回执未完成。11-24 历史只读盘点曾确认实验标签资源无活动项；11-25 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 session expired，本轮未重查 AWS 资源。后续获批节点窗口前须用户本人重新 `aws login`，再按范围只读盘点并审核计划。真实 member profile、节点身份/endpoint、生产用途 mTLS 材料和可核对回执仍是 M6 硬依赖 |
| 支付适配器与本地沙箱账本 | provider-neutral 契约、签名/时间窗验签、重复及乱序决策、SQLite 事件账本、fulfillment/refund outbox 与 local/testing webhook/worker 已贯通；订单、权益、退款只在沙箱状态机处理。补偿失败保留权益并幂等重试已有回归；本轮新增 local/testing 每分钟处理两个 sandbox outbox 的调度注册，production 不注册；调度定向 3/3，Laravel 全套 260 passed、1 skipped、2,310 assertions | 未接任何真实商户/真实收单，不发生真实扣款或退款；真实商户 sandbox、回调契约、凭据及合规意见仍未到位。调度注册不证明 staging scheduler 常驻；离线代码不能代替真实支付/退款验收 |
| M8 proof bridge | Mihomo renderer/解析 fixture、Go `crypto.Signer` bridge 合同和隔离数据面有测试；Android core-native proof bridge Manager→Node Agent PeerStore/DataPlane 的 TLS 1.3/HTTP2+loopback SOCKS 集成 race test 已纳入 CI；专用 AVD 全套 17 项实测 Android Keystore→JNI→Go bridge→Agent peer 授权、不同 installation 公钥复制拒绝、撤权 `confirmed_test` 与非零最终流量及撤权后重连拒绝；Debug-only isolated service action 在 release gate 关闭时验证 Android `VpnService.Builder`、真实 TUN、Mihomo→proof bridge→Node Agent TCP echo、合成撤权回执、活动流 EOF 和后续流拒绝，且不发布 `CONNECTED`；新增 attempt-bound member tunnel health monitor，连续两次 VPN route probe 失败后会清理 session/TUN 并发布失败状态；bridge close 现先关闭 loopback SOCKS 流、等待处理器退出，再经当前 mTLS/HTTP2 会话调用 disconnect 并关闭 transport，Go race 集成断言 active peer session 归零；Windows CNG signer、server-authorized capability、默认关闭 bridge gate 有 hosted Rust/MSVC 证据；PHP 校验/加密保存每节点 endpoint/CA 并向 Android grant 返回完整 descriptor/metadata；Node Agent 的显式 `isolated-test` runtime config 现在可注入 Mihomo bridge renderer 并生成 profile，renderer 仍默认 unavailable，测试回执保持 `confirmed_test`；Android stop-after-queued-start AVD 用例等待上限已与 20 秒启动操作 timeout 对齐，完整 disposable harness 复跑通过 | isolated TUN/revoke 验收只用本地 server-ready grant/Node Agent fixture，不访问生产会员 API、云节点或公网目标；普通 MemberTunnelSession lifecycle instrumentation 中 `establishTun`/`verifyTunnel` 仍为计数 stub。运行期 health monitor 是 HTTP 204 route probe，不是 peer generation/节点撤权事件订阅，仅可在隧道数据路径不可达时失败关闭。Debug-only fixture action 不改变 release gate，`MEMBER_PROOF_BRIDGE_INTEGRATED` 保持 false。Node Agent production runtime 仍不可启动；isolated renderer profile 生成不证明本地 bridge 已运行、实际 VPN 隧道或生产 member profile；stop-after-queued-start 用例验证最终状态，不提供响应时延 SLO。真实节点 profile/endpoint/CA/mTLS、生产会员授权、真机复制拒绝和匹配撤权回执未完成；Windows 真实 process/TUN、macOS Keychain 与 member 节点握手未验。Android API 与 service failure diagnostic codes 只呈现客户端观察边界，不证明节点原因、生产连接或撤权断流 |
| M9 账号统一 | 匿名注册 API（明确同意、恢复密钥哈希、幂等与限流）；Android Debug 同意入口、匿名账号幂等恢复及显式退出后关闭自动恢复；Android/Web 登录表单不预填种子账号凭据；Web/扩展可辨识匿名账号并保留权益状态；AndroidX Keystore 状态与 API instrumentation 已在隔离 API 35 arm64 emulator 上通过；Windows Tauri/React 账户壳已合入 `f74c270`，账户 API origin 与 Credential Manager hosted run `44945777899` 全绿；Android 授权状态快照保留已本机 JVM 验收；Windows CNG challenge/authorize 合入 `ada4de2`，Windows Rust 35/35、Credential Manager smoke、MSVC build 与主 CI run `44977351487` / `44977351461` 全绿 | 邮箱/手机升级、跨端登录和 PC 扫码授权仍未实现；Windows 新 enrollment 只展示授权状态，bridge/TUN 仍未接线；Android/Windows 均未验收真实会员线路、撤权断流或严格设备复用拒绝；不得清除现有会员设备数据 |
| M10 智能路由 | 加权候选、熔断、SQLite CAS 健康仓库、默认关闭的健康命令/条件 15 秒 scheduler/route filter、PHP opt-in HTTPS/mTLS transport 与 Go opt-in loopback TLS 1.3 listener 均有离线测试；新增 `isolated-test` runtime-config opt-in HTTPS canary executor，固定 HTTPS/443 target、显式 CA、无代理/redirect、响应有界且 challenge 字符串精确匹配；Go race tests 覆盖 mTLS probe listener→HTTPS canary，PHP transport 另有全链本机集成覆盖 PHP→Go Agent mTLS→本地 HTTPS canary；M10 profile 健康过滤、route version 发布/回滚、合成熔断恢复与不健康 canary 回退均有 Feature 测试 | executor 默认关闭，生产 runtime 不支持且保留 `503 probe_unavailable`；没有获批真实 canary URL/CA 或隔离节点运行，因此尚无真实健康观测，probe/route filter/scheduler 继续默认关闭，不能称稳定性/切换验收。后续需获批 canary 与节点身份后实测超时、证书失败、状态新鲜度及恢复；严格 peer profile 不变，D3 范围仍待决且不能绕过 peer revoke/provision/receipt |
| M11 与 E0 工程基础 | GitHub Actions 覆盖 Web/API、PHP/API/Web/扩展测试、Go race/vet/build、mock-only ops 安全测试，以及 Android pinned NDK/Go 下的 arm64 Mihomo Debug APK、JVM tests 与 lint；主线 `b775212` 的 CI `45446581811` 六个 jobs 全绿，包含 process-death host APK/instrumentation 编译；Web 订单取消提交 `8a7aa19` 的 CI `45451245263` 六个 jobs 全绿。Android signed release-candidate workflow 的 main/manual/protected-env/review-only 边界、release runtime CycloneDX BOM、APK+BOM 联合摘要、manifest 校验和 provenance 在 hosted CI 有覆盖 | 本机 M11 80 请求/并发1与4结果只是 PHP dev server + 临时 SQLite smoke，不是生产容量门。11-25 GitHub Environment 只读 API 返回 `total_count=0`，正式签名候选仍无法运行。SBOM provenance 不等于 APK/Release 签名；生产签名制品/回滚、staging、静态资源发布、监控告警与成本预算仍缺 |

### 0.2 一周首版冲刺（检查更新：2026-11-25；检查点 2026-12-01）

目标范围沿用已确认的 Android + 网站/API 单服务商受控试点。工作按并行泳道推进，外部放行材料不能替代，也不因代码完成自动通过。

| 时间 | 可并行工作 | 退出证据 / 阻塞处理 |
| --- | --- | --- |
| 11-24，基线日（历史） | 锁定首发范围与 go/no-go 清单；Android/Web/API/支付继续离线回归；运维侧只读核验 AWS 身份与实验资源 | 当日主线 CI `45322097403` 六个 jobs 全绿；当日 AWS 包装器曾确认 STS 有效、实验资源盘点无活动项；这是 11-24 历史状态 |
| 11-25 至 11-26（滚动） | Android 进程死亡后的显式冷启动恢复、Web/API 订单取消 UI 阶段均完成；后续只补新证据揭示的首发离线缺口。外部仍需配置签名 Environment、审批人和签名/API origin 凭据，提供 member profile/endpoint/CA/mTLS/撤权回执，确认首发市场合规意见并确定 staging 回滚窗口 | 本机与相应 hosted CI 阶段已验收。11-25 Environment 数量仍为 0，`xlb-ops` STS 已过期；节点与发布窗口负责人尚未确认。任一外部门未就绪时不以沙箱或合成 peer 代替，并继续无依赖离线工作 |
| 11-27 至 11-28 | 在材料齐备时构建受保护签名候选并验 APK/SBOM provenance；部署到获批 staging，跑网站/API 登录、会员权益、商户沙箱、Android 安装/连接/退款撤权与回执核对 | 必须有可复现候选摘要、真实 staging smoke、真实商户沙箱回调/对账、两台 proof-capable 身份的会员线路和匹配撤权最终流量回执；否则这些门保持未通过 |
| 11-29 至 11-30 | 故障/回滚演练，检查指标、告警、客服和资源费用；仅对已通过的链路做小范围受控试运行准备 | 记录错误预算、停止扩量与回滚责任人；未做真实节点和 staging 验收不发布付费试点 |
| 12-01 | Go/no-go 审查与候选交付 | 所有 P0 放行门通过后才可宣布受控试点上线。若外部材料延迟，交付签名候选/内部技术预览并列明阻塞，不把日期写成已上线承诺 |

一周目标是压缩 AI 可控开发与审查周期。11-25 当前只读复核显示 GitHub Environment 数量为 0、`xlb-ops` STS 已过期，节点/撤权材料、staging、商户沙箱与合规书面意见仍未登记到责任人和窗口。因此 12-01 只能作为内部冲刺检查点，当前没有证据支持受控试点上线日期预测；新增或剩余 AI 可控工作继续按新缺陷与首发验收路径排序。

### 0.2.1 四象限复盘与本周工作重排（2026-11-25）

#### 项目全景与工作量排序（2026-11-24 更新）

本计划按知识库的四象限规则管理 Codex、DeepSeek Harness 和 WorkBuddy 的开发任务：四象限决定先后，WBS 将工作拆成通常不超过两小时的可验收单元；AI 主动编码时间、CI/设备运行时间、外部等待分别记录。每轮以 PRD 最新进度和 `main`/`origin/main` 为基准，不以某个旧 worktree 的分支差异代替主线状态。

| 优先级/工作流 | 当前证据与主要工作量 | AI 主动投入估计 | 依赖与安排 | 下一项可验收结果 |
| --- | --- | --- | --- | --- |
| **Q1 Android 会员数据面放行** | 两台真机身份、Android Keystore、Go bridge、Mihomo/TUN 的隔离 harness 和客户端安全诊断已有证据；生产 gate 仍关闭。最大不确定性在真实 member profile/节点凭证接线、双机复制拒绝、撤权回执与最终流量匹配 | 有材料后按 1–3 个开发/验收日估算，不能把外部排队算作 AI 工时；具体需根据节点契约复估 | 节点负责人提供 endpoint、CA/mTLS 注入方式、profile、获批窗口和回执字段。资料未到期间不改 gate、不模拟真实结论 | 材料齐备后先做单设备 happy path，再做第二身份复制拒绝和撤权；每项保留请求/节点/peer/generation 对应证据 |
| **Q1 M11 签名候选与 staging** | APK、runtime SBOM、摘要 manifest、provenance workflow 有离线/CI 验证；protected Environment 未配置，无 staging 部署/回滚验收 | Environment 元数据可见后约 2–4 小时配置核对与候选流程复核；staging 演练约 0.5–1.5 日，环境和负责人等待另计 | 仓库管理员建受保护 Environment 并设审核者；凭据负责人经批准通道提供正式签名配置/API origin；staging 负责人给环境、窗口和回滚人。AI 不读取/提交凭据，不部署 | 获批者产出同一 commit 的签名 APK、SBOM、manifest、provenance；staging smoke 与回滚形成独立记录 |
| **Q1 商户沙箱与首发合规** | provider-neutral 契约和 local/testing 付款退款账本已经存在；真实商户 sandbox、首发地区合规意见未验收 | 商户契约到位后约 0.5–1.5 日接入/验收，审查时间由外部负责人决定 | 支付负责人提供回调、签名、重试、对账字段；合规负责人确认地区和书面意见。不得以本地假渠道宣称真实支付已验收 | 测试下单→回调→权益→退款→对账可追溯，并取得书面放行意见 |
| **Q2 Android 离线失败处理** | 连接与 API 失败的安全诊断编号已有实现；首页连接失败有“重试连接”、账户节点线路不可用卡有“重试同步”。本轮为 attempt-bound monitor 补上“probe 进行中旧 attempt 被替换”回归；JVM 全量、AndroidTest compile、lint、Debug APK 本机通过，主线 `f07465d` CI `45406085919` 六个 jobs 全绿。未发现还需重复建设诊断 UI 的离线缺口 | 本阶段已完成；后续由新的复现问题驱动 | 真实节点材料缺失期间可继续其它工作，但不把客户端 probe 说成节点撤权事件/线路稳定证明 | 将 Android 诊断入口从本周 AI 待办移出；真实 member profile、节点证书和回执到位后转入 Q1 真机验收 |
| **Q2 Web/API 沙箱首发回归** | 2026-11-24 本机订单、沙箱付款/退款和 provider ledger 测试通过；直接付款和 provider fulfillment 两条续费路径均锁会员行后计算新权益，相关 CI 全绿。本机浏览器联验会员网站→Laravel：建单与 sandbox payment 均 HTTP 201，退款后测试订单/grant 已清理。2026-11-25 新增会员取消 pending test order 的 Web UI 回归，断言 cancel API、列表刷新与状态/操作更新；Web Vitest 2 files / 9 tests 与 production build 通过。未完成真实商户/staging 流程或 MySQL/PostgreSQL 并发锁竞争 | 本地沙箱入口已验收；真实商户/staging 依赖外部环境 | 可与 Android 离线验证并行，文件所有权分离；真实商户接入仍等 Q1 资料 | 不重复本地支付成功路径；后续仅补新暴露的订单状态/恢复缺陷；真实渠道到位后验收商户回调/对账和目标数据库锁语义，当前仍无真实收款 |
| **Q2 取消订单付款状态边界** | 覆盖普通 sandbox payment 与 provider session 两条路径；取消后迟到 paid webhook 记为 `review`、不排 fulfillment/grant；payment 已被接受后取消返回 409 且 fulfillment 只发放一次。取消与 worker 锁序统一为订单→provider→fulfillment，ledger 重试记录器统一为 provider→outbox。Laravel 全套 265 passed、1 skipped、2,377 assertions，Pint 通过 | 本机阶段验收，待最终提交 CI | 只证明 local/testing SQLite 状态转换和顺序分支；生产数据库并发锁、真实商户 webhook/对账仍无证据 | 后续由新复现问题驱动，不重复已覆盖的取消 happy-path；取得正式渠道契约后验证异步回调、对账与目标数据库并发语义 |
| **Q2 发布可追溯性** | 候选 artifact 校验、SBOM/provenance CI 和 §0.2.3 go/no-go 清单复核已完成；`main@f507db0` 的 CI `45456754780` 六个 jobs 全绿 | 离线清单复核关闭；正式候选时间取决于外部 Environment、审批人和签名凭据配置 | 正式 Environment 尚不存在；不得创建环境或注入秘密，须由仓库管理员/凭据负责人经授权处理 | 等外部签名配置就绪后，由获批者构建并审查 APK/BOM/manifest/provenance；当前不放行 |
| **Q2 Windows proof capability 离线接线** | CNG P-256 non-exportable signer 与 server-authorized peer proof capability 的绑定签名测试通过；Windows UI 6/6/build 与 CI `45463883844` MSVC `cargo test`/build 全绿，一般 CI `45463883966` 全绿 | 本机无 Windows/Rust target；native 验收由 hosted Windows runner 完成 | 本阶段只增加 test seam；production bridge gate 继续 false | capability key-binding 单元关闭；下一阶段需先设计并测试 Tauri proof lifecycle/stop/logout/revoke adapter，仍不接生产 TUN/节点 |
| **Q2 Windows proof 生命周期失败关闭** | AccountClient loopback integration ready→ready 但 device snapshot identity mismatch→session 401 通过；Windows MSVC CI `45470774438` 与一般 CI `45470774428` 全绿 | 本机无 Windows/Rust target；native test 由 hosted Windows runner 验收 | 仅 `apps/windows/src-tauri/src/lib.rs` 与 PRD；不接生产 API/sidecar/TUN | 客户端 snapshot mismatch 与 session 失效关闭边界已验收；真实 server revocation event→process adapter→数据面停止仍是后续未验链路 |
| **Q2 Windows logout 失败时本地 proof 清理** | AccountClient loopback test 模拟 CSRF 成功、logout endpoint 500，验证本地 proof/cookie 仍清除；Windows MSVC CI `45472488726` 和一般 CI `45472488853` 全绿 | 单个 Rust integration test 已 hosted 验收 | 仅 `apps/windows/src-tauri/src/lib.rs` 与 PRD；不访问外部 API | logout 请求失败时的客户端本地清理已关闭；真实 API/revocation service 行为仍未验 |
| **Q2 Windows CNG installation-key mismatch** | 两个独立 CNG P-256 installation key 的 integration test 验证登记公钥与当前 signer 错配时拒绝创建 verified proof；Windows MSVC CI `45469444343` 与一般 CI `45469444398` 全绿 | 单个 native test 已 hosted 验收 | 仅 `apps/windows/src-tauri/src/cng_device_key.rs` 与 PRD；临时 CNG keys 测试后删除 | 客户端 key-binding 错配单元关闭；不代替节点端凭证复制拒绝 live harness |
| **Q3 外部协调** | 节点、仓库 Environment、staging、商户、合规负责人及日期尚未确认；用户已表示稍后提供外部放行责任人 | AI 汇总缺项约 30–60 分钟/轮；等待时长未知 | 用户提供负责人后再登记姓名和承诺日期；未经授权不代发消息 | 把负责人和下一动作映射到上方 Q1 表，按真实日期重排 |
| **Q4 后续范围** | Windows 数据面、macOS/iOS、规模化并发、多云/CDN、非首发细节不影响 Android + Web/API 单服务商受控试点的近期最短路径 | 当前冲刺不分配 | 放入后续路线图；只有 Q1 发布门和首发链路稳定后再重估 | 暂无，本周不启动 |

**2026-11-25 Android 进程死亡冷启动恢复阶段**：四象限为 Q2（重要的客户端生命周期失败恢复，不解除真实会员节点 Q1 上线门）。新增独立 Android process-death host instrumentation，专用 AVD 上由 host 精确杀掉 debug app，之后经 VPN consent 批准与显式重新 launch 恢复 pending member request；local/testing Cookie 会话仍有效，客户端重新提交 P-256 proof 并进入生产关闭 gate。独立命令 `PROCESS_DEATH_ONLY=true apps/android/scripts/test-anonymous-recovery-emulator.sh` 与完整 AVD harness 均退出码 0；主 instrumentation 25/25、6 项专项测试通过；Debug/AndroidTest JVM/编译/lint 通过，hosted CI `45446581811` 六个 jobs 全绿且 Android job 编译独立 host APK/instrumentation APK。该测试明确覆盖显式冷启动恢复，不声称 OS 自动重建 Activity。下一项继续处理 Android/API 首发离线缺口，并优先跟踪真实节点 profile/mTLS/撤权回执外部门。文件所有权限于 Android app、process-death-host 与 AVD harness；未碰 AWS/云、生产环境或真实节点。

**2026-11-25 Web 订单取消 UI 阶段**：四象限为 Q2，针对会员本地测试订单生命周期补齐“用户取消待付款订单”前端验收。新增测试验证确认取消后的 POST、详情状态、订单列表再次获取及已取消后不显示沙箱付款入口。Web 2 个测试文件、9 项通过，`pnpm --filter @xlb/web build` 通过。此阶段不触发真实资金、商户 API 或节点撤权；后续优先从新缺陷证据推进，不重复本地 happy-path mock。

**关键路径**：节点/撤权材料 → Android 真实会员 profile 与双身份回执 → 受保护签名候选 → staging 登录/支付沙箱/回滚 → go/no-go。商户 sandbox 与合规可和节点开发、签名准备并行，但都必须在开放付费试点前通过。Android 离线失败处理、Web/API 沙箱回归、候选 workflow 检查可按目录边界并行，集成时统一以最新 main 运行全套 CI。AI 当前主要耗时应投到 Android 会员路径缺口与候选/测试的可重复性，不再继续深挖已经覆盖较多的 M10 合成探测状态边界。

**2026-11-24 Android callback 失败关闭增量**：专用 AVD 现覆盖 Activity recreation 后返回 VPN permission approved、但 fresh API authorization 已被 fake API 撤销的路径。Activity 重新挑战签名，收到 revoked 状态后不保留 pending grant、不发 service handoff，结果前后没有新的 VPN service state 广播；定向 1/1，主套 25/25 及专项 instrumentation 通过。提交 `9beca4d` 对应 CI `45423725347` 六个 jobs 全绿。此项仍是 loopback fake API/模拟 permission result。后续新增的 2026-11-25 独立 host instrumentation 已在 AVD 实测目标 app 进程死亡后的显式冷启动恢复，因此“Android OS 真实进程终止恢复尚未验”的旧描述已过期；仍未验证系统自动重建 Activity、生产 PHP authorization、真实节点和真实 member tunnel。

**当前可并行泳道**：

1. Android 目录：只负责服务准入、连接失败恢复与现有诊断可用性，验收为 Android JVM/AndroidTest compile/lint/必要 AVD；不得打开生产 member gate。
2. `apps/php-api` 与 `apps/web`：只负责 local/testing 会员购买退款和网页状态链路回归，验收为 Laravel/Web 定向及相关全套测试；不触发真实资金。
3. `ops` 与 `.github/workflows`：只负责候选清单、权限边界和 dry-run 可重复性，验收为脚本单测、workflow 回归及 hosted CI；不读取 secrets、不创建 protected Environment。
4. 外部放行泳道由节点、仓库、staging、支付和合规负责人并行；AI 不向外部发送消息。最终 staging 与真实双机验收依赖前述材料，必须串行执行。

每个工作项开工前登记四象限、范围/文件所有权、可验收证据和估时。若一个单元超过两小时仍无边界，拆成检查点。只有存在真实上线阻塞的 Q1 才可打断已开始的工作；Q3 状态同步集中处理，Q4 不进入一周首发排期。知识库通用规则见 `10-全局/规范/AI项目四象限与持续推进.md`。

### 0.2.4 Worktree 未合并成果复核（2026-11-24）

**Web/API 本地浏览器联验（2026-11-24）**：用 Playwright CLI 对本机会员 portal 与 Laravel 完成登录→套餐选择→建单→确认沙箱付款；HTTP 201 创建测试订单和接受模拟付款，UI 展示 `paid_test`、权益延长、固定订阅 QR/link。随后 sandbox refund 清理本次订单，并在 SQLite 核验订单和 entitlement grant 均为 revoked 测试态。浏览器请求没有触达商户 API；不代表真实收款、生产支付、staging 或节点撤权回执。订阅 URL 型凭证只在本机临时浏览器快照显示，本轮快照已删除。

**M11 外部签名配置复查（2026-11-24，只读 GitHub 元数据）**：本轮再次查询 repository environments 与 Actions variables，均为空（Environment `total_count=0`、variables `total_count=0`）；没有读取 secrets、创建 Environment 或发起候选 workflow。`android-release-candidate` 仍需仓库管理员设置 main-only 部署规则与审核者，并由凭据负责人通过受控通道配置正式签名/API origin 后，才可进行审批后的手动候选构建。

本轮只读盘点发现 34 个 worktree，其中 33 个带分支；按 `git cherry -v origin/main HEAD` 区分 patch 等价与真正独有改动后，仅 3 个分支有主线未包含的 patch：

| 分支 | 独有 patch | 复核结果 |
| --- | --- | --- |
| `codex/android-node-auth-status` | 1 条节点授权状态历史说明 | 对应代码已进入主线，当前 PRD 已包含较新的 Android API/授权诊断状态；旧段落仍称其代码提交待合并，事实已过期，未合并旧文档 |
| `codex/aws-inventory-status-refresh` | 3 条 Android grant handoff / foreground-start 提交 | 主线已有后续 handoff 和 stale-intent 修复及 Android JVM/AVD/CI 证据（如 `d718d90`、`b283316`），接口与测试已演进；不把旧实现重复 cherry-pick，也不覆盖当前文件 |
| `codex/fleet-recovery-preview` | 1 条 11-23 估期与 AWS 状态记录 | 与 11-24 冲刺目标、AWS 复核状态和 blocker 清单冲突，属于过时历史文案，未合并 |

其余 worktree 的领先提交均为 patch 等价或没有领先提交。主线及两个辅助 worktree 当前存在未提交/未跟踪内容；只读检查后全部保留，其中不提交 Playwright/output 临时目录、Android 辅助 worktree 测试编辑与内存转储文件。未重置、清理或合并任何辅助 worktree。后续并行审查以独有 patch 和当前代码语义为准，单看 ahead/behind 数量不能作为待合并工作量。

### 0.2.3 Android 候选阶段 Go/No-Go 检查清单（2026-11-25 复核）

这是候选构建与后续受控试点的检查单，不授权创建 GitHub Environment、写入 secrets、部署或发布。

| 阶段 | Go 条件 | 当前结论/动作 |
| --- | --- | --- |
| 源码冻结 | 候选来源为已审阅的 `main` commit；常规 CI 六个 jobs 全绿；构建理由可关联到 issue/审批记录 | `main@f507db0` 已推送，CI `45456754780` 六个 jobs 全绿；当前代码/CI 证据满足构建基线，正式候选冻结仍须绑定审核/审批记录 |
| 签名权限 | `android-release-candidate` Environment 存在、仅允许 main、设置人工审核者；签名 secrets 和 approved API origin 由负责人经受控通道配置 | 2026-11-25 GitHub 只读 API 返回 Environment 数量为 0；未查询 secrets、未创建或修改 Environment，签名候选不可运行 |
| 候选构建 | 获批者手动运行 workflow；Release unit tests、lint、APK signature/package identity 检查、runtime SBOM、APK+SBOM source-bound manifest、manifest 校验及两项 provenance attest 全部成功 | 常规 CI 仅以临时测试密钥检查签名配置并产出 Debug APK/SBOM；正式 workflow 仍受 `workflow_dispatch`、`main` 与 protected Environment 限制。没有正式凭据，未构建签名候选 |
| 候选审查 | 下载 APK/BOM/manifest；核对 manifest source commit 等于审阅 commit、摘要一致；验证 APK signer/package 与来源证明；记录审阅结果 | 当前无正式签名候选制品；脚本和 CI 规则已覆盖校验路径，但该阶段未通过 |
| staging 放行 | 负责人提供已批准 staging、数据保留与回滚窗口；候选摘要固定后部署；登录/权益、商户沙箱、Android 安装/线路和撤权回执 smoke 通过；回滚演练有记录 | 尚无 staging 环境、窗口与回滚责任人记录；不部署、不产生云费用 |
| 付费试点 Go/No-Go | Android 双身份复制拒绝和匹配节点撤权最终流量回执、商户 sandbox 对账、目标市场合规意见、签名/制品审查、staging 和支持/回滚责任均有证据 | **No-Go**：真实会员节点/撤权回执、真实商户 sandbox/对账、合规意见、正式签名候选、staging 与责任人日期均未齐。离线 CI、AVD、合成 peer、local/testing 不能代替放行证据 |

**离线检查证据**：ops workflow 权限/步骤和 artifact manifest 正负例测试曾 10 项通过，常规主线 CI `45455644892` 六个 jobs 全绿。它们验证规则和候选构建技术路径，不实际运行正式签名 workflow、不访问 secrets、不产生正式候选 APK，也不代表 protected Environment 或发布放行已验收。

**沙箱购买/退款回归复核**：`apps/php-api` 的 `OrderLifecycleTest`、`SandboxPaymentTest`、`SandboxRefundTest` 与 `Payments/ProviderPaymentEventLedgerTest` 共 31/31（325 assertions），Web Vitest 2 files / 8 tests 通过。验证的是 local/testing 数据库和组件/接口测试，不包含浏览器真实支付流程、真实商户、节点断流或 production/staging。

四象限按对 12-01 Android + 网站/API 受控试点的影响与实际截止时间排序。两天半以上的项目运行时长不能直接当作 AI 编码工时；PRD/提交记录显示近期高投入集中在 Android service/grant 并发与生命周期失败关闭、M10 探测到路由过滤的多层测试与 hosted CI。它们是重要的安全/稳定性建设，但本机 canary 与合成状态不能交付真实会员线路；额外离线细节的边际价值已经低于外部放行材料。

| 象限 | 当前事项 | 下一步、负责人和退出证据 |
| --- | --- | --- |
| Q1 重要且紧急 | 真实会员 profile、节点 endpoint/CA/mTLS 身份与匹配撤权/最终流量回执 | 节点/运维负责人提供获批材料和隔离窗口；AI 先只读检查目标，再按授权 plan/apply，完成两台会员身份的真实连接、复制拒绝和撤权回执验收。没有节点回执不宣称稳定、精确限设备或断流。 |
| Q1 重要且紧急 | 受保护 Android release Environment、审批人、正式签名与 approved API origin | 仓库管理员/凭据负责人配置并证明环境保护和凭据可用；签名候选 workflow 成功产出 APK、SBOM、manifest 与 provenance。当前只读 API 查询未列出任何 Environment，因此该门尚未打开。 |
| Q1 重要且紧急 | 获批 staging、部署/回滚责任人与窗口 | staging 负责人给出隔离环境和维护窗口；通过候选摘要核验、登录/权益/支付沙箱 smoke 与回滚演练。不得未经批准创建云资源或产生费用。 |
| Q1 重要且紧急 | 商户沙箱回调契约、首发市场合规书面意见 | 支付/业务负责人提供商户 sandbox、签名/重试/对账契约，合规负责人给出针对首发市场的书面意见；provider-neutral 代码和本地沙箱不能代替这些材料。 |
| Q2 重要但不紧急 | Android + API 离线关键路径、默认关闭的安全门和失败处理 | AI 继续补最小的独立回归，优先会员授权/失败关闭、沙箱购买退款及当前已打开阶段；遇到节点材料时转入 Q1 联验。已经有高覆盖的 M10 本机 probe/route-filter 回归暂缓继续加同类细节。 |
| Q2 重要但不紧急 | 候选可追溯性、恢复/回滚与小范围运维手册 | AI 复用已有 workflow/manifest/SBOM，补齐 staging 前所需的最小检查表；只做会影响首发决策或应急处理的项目。 |
| Q3 紧急但不重要 | 外部负责人催办、短状态同步、临时资料归档 | 项目负责人按依赖清单协调，集中处理而非打断连续开发；仅在实际影响截止期时升级优先级。AI 不擅自向外部联系人发消息。 |
| Q4 不重要且不紧急 | Windows/macOS/iOS 数据面、3000 并发扩容、CDN/多云扩展、非首发产品细节与重复文档润色 | 移出本周首发关键路径，记录在后续路线图；本周不因容易实现或测试漂亮而抢占真实会员线路、签名、staging 和合规放行工作。 |

**重新排序规则**：将可离线推进的 M10 深度补测设为当前停止扩张的 Q2 工作包；每轮先列出一个 Q1 外部行动及一个 AI 可交付工作，外部阻塞期间继续不依赖该材料的首发代码/候选检查。每个子任务目标控制在约 2 小时内可验收，超出时拆成明确检查点；外部等待和 CI/设备运行单独计时。不得通过降低设备限制、关闭安全门或跳过真实回执压缩日历。

### 0.2.2 Q1 放行门外部行动清单（2026-11-25 复核）

以下工作不能由 AI 离线代码替代。负责人按职责角色列出，具体姓名/日期未由用户或责任人确认，因此记录为待落实，不假设已经有人承接。每个行动项需要在本周首发 go/no-go 前更新状态。

| Q1 门 | 当前证据/状态 | 需要的负责人动作 | 可核对退出证据 | 截止检查点 |
| --- | --- | --- | --- | --- |
| 会员节点与撤权回执 | 11-25 经 `ops/aws-cli` 只读尝试确认 `xlb-ops` STS 已过期，未继续查询 AWS 资源；实验资源最新一次历史只读盘点为空（11-24）；尚无会员专属线路/节点材料 | 节点/运维负责人提供隔离节点 endpoint、节点身份、CA 与生产用途 mTLS 注入路径、获批 canary 地址；用户本人先重新 `aws login`，之后只读盘点并审查获批 plan；指定窗口让两台绑定设备跑连接/复制拒绝/退款撤权 | 节点 `node/peer/request/generation` 匹配的回执、最终累计流量与重连拒绝；两台设备各自输出脱敏版本/时间证据 | 11-25 负责人/材料尚未确认；11-27 仍为原计划目标、并非已承诺窗口；缺材料则 12-01 不可称受控试点上线 |
| Android 受保护签名候选 | 候选 workflow 和签名配置离线验证通过；11-25 GitHub 只读 API 返回 Environment 数量 0 项 | 仓库管理员创建 `android-release-candidate` protected environment，限定 main、设置审核者；凭据负责人按批准通道注入 keystore/password 与 approved API origin，不在仓库/聊天传递 | 获批者手动运行 workflow，保存 APK、BOM、manifest、provenance 与审核结果；不自动建 GitHub Release 或上架 | 11-25 管理员/凭据负责人尚未确认；11-27 仍为原计划目标、并非已承诺候选日期；未配置不得称候选可审核 |
| staging 与回滚 | CI 容器构建/HTTP smoke 通过；无 staging 部署记录 | staging 负责人确认隔离 URL、数据库/数据保留、部署权限、回滚责任人与维护窗口；任何新增费用/云资源先单独审批 | 按同一源 commit 部署、健康检查、登录/权益/支付沙箱 smoke、回滚演练记录 | 11-25 确认窗口；11-28 前验收，否则不开放付费试点 |
| 商户沙箱与首发合规 | provider-neutral 支付及退款状态机只有 local/testing 证据；真实商户与首发市场书面意见未登记 | 支付负责人提供单一商户 sandbox、回调契约、签名/重试规则和对账字段；合规负责人确认首发地区、退款/隐私/服务条款并给书面意见 | 商户 sandbox 下单→回调→权益→退款→对账记录，以及可审计合规结论 | 11-25 确认资料/责任人；11-28 前验收，否则只保留本地沙箱演示，不宣称可收真实款或可上线 |

**当前判断（2026-11-25）**：负责人及日期都没有本轮确认，核心节点材料、签名 Environment 明确缺失，`xlb-ops` 会话本轮检查时已过期；因此 12-01 是内部冲刺检查点，不是可信的上线承诺。用户本人需恢复 AWS CLI 登录后，才能进行获批窗口前的只读资源盘点；不得为恢复会话而代替用户登录。外部泳道应与离线代码并行，但等待责任人不能由 AI 通过重复增加本机测试替代。下一 AI 工作限于首发 Android/Web/API 有新证据支持的测试缺口和候选 workflow 检查；M10 同类全链探测测试暂时冻结，直到出现新故障证据或真实 canary 材料。

**2026-11-24 Android service 销毁清理未消费 grant**：专用 `xlb-m9-recovery` AVD instrumentation 先复现 service 销毁后单次 member handoff 仍留在进程级 grant store；`XlbVpnService.onDestroy()` 现在同步清除 pending grant。修复后定向 instrumentation 1/1、service admission instrumentation 整组 4/4 与 Android 全量 JVM、lint、AndroidTest 编译、Debug APK build 通过，主线 CI `45278603034` 全绿。仅验证 AVD service 生命周期，不开启生产 member gate，不建立真实 VPN/TUN 或节点连接。

**2026-11-24 Android member grant 并发单次消费**：新增 8 个并发消费者竞争同一 `MemberTunnelGrantStore` handoff ID 的 JVM 回归测试，唯一消费者取得 grant，其余均为空；Android JVM 全套、lint 与 AndroidTest 编译通过，主线 CI `45276068548` 六个 jobs 全绿。生产 gate 保持关闭，未连接真机、建立 TUN 或接入真实节点。

**2026-11-23 Android 授权失效停止策略增量**：service 将已准入会员隧道与通用 Debug 测试线路区分；账户同步发现权益失效时停止两类连接，发现会员 node authorization 不再 `READY` 时只停止会员连接，资源清理失败期间不清除会员会话标记。JVM 状态组合测试、AndroidTest 编译/lint 与专用 AVD 关闭 gate harness 通过；gate 仍关闭，未验证活动会员 TUN、真实授权变化或节点撤权回执。本轮 PRD 已记录该边界，后续 hosted CI 结果以主线提交检查为准。

**2026-11-23 Android session cancel-boundary 增量**：`MemberTunnelSessionTest` 现对证书创建、proof bridge、profile 加载、TUN 建立、线路验证五个启动边界逐项模拟不响应中断的操作，证明 stop request 本身不被这些阶段阻塞；阶段恢复后 startup 不跨越下一边界，并按序执行资源清理。定向 session 测试 5/5、Android 全套 JVM 与 lint 通过。它不能保证 native 调用永久不返回时资源最终释放，不建立系统 TUN，也未使用真实节点；会员 gate 保持关闭。

**2026-11-23 Android start/stop 代次门控增量**：`TunnelAttemptGate` 将 stop/revoke/destroy 与 start 编入单调代次，service 会跳过队列中已失效的通用 start，并在 core/profile/TUN 阶段边界检查旧代次；旧尝试不能结束较新的 attempt。JVM gate tests、Android 全套 JVM tests、AndroidTest 编译、lint 通过。仅本机测试；未运行 Android TUN、实体设备或真实会员节点，会员 gate 保持关闭。

**2026-11-24 Android 会员会话标记代次保护增量**：将 `currentTunnelIsMember` 变更与 VPN service 当前 attempt 的代次检查原子化，旧启动清理结果不能清除更新会员 attempt 的标记；STOP/revoke/destroy 在资源清理成功前保留旧会员标记，清理结束只由仍有效的代次发布 DISCONNECTED。新增旧 attempt 清理被替换 attempt 拒绝的 JVM 回归测试；JDK 21 下 Android 全套 JVM、AndroidTest 编译、lint、Debug/AndroidTest APK build 通过，专用 `xlb-m9-recovery` AVD 的 rapid stop/start instrumentation 1/1 通过；提交 `83591b9` 的主线 CI `45213142154` 全绿。未运行实体手机、系统 TUN 或真实节点，member gate 仍关闭。

**2026-11-24 Android worker 状态广播代次保护增量**：worker 的 CONNECTED/ERROR 与 stop completion 状态只有在 `TunnelAttemptGate` 原子确认当前代次时才能发布，当前 start 的 CONNECTING 广播和路由名同样成组更新；旧终态不能覆盖新 start 的 UI/service 状态。新增 finish callback 被替换代次拒绝的 JVM 测试；Android JVM、lint、AndroidTest 编译、Debug/AndroidTest APK build 与专用 AVD rapid stop/start 1/1 均通过；提交 `c7ab282` 的主线 CI `45214979632` 全绿。未运行实体设备、会员 TUN 或真实节点。

**2026-11-24 Android grant handoff 与 service intent 绑定增量**：每个 process-local grant 获得单调单次 ID，service intent 必须带匹配 ID 才能消费；旧 intent 和旧调用方的失败清理不能触碰替代授权。过期 foreground start 会安全完成 Android 前台服务生命周期，替代 grant 待处理时保留匹配 intent 的服务实例，STOP/revoke 继续显式清空待处理 grant。JVM store tests 验证旧 ID 不能取走或清空新 grant；专用 AVD service stale-intent→replacement-grant case 与 PlatformApi→service→logout case 各 1/1 通过，Android JVM/lint/AndroidTest compile/Debug APK build 均通过；提交 `d718d90` 的主线 CI `45218168507` 全绿，前台生命周期修复提交 `b283316` 对应主线 CI `45227271566` 全绿。member gate 保持关闭，测试不建立系统 TUN 或真实节点连接。

**2026-11-24 Android 授权刷新期间的待处理 grant 撤权增量**：`MemberTunnelStopPolicy` 将 service 已标记会员线路与 grant 已发布但尚未消费都视为会员启动；授权变为 `UNAVAILABLE` 时可及时 STOP 并清除 handoff，延迟到达的旧 member intent 不能重新启动。JVM 覆盖待处理 grant 与已就绪授权的策略组合；专用 AVD Fake API→PlatformApi→grant store→service 撤权/延迟 intent 类 2/2 通过，Android 全套 JVM、lint、AndroidTest 编译及 Debug/AndroidTest APK build 通过。只证明客户端本地授权状态竞态，member gate 仍关闭，未建立系统 TUN 或真实节点连接。

**2026-11-24 Android 启动自动连接改走已验证会员 grant**：修复自动连接检查账户配置后调用 generic tunnel、而非 member grant 入口的模式错配。启动连接现在要求显式开启、VPN permission、有效权益、完整 server-authorized grant 和无现有隧道，通过单次 member handoff 进入 service；缺少 grant 时关闭失败。策略 JVM tests、Android 全套 JVM、lint、AndroidTest 编译、Debug/AndroidTest APK build 通过。未建立系统 TUN 或真实节点，member admission 仍关闭。

**2026-11-24 Android VPN 权限回调恢复会员授权**：Activity recreation/process restoration 不保存授权 grant 或 profile，只保存一次性“会员权限请求待完成”标记；权限批准返回而进程内 grant 不在时，重新验证当前会话/权益/节点授权并取得新 grant 后才发起 member service handoff。策略单测与专用 AVD `ActivityScenario.recreate()` 测试通过，Android 全套 JVM/lint/AndroidTest compile/Debug APK build 通过。AVD 测试没有触发真实系统权限弹窗或模拟进程终止，也未建立 TUN/真实节点连接。

**2026-11-24 Android 权限批准回调→fresh grant→service gate 联合验收**：专用 AVD 测试将 local FakeMemberApi、真实 `MainActivity` 生命周期重建、恢复后的批准回调、Keystore challenge 签名、新授权解析和单次 service grant handoff 连在一起；member gate 仍关闭，预期由 service 明确拒绝授权，测试核对无假连接状态。该用例 1/1、Android JVM tests/lint/AndroidTest compile/Debug 与 AndroidTest APK build 通过；主线前置提交 CI `45237384820` 全绿。测试没有弹系统 VPN 权限窗口、杀死 app process、建立 TUN 或连接真实节点。

**此前 Android 增量，以主线 `342a7d8` 为准**：Android `PlatformApi` contract-shaped loopback grant→VPN service 关闭 gate、ACTION_STOP、撤权与登出清理测试通过；伪造 profile digest 时 `MemberTunnelGrant` 不产生；权益同步失效时 `CONNECTING` 与 `CONNECTED` 均触发 stop。专用 AVD 19/19 基础 instrumentation，新增 API integration cases 各 1/1，Android JVM/AndroidTest 编译/lint 通过。hosted CI run `45196145412` 全绿。测试使用 loopback 假 API 与 service 测试边界，未建立系统 TUN、未连接 PHP ready authorization 或真实节点，Android member gate 仍关闭。中间 CI run `45195474224` 的 Laravel `NodePeerProvisioningTest` 曾出现 peer 保持 provisioning 的单次失败，下一主线全套 run 通过且 PHP 代码未变，原因未复现，保留为待观察的 CI 波动。

| 发布阶段 | 状态 | 下一退出门 |
| --- | --- | --- |
| E0 地基 | 部分完成（Android native APK 检查、metadata-only 清单 `44943107063`、源码与 Android runtime SBOM artifacts `45013262238`、SBOM provenance attestation `45028876877` 均 hosted 全绿；main CI 增加 1 日临时 key 的 signed-config 验证，隔离构建目录、本机签名指纹与包名校验通过；新增受保护环境/manual-only 的 Android signed release-candidate workflow） | 该 workflow 尚无 protected environment、审核者、release signing secrets 与正式 API base URL 配置；仅在环境审核通过并注入正式密钥后构建签名候选 APK，artifact manifest/provenance 供审阅，不创建 Release 或商店发布。仍需 staging/回滚、可信制品保留；合规与 D 决策保留为发布门，E0 放行要求正式制品验签与 attestations 通过 |
| E1 会员数据面放行 | 未通过 | 真实 member profile、双真机复用拒绝、节点撤权回执/最终流量和连接验收 |
| E2 工程化 | 部分完成：`node_fleet_plan.py` 有界 manifest / deterministic plan-only waves；`node_fleet_recovery.py` 本机 UTC review-only 失败回收预览绑定 release intent、batch/generation、账号区域、归属标签和 5 分钟 inventory 新鲜度；ops 52 tests，hosted CI `45116749400` 全绿 | 尚无认证 inventory 数据源、批量执行器、真实失败回收、常驻受控 worker、实测容量与热备/回收演练；preview 不代表可执行或真实资源事实 |
| E3 四端 | 部分完成 | Android 会员真机数据面闭环；Windows 账户壳与 MSVC/Credential Manager smoke 已通过 hosted run，下一步才是 Mihomo/proof bridge/TUN 与 Windows 运行验收；macOS/iOS 条件与后续验收 |
| E4 分发监控 | 未开始 | 用户批准资源/预算后接静态资源 CDN 与可触发告警；CDN 不承载 VPN 数据面 |
| E5 试运行 | 未开始 | 合规书面门槛、E1–E4、对账/SLO/支持流程均通过后再试点 |
| E6 多云 / E7 放量 | 未开始 | 第二家厂商 conformance 和蓝绿释放证据；依据容量/成本逐级放量 |

**当前执行队列**（可并行项共享契约先定；每个分支只拥有其模块，独立测试/commit 后由主控串行合并）：

| 工作包 | 文件/模块边界 | 依赖与退出条件 |
| --- | --- | --- |
| A. M10 真实 probe executor 与运行验收 | `apps/node-agent/**`、`apps/php-api/**` 分别限定 Agent executor/runtime 与 PHP transport/config/Feature tests | `nodes:probe-health` 命令现经本机 TLS 1.3/mTLS 调用 Go Node Agent，Agent 再连本地 HTTPS canary，并将结果写入 RefreshDatabase 管理的临时 SQLite；`NodeRoutingPolicy` 接受新鲜健康候选并拒绝过期或 unhealthy 状态；PHP 定向 1/1、34 assertions。Node Agent race 133、vet/build 通过。该代码只证明本地/隔离 canary 协议，真实 URL/CA 未配置且无隔离节点运行；probe/route filter/scheduler 默认仍关闭。下一步须由节点/运维提供获批 canary URL、CA 与节点 server/client identity，运行隔离联验并检查 timeout/cert failure、状态新鲜度和恢复，不让 liveness 或合成回执标记真实健康。`health_check_ttl_seconds=300` 与 probe route-state TTL=30 秒是不同状态源 |
| B. E0 制品可追溯性 | `.github/workflows/**`、`ops/build_artifact_manifest.py`、Android Gradle | Debug APK 来源/摘要清单及 6 项测试已实现，hosted run `44943107063` 全绿；Syft 源码/锁文件 SBOM 与 Android Release runtime CycloneDX SBOM 在 `45013262238` 全绿并上传为两个 7 天 Actions artifacts。main push 上的 GitHub SBOM provenance attestation 在 run `45028876877` 全绿，source 与 Android SBOM 下载后的 subject digest 匹配，`gh attestation verify` 各验证通过 1 份；此前 tlog `404` 后，attestation 移入可见但不阻断开发 CI 的独立 job，并单次延迟重试。E0 放行仍要求 attestation job 成功且 artifact digest 验证通过。registry push/storage record 关闭；该 attestation 不给 APK/Release 签名、不上传 APK、不建 Release；生产制品签名、可信存储/保留与发布审批仍未实现 |
| C. M11 本地负载分层 | `loadtest/**`、验收报告 | 80请求的 loopback smoke 已完成；后续需在获批 staging 测控制面阶梯/长稳并单独审批，现有结果不能外推生产容量或 VPN 节点 |
| D. Windows CNG verifier/真实 sidecar adapter | `apps/windows/src-tauri/**`，Rust supervisor/lifecycle contract 与 CNG signer 已合入 | CNG P-256 signer hosted 验收（29/29，`44959472000`）与 server-authorized peer capability 门禁已完成（36/36、MSVC build、UI tests/build，全绿，`44994703324`）；graceful-stop 回退已通过 `45013647469`（41/41）。权益快照门禁通过 `45015001854`（42/42）。生产 `start` 现实际检查 constant-false feature flag，在 flag 关闭时 fail closed 且不调用 adapter；正向 fake lifecycle 仅经 `cfg(test)` helper。Windows workflow `45019455975` 与整仓 CI `45019456008` 全绿；bridge/TUN 仍未接入，不得外推为会员线路/严格限设备验收 |
| E. M6/M8 真机与节点闭环 | Android/Node Agent 契约分开验收 | 两台现有手机的当前 installation 已各自完成沙箱登录与独立 P-256 绑定，不需要第三台手机；手机已拔除，不影响离线开发；会员真机隧道/复制拒绝/匹配撤权回执未验收。11-24 的只读资源盘点为空是历史状态；11-25 经 `ops/aws-cli` 包装器复核时 `xlb-ops` STS 已过期，未复查当前资源。需用户本人重新 `aws login` 后再做获批窗口前的只读盘点；仍缺会员 profile、节点 identity/endpoint、mTLS 注入材料与匹配回执。 |
| F. Android 授权状态快照保留（2026-11-22 已完成） | `apps/android/app/src/main/java/com/xlb/vpn/data/PlatformApi.java`、`apps/android/app/src/test/java/com/xlb/vpn/data/NodeAuthorizationStatusTest.java` | overview 合并后仍保留 `READY` 与 `PROVISIONING`；Android JVM 定向/全套测试、`lintDebug`、`assembleDebug` 通过。只验证客户端纯数据快照，不表示服务端授权或 VPN 已连接 |
| G. Windows CNG challenge/authorize 设备状态（2026-11-22 已完成） | `apps/windows/**`，独立 worktree `codex/windows-device-enrollment` | `106b14a` 合入 `ada4de2`；按 API origin 持久化 installation UUID、challenge 签名/授权状态 fail closed；React 6/6、pnpm build、hosted Windows Rust 35/35、Credential Manager smoke、MSVC build 与主 CI 通过。bridge/TUN 保持关闭，不外推为会员 VPN 或严格限设备验收 |
| H. Android Keystore digest signer 合同（2026-11-22，已合入） | `apps/android/**`、`apps/node-agent/internal/androidsigner/**` | `73c82cd` 等效合入 raw-digest `NONEwithECDSA`/Go `CryptoSignerAdapter`；专用 API 35 AVD instrumentation 3/3，legacy SHA256-only alias fail closed；Go full/race 106、vet/build，Android assemble/JVM/lint 通过。`3751174` 已合入 Gradle `connectedDebugAndroidTest`/`installDebugAndroidTest` pre-install AVD-only guard；fake adb 覆盖多设备/离线设备/错 serial/错 AVD/非 emulator。未接生产 bridge/VPN |
| I. Android Keystore→Go TLS 1.3 mTLS signer callback 合同（2026-11-22，专用 AVD 10/10 通过） | `apps/android/**`，主线 `33da257` | instrumentation-only Java Keystore signer→JNI→Go `crypto.Signer` 完成临时 TLS 1.3 mTLS handshake；Go host race test 用软件 signer 覆盖证书/签名链和 key mismatch；Keystore instrumentation 覆盖真实 Keystore 身份、另一 key 拒绝和 callback 异常失败关闭。Android Debug/Release NDK build、JVM、AndroidTest compile、lint、Go tagged race/vet 与 fake guard 通过；专用 harness 经隔离 ADB 5038 运行且只见 `xlb-m9-recovery`，共 10/10。未接 `ProofBridgeSupervisor`/`XlbVpnService`/Mihomo/TUN；下一步依 server-verified peer artifact/profile delivery 实现生产接线 |

| J. Android server-verified peer proof 与 bridge supervisor 输入类型统一（2026-11-22 本机离线验收） | `apps/android/app/src/main/java/com/xlb/vpn/data/MemberDeviceProof.java`、`PlatformApi.java`、`tunnel/ProofBridgeSupervisor.java` 与 JVM tests | PlatformApi 的验证结果与 supervisor/adapter 输入统一为 opaque proof 类型，当前无 UI/service 调用点传入；只暴露身份引用和 Keystore signer 操作、不含 credential/profile；完整合法 proof 仍因 `PROOF_BRIDGE_ENABLED=false` 返回 `FEATURE_DISABLED` 且不调用 adapter。JVM 45/45、lint、Debug APK 与 AndroidTest 编译通过。没有接 `XlbVpnService`/JNI/Mihomo/TUN；adapter 启动、授权重检与线路验收仍未实现 |

| K. Android member profile 通用 tunnel 准入失败关闭（2026-11-23，专用 AVD 验收） | `apps/android/app/src/main/java/com/xlb/vpn/tunnel/MemberTunnelAdmissionPolicy.java`、`DebugProfileFetcher.java`、`MainActivity.java`、`XlbVpnService.java` 与 JVM/instrumentation tests | Debug importer 要求 `X-XLB-Profile-Scope: local-test`；MainActivity 与 non-exported XlbVpnService 双重检查准入标记；服务端 profile scope 必须精确为 `local-test`，XlbVpnService 拒绝未标记 ACTION_START 并取消旧连接尝试；隔离 harness 下 Android instrumentation 11/11（含 service 拒绝路径），JVM 48/48、AndroidTest 编译、lint、Debug APK 构建通过。Debug test route 不代表会员线路或设备证明；member bridge/TUN 集成仍未验收 |
| L. Node Agent proof bridge 可复用 Go API（2026-11-23 已完成） | `apps/node-agent/bridge/**`、`cmd/xlb-bridge/main.go` | 将桥接实现从 `internal` 包提升为公开 `xlb/node-agent/bridge` import，保持 `xlb-bridge` isolated-test 运行门不变；Go race 108、vet、build 通过。Android native 尚未依赖该包或启动 bridge |
| M. Android Keystore → Go signer device certificate（2026-11-23，本机与 AVD 验收） | `apps/android/app/src/main/java/com/xlb/vpn/data/DeviceProofCertificate.java`、Android JNI、`apps/android/core-native`、Node Agent cert lifetime API | server-authorized opaque proof 的安装级 Keystore signer 经 JNI 生成 10 分钟、身份扩展绑定的 P-256 client certificate；Debug/Release native build、Android JVM 48、专用 AVD instrumentation 12/12、Go race 109、vet/build 通过。bridge 与 VPN service 尚未消费该证书，feature flag 仍关闭，真实 member profile/节点握手/断流未验；下一步完成默认关闭 adapter lifecycle 与服务端撤权重验 |

M9 的 D2（邮件/手机/扫码跨端方式）、M10 的 D3（智能路由适用账号级还是逐设备线路）、E1 高可用拓扑/SLO 和 E4 资源费用是需用户拍板的项目决策；各自之外的离线契约、测试和模块工作继续推进。合规书面评估、生产 mTLS/运行身份、真实支付商户资料属于上线外部门槛，不阻止本地实现。每个工作包以最新 `origin/main` 开独立分支，阶段通过后 commit，并由主控运行 `ops/merge-worktree.sh <branch>` 合并推送；禁止提交 `.env`、私钥、数据库和临时产物。

### 0.2 近期依赖顺序与并行边界（2026-11-22）

**worktree / 依赖审计（历史快照：2026-11-22，审计基线 main=`84217ce`，记录提交 `6e64777`）**：当时只读 Git 图审计 28 个 worktree（main + 27 个 codex 功能分支）；功能分支补丁均已在主线或等效，不应重复倒合。该快照曾发现 `android-native-ci` 用户改动以及主 worktree `.playwright-cli/`、`output/` 未跟踪产物，均要求保留。2026-11-23 最新 M6 与 AWS 状态以 §0.1、§0.3 为准；此处历史 STS 结论不代表当前会话有效。

**worktree / 分支只读复核（2026-11-24）**：当前 34 个 worktree（main + 32 个 Codex feature branch + 1 个 detached checkout），`main` 与 `origin/main` 同为 `5288be7`。除主 worktree 原有未跟踪 `.playwright-cli/`、`output/` 外，只有 `android-native-ci` 有未提交 Gradle/AndroidTest 文件，`android-revoked-permission` 有未提交会员授权 instrumentation 与 `.hprof`；这些文件/目录均保留，未暂存、移动或清理。3 个旧分支仍有 patch-ID 与主线不同的提交：`aws-inventory-status-refresh` 的 grant handoff/stale foreground 改动已由主线 `d718d90`、`b283316`、`5a98828` 的后续实现覆盖；`android-node-auth-status` 仅剩过时进度文档；`fleet-recovery-preview` 的估期文档已被当前 12-01 冲刺与四象限放行清单替代。未发现需要再倒合的独立功能；不对这些旧分支执行删除/归档，避免丢失历史或用户工作。

**worktree 用户改动与主线测试语义复核（2026-11-24，基于 `3174559`）**：逐项只读比较 `android-native-ci` 的 Gradle/AndroidTest 文件与 `android-revoked-permission` 的会员授权 instrumentation。Gradle runner 与 AndroidX test 依赖已在主线；主线 `SecureSessionStoreInstrumentedTest` 有 5 个测试方法，旧 worktree 文件有其中 4 个。旧分支新增的“Activity 重建后拒绝系统 VPN 权限”情形已由主线 `deniedPermissionCallbackDoesNotReauthorizeOrStartMemberService` 覆盖，主线还断言无 `CONNECTED` 广播。没有发现待补的独立行为断言；保留两处原始用户改动和 `.hprof`，不重复移植、不暂存或清理。

近期按“共用契约先锁定、平台实现并行、真实节点串行验收”推进。下列早期 11-24 至 11-25 事件与会话记录均按其日期保留为历史，不表示当前外部会话或环境状态：

1. **Android M8 离线证明阶段（2026-11-22 已完成）**：专用隔离 AVD instrumentation 已验证同 installation ID 稳定复用 P-256 公钥、不同 installation ID 得到不同公钥、challenge 签名可由对应公钥验证、私钥不可导出；完整 instrumentation 6/6 通过。此阶段不证明硬件 backed provenance，也未接入会员隧道。
2. **Windows M8 平台密钥阶段（2026-11-22 hosted 已通过）**：Windows-only CNG P-256 signer 使用直接 `windows-sys 0.61.2` bindings，existing key 每次打开均核对 signing-only/zero-export/P-256；Windows hosted 29 tests 与 MSVC build 均通过（run `44959472000`）。本机无 Cargo/Rust；bridge 仍关闭，不能外推为 sidecar/TUN/会员线路验收。两个平台只共享 `xlb-proof-v1` wire/身份语义，不共享私钥或把一个平台的测试结果外推到另一个平台。
3. **M10 Agent probe 阶段（隔离实现及 PHP→Agent→canary 本机全链通过，真实目标待验收）**：PHP 按 node ID 配置 HTTPS endpoint 与逐节点 CA bundle，使用 client cert/key、关闭 redirect 和随机 challenge/node ID 复核；仅显式选择 `NODE_HEALTH_PROBE_TRANSPORT=http` 才注册 transport。Node Agent 已增加仅在 trusted runtime config 显式选择 `https-canary` 时启用的 executor，固定 `/.well-known/xlb-node-probe/v1`，只连经公网 IP 筛选的 TCP/443，要求 TLS 1.3 并按显式 CA 验证，无代理/redirect，限制 JSON 响应并要求 challenge string 完全一致。Go 全套 race 133、vet/build 通过；PHP→Go mTLS→本地 TLS canary 定向测试 1/1、32 assertions 通过。runtime 配置缺少该项时保持 unavailable；Agent probe listener 仍要求独立 loopback `NODE_AGENT_PROBE_LISTEN`，production mode 仍拒绝启动。所有集成使用本机 loopback fixture，不代表获批真实节点；仍需真实 canary URL/CA 与隔离节点窗口验实际 endpoint、超时、证书失败及健康状态新鲜度。PHP fake、合成 canary 与 `/healthz` 均不能证明真实节点健康或会员稳定性。
4. **M6 会员节点阶段（身份已齐，云端验收前串行）**：两台物理设备足够，不需要第三台。ALT-AN00 与 VOG-AL10 当前安装均已完成沙箱登录和独立 P-256 challenge 绑定；两条旧设备记录保留。11-24 `ops/aws-cli` 只读快照曾确认唯一活动 XLB 栈为既有 `xlb-node-manager-access-usw2`、实验栈均 `DELETE_COMPLETE`，实验标签下无实例、卷、网卡、安全组、地址、Scheduler 或 SSM 托管实例；该快照是历史状态。11-25 再次调用同一包装器时 `xlb-ops` STS 返回 session expired，本轮未查询当前 AWS 资源。AWS 后续状态盘点需用户本人 `aws login` 后再按获批范围只读执行；当前仍缺 server-verified member profile、节点身份/endpoint、mTLS 注入材料与匹配节点回执。材料到位后再做有效会员连接、双机同时/错峰凭证复制拒绝、匹配 node/peer/request/generation 的撤权回执与最终流量单调验证。无节点回执不得宣称断流。
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
| 4 | M6 两台现有会员真机数据面验收 | Android/API/验收记录，云资源另按计划 | 两机同时/错峰复制均拒绝；有效会员连接成功；退款撤权有匹配回执且最终流量单调 | 两台设备登录和独立绑定均已具备，不需要第三台手机；11-25 AWS wrapper 检查确认 `xlb-ops` session expired，当前资源状态未复核。真实会员 profile、节点身份/端点/证书及可核对的节点回执仍缺；用户本人重新 `aws login` 前不运行 AWS 盘点/计划，期间继续 Android/Windows 离线 adapter 与失败关闭测试 |

M11 隔离控制面负载工具已在主线，包含最多 100 个本地请求、并发 1/4、loopback-only、临时 SQLite、无 Cookie 重放及 health preflight。它只形成开发机控制面基线，不得外推到生产容量或 VPN 隧道稳定性。D2 跨端登录方式、D3 智能线路范围、高可用拓扑/SLO 与 E4 资源费用仍需用户决策；可并行的离线工作不因此暂停。任何阶段碰到外部门时，继续执行其他无依赖离线工作。

### 0.3 一周 AI 冲刺目标与整体放行时间分开估计（2026-11-25 更新）

此前“3–6 个月、约 2027-02 至 2027-05”是包含节点、商户、合规、staging 和试运行等待的低置信整体日历估计，并非 AI 编码工期。其“两周内外部材料到位”前提未满足，已撤销为当前上线预测。当前以 2026-12-01 为内部冲刺检查点，覆盖 Android + 网站/API 单服务商受控试点的代码、离线验收和候选审查；受控试点能否按期上线，仍取决于真实节点、商户沙箱、合规、受保护签名 Environment 和 staging 放行门。11-25 只读复核确认 Environment 数量为 0、`xlb-ops` STS 已过期、外部负责人尚未提供；因此没有证据支持受控试点的具体日历上线日期。

| 交付范围 | 当前估计 | 说明 |
| --- | --- | --- |
| Android + Web/API 单服务商受控试点的 AI 可控代码与离线验收 | 目标 1 周，至 2026-12-01；低置信 | 冲刺范围为代码/自动化/离线测试达到可审核候选状态，包含 Android 失败诊断与准入失败关闭、会员授权/API 错误状态回归、跨模块回归、候选制品校验及发布清单。若发现新的安全或数据完整性缺陷，优先修复并如实更新目标。AWS/节点/商户/合规/受保护签名环境等外部等待不计入编码周期；完成冲刺不代表真实线路、真实支付或受控试点已放行 |
| Android + Web/API 单服务商受控试点 | 暂无可靠日历日期 | 需真实 member profile、节点 endpoint/CA/mTLS 与撤权回执，真实商户沙箱/回调契约、合规意见、受保护签名环境、staging/回滚/监控和试运行观察。取得可用材料和责任人日期后，再按实际验收步骤重算 |
| Android、Windows、macOS、iOS 全平台公开发布，并完成多云与放量门 | 至少数月，尚无可承诺区间 | 首发受控试点之后再排期，另需 Windows sidecar/TUN、macOS/iOS 系统扩展与商店/签名验收、第二家 provider conformance、容量模型及 E6/E7 放量退出门 |

当前 M8/M6 关键路径仍是 Android member bridge 与真实会员数据面。两台现有真机 installation 的独立 P-256 绑定已具备，不需要第三台手机；离线开发不依赖手机在线。Android member admission gate 仍关闭，production Node Agent runtime 尚无可用真实 `DataPlaneProbeExecutor`。11-24 历史只读盘点记录实验标签资源无活动项；11-25 经 `ops/aws-cli` 包装器核对时 `xlb-ops` STS 已过期，因此没有复查今日 AWS 资源状态；本轮无云资源写入。GitHub Environment 只读 API 返回总数 0。真实会员 profile、节点 endpoint/CA/mTLS 材料及匹配撤权最终流量回执仍未提供。CI `45446581811`（Android process-death host）和 `45451245263`（Web order cancellation）六个 jobs 均全绿；这些证据不代表生产签名候选、真实会员线路或 M6 放行。剩余离线工作继续按 §0.2 及 PRD 逐阶段推进。

**需要用户/外部协助，但不阻断上述离线工作：**

1. 首发范围：已确认第一阶段为 Android + Web/API 单服务商受控试点；Windows、macOS、iOS、第二家云厂商不作为首发放行门，后续单独排期。E3/E6/E7 仍是后续阶段。
2. 产品决策：确认 D2 跨端登录方式、D3 智能线路是否只对账号级兼容线路自动选路（建议严格设备线路保持逐设备 peer 受控切换）、首发高可用拓扑、SLO/错误预算和 E4 资源费用上限。代码与无费用验证继续进行；涉及资源创建/费用仍需逐项批准。
3. 节点联调材料：由节点/运维侧提供可用于获批验收的 member profile 生成条件、node ID、管理员控制的 endpoint、证书身份映射和受控 mTLS CA/client/server 证书注入方式，以及能核对 node/peer/request/generation 的真实回执。凭据走本机受控安全存储，不经聊天、仓库或普通文档。现有合成 peer 回执不能替代此项。
4. 商业放行材料：真实支付服务商的商户沙箱账号、API/验签与回调契约，以及目标市场对应的合规书面结论；凭据使用安全注入。provider-neutral 支付代码可继续，但不能宣称真实支付已验收。
5. 分发条件：若 Windows/iOS 纳入首发，需要相应代码签名、分发/商店账号和可用验收设备；若不纳入，可在 Android 首发之后推进。

**时间判断更新（2026-11-25）**：AI 可控代码和离线候选以 2026-12-01 为内部冲刺检查点；这不是受控试点上线承诺。受控试点没有可靠日历日期，须等真实会员节点及撤权回执、商户沙箱契约、合规书面意见、受保护签名 Environment 与 staging/回滚门落实后，再按实际验收计划重估。`xlb-ops` STS 本轮已过期，资源状态未复查；用户本人重新 `aws login` 后，才可继续获批窗口前的只读盘点。Environment 数量为 0。全平台公开发布及多云放量仍需数月以上，且涉及 Android/Windows/macOS/iOS 各自系统隧道、签名/分发审核和第二服务商 conformance。

### 0.4 四象限调度与主要工作量（2026-11-25）

四象限的通用定义、适用 Codex、DeepSeek Harness、WorkBuddy 的规则已记录在知识库 `10-全局/规范/AI项目四象限与持续推进.md`。本节维护迅连宝当前排序，按放行影响和真实截止判断紧急度，不按“容易做”排序。当前日历基线是用户希望约一周完成 AI 可控代码冲刺；已过的日历时间不等于 AI 实际投入工时，仓库没有可靠的逐项主动工时记录，因此不把“2天12小时”折算成代码工时或完成率。

| 象限 | 当前事项与证据 | 下一步、依赖与估时口径 |
| --- | --- | --- |
| Q1 重要且紧急 | **最大技术工作量与首发关键路径**是 Android M8/M6 生产形状闭环：服务端签发的 member grant/profile → 设备 proof bridge → Mihomo/TUN → 节点 peer 授权，再覆盖重连、停止、退款撤权和匹配节点回执。已有离线/隔离 AVD 和合成 Agent 证据，生产 gate 仍关闭，不能将其当作会员线路验收。 | 代码按接口分成可测小阶段继续；在真实 profile/endpoint/CA/mTLS 及节点回执契约冻结前，剩余实现和联调工时为低置信、不能给单日估期。节点负责人需提供受控测试材料/窗口；AWS 资源盘点等用户本人重新登录后再做。 |
| Q1 重要且紧急 | **发布阻塞主要是外部放行工作**：GitHub `android-release-candidate` Environment 只读查询为 0 项；真实节点与撤权证据、商户沙箱契约、合规意见、staging/回滚窗口和负责人日期均未齐。 | 外部责任人确认后并行设置环境、提供材料、准备 staging 与书面意见；当前尚无负责人姓名/承诺日期。AWS STS 于 11-25 过期，用户本人重新 `aws login` 前暂停 AWS 查询。此项日历等待不可由 AI 编码压缩。 |
| Q2 重要但不紧急 | Android/API 授权与隧道失败安全诊断、失败关闭竞态、Web/API 沙箱下单退款、M10 健康探测契约和候选制品校验，属于上线前离线质量工作。Android diagnostics patch 在主线等效提交 `5be4a7a` 的 CI run `45316986228` 六个 jobs 全绿。 | 按每项约 1–2 小时拆成定向测试、修复、全模块回归；同一文件/接口串行，Android、支付/对账、发布文档可由独立 worktree 并行，合入时逐阶段 CI。 |
| Q3 紧急但相对次要 | 20 分钟哨兵状态播报、重复状态核对和临时进度汇报。 | 自动化唤醒后检查真实提交/测试/阻塞并执行下一项离线工作；“无代码变化”不是停止理由。用一条同步的模块状态和可验收队列减少重复巡检开销。 |
| Q4 不重要且不紧急 | 本次受控 Android + Web/API 试点之外的全平台扩展、外观细节，以及 Reality/uTLS 等抗审查伪装。 | 移至首发之后；Reality/uTLS 只保留为已知差距，不作为本周冲刺或已解决事项。 |

**本周执行顺序**：先完成 Android/Web/API 离线安全与业务回归、签名候选链路检查和发布材料清单；外部泳道同时补齐节点、商户、合规、Environment 与 staging 的负责人和日期；取得真实节点材料后串行做隔离节点验收及两台现有会员真机复测。若外部材料未到，交付可审核代码候选和明确放行缺项，不把离线通过包装成上线。每个代码包限定接口/文件边界、独立测试和退出证据，已合入主线的诊断阶段不重复开发。

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
| **P0** | 受控试点候选与 staging 发布链不完整 | CI 已覆盖 Web/API/PHP/扩展/Go/ops 与 Android native Debug APK，Android release runtime SBOM、manifest 与 provenance 检查也已进入 CI；Dockerfiles 已存在。受保护签名 Environment、staging 部署/回滚和真实运行 smoke 仍缺 | 开发检查通过仍不能证明可审核候选已部署并可恢复 | §3、§7 |
| **受控试点外** | 无批量部署/编排 | `ops/node_lab.py`、`node_manager.py` 是**单节点测试/管理**脚本，无多节点并发编排、无蓝绿一键任务 | 节点池扩容、批量替换与故障恢复仍依赖人工编排；不应因此阻止受限规模、人工批准的单服务商试点，但正式放量前必须完成 | §4 |
| **规模化发布门** | 压测尚无容量基线 | 已有 80 请求 loopback smoke 与本地 P50/P95/错误率记录；它只量 PHP dev server + SQLite，尚无分级 staging 数据、生产数据库/节点容量模型；公网/云端压测须审批 | 本机 smoke 不能用于预测生产承载量；规模化前须用获批 staging 数据建立容量门，禁止把目标并发当成测量结果 | §5 |
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

离线前置已落地为 `ops/node_fleet_plan.py`：它只校验固定 `us-west-2` 的隔离测试请求并输出最多 5 个节点、并发最多 3 的 plan-only 波次；拒绝未知字段，不导入 `node_manager`，不调用 AWS，也没有 `--apply`。CLI 子进程测试验证 plan-only 输出契约、JSON 损坏和 16 KiB 上限失败时不打印计划。另有 `ops/node_fleet_recovery.py` 为调用方传入的失败节点、release intent 与资源快照生成纯本地 review-only 预览；评估时间取 CLI 本机 UTC，不接受调用方回拨；它拒绝共享/控制面、身份不符、撤回/过期、generation/batch 不匹配及超过 5 分钟的 inventory。该输入不带认证/签名，预览不能证明 AWS 当前事实或授权删除，且没有 apply 能力。批次创建、认证 inventory、幂等执行、真实失败回收、灰度或热备仍未实现；任何云执行需逐项复核资源与费用并取得批准。

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

### 7.1 受控试点与规模化发布门

首发已定为 Android + 网站/API 单服务商受控试点。以下条件是该试点的硬门槛，缺一项都不能放行：

1. **首发合规结论**：律师或合规负责人书面确认目标市场、经营主体、服务范围、跨境数据和支付政策（`vpn-business-operations-roadmap` §0）。
2. **真实会员线路与设备约束**：两台独立 proof-capable 设备完成有效会员连接；复制身份被拒；退款或撤权后有可匹配 node/peer/request/generation 的节点回执、最终流量与重连拒绝证据。保持严格限设备，普通兼容客户端不计入此门。
3. **受保护候选与部署**：受保护签名 Environment、审批人和正式签名/API origin 凭据就绪；从审查过的 commit 产出签名 APK、release runtime SBOM、摘要 manifest 与 provenance；在获批 staging 部署并通过登录、权益和适用的支付沙箱验收。
4. **最小运行保障**：受控环境的 scheduler/queue worker 常驻并有心跳、积压与失败告警；明确试点用户数、节点和流量上限、值守责任人、停止条件、成本上限和应用/配置/路由/数据库恢复方案，完成回滚演练。新增资源或费用仍需单独审批。
5. **支付放行**：若试点开放购买入口，须先完成选定服务商的商户沙箱下单、验签回调、权益发放、退款和对账，并取得目标市场合规结论；本地沙箱不能替代商户沙箱。真实收款还需生产商户凭据和独立审批，不因候选或技术预览自动开放。

批量部署编排、热备池、规模化容量基线、跨区故障切换及更高并发 SLO 属于后续规模化发布门。进入该阶段前，需在获批 staging 取得分层压测数据、完成容量模型、批量操作与回收核对、热备切流和恢复演练；这些事项仍是正式扩大用户规模的前置条件。

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
