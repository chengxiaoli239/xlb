# 迅连宝会员连接流程 PRD（产品口径定稿，待验收实现）

确认：2026-11-18 09:44。范围：自有 Android / Windows / macOS 客户端、会员网站、浏览器扩展，以及 Clash Verge 等兼容客户端。本文区分产品目标、开发期验证和正式上线门槛；不表示当前代码或正式节点已实现。阶段进度见下文。

## 开发进度（2026-11-22）

- **E0 Debug APK 构建清单（2026-11-22，hosted 全绿）**：新增 `ops/build_artifact_manifest.py`，从干净 Git checkout 生成 commit/ref、CI run ID/attempt、制品相对路径、大小和 SHA-256；拒绝已跟踪修改、非忽略的未跟踪文件、越界路径和覆盖已有文件，输出必须位于 Git 忽略目录。Android CI 在 native Debug APK 构建后生成清单并写入该次 workflow summary，不上传 APK、不签名或发布。工具定向 6 项、ops 全套 34 项、Python 编译、workflow YAML 解析与 diff 检查通过；本机未安装 `actionlint`。hosted run `44943107063` 五个 jobs 全部成功，Android job 日志确认实际写出清单。该清单只是来源与摘要元数据，不是可复现构建、SBOM、签名或生产制品放行证明。
- **M9 Android 匿名账号 Keystore 与 API 恢复验收（2026-11-22，隔离 emulator 通过）**：API 35 / arm64 `xlb-m9-recovery` AVD 实测 Android Keystore + SharedPreferences 跨 `SecureSessionStore` 实例恢复、cookie-only 清理保留许可、显式退出保留 secret 但关闭启动恢复、installation ID 轮换清除旧身份。instrumentation 通过显式隔离 API `127.0.0.1:4181` 和独立 SQLite 验证：用户同意注册、重建 store 恢复 session；服务端 session 失效而本地许可仍在时，`currentAccount()` 返回 null，启动恢复取得同 account ID；正式退出不再自动恢复，用户再次同意后幂等恢复同一账户。AndroidX instrumentation 5/5 通过；SQL 核查匿名账户 1 条、关联会员权益 0、会员设备 0。测试在清理任何 app 偏好前验证专用 AVD 名和显式 `disposable_avd=true`，API 流程另要求 `isolated_api=true`；现有 `4175` API 和两台会员真机未触碰。新增 `apps/android/scripts/test-anonymous-recovery-emulator.sh` 自动创建临时 SQLite/配置缓存、只绑定 loopback、拒绝忙端口/既有 reverse、仅对明确 serial 操作并在完成后清理本次资源；完整 harness 已从干净状态再次跑过 5/5，忙 reverse 负测拒绝覆盖。代码提交 `b272e71`/`7d5aa44`/`9af109e`/`d8f1778`，CI runs `44932111255`、`44932197593` 全绿。证据只覆盖隔离 emulator + local/testing，不代表生产账号/支付、真实会员线路或设备限额验收。下一阶段：Windows Tauri/React 账户壳登录与权益状态独立实现，不接 Mihomo/TUN/线路切换，不宣称严格限设备。
- **M9 Android Keystore 匿名恢复 instrumentation 测试（2026-11-22，已提交待 hosted 验证）**：新增 AndroidX/JUnit4 设备测试覆盖 Keystore 加密状态跨 `SecureSessionStore` 实例恢复、只清失效 cookie 后仍保留恢复同意、`clearSession` 后保留 secret 但关闭启动恢复，以及 installation ID 轮换清除旧恢复身份。CI 会构建并编译这些 Android instrumentation tests，但不会在 Ubuntu x86_64 运行，因为当前 Debug APK 仅包含 arm64 ABI。Apple Silicon 本机 `assembleDebug testDebugUnitTest compileDebugAndroidTestJavaWithJavac lintDebug` 通过；代码已合并为 `b272e71`/`7d5aa44`。测试尚未在 emulator 执行；arm64 API 35 镜像仍在通过当前网络下载。此项目前证明测试可编译，不能声称设备 Keystore 实测或跨进程重启通过。
- **E0 Android Mihomo native CI 构建（2026-11-22，hosted 全绿）**：`d952f78` 将 CI Android job 改为安装固定 Android 35 / NDK `29.0.14206865` 与 Go 1.24.0，执行 `assembleDebug`、JVM unit tests、lint，不再跳过 `buildMihomoArm64`。Gradle host tag 按平台与 NDK 实际 clang 文件选择：Linux x86_64 用 `linux-x86_64`；Apple Silicon 优先 `darwin-arm64`，本机 NDK 缺该 binary 时回退既有可运行的 `darwin-x86_64`。Mihomo 固定版本 `v1.19.34`、GOOS/GOARCH 和仅 arm64 ABI 不变。Android Studio JDK 21 + 本机 Android SDK 下完整 `assembleDebug testDebugUnitTest lintDebug`、workflow actionlint 与 diff check 全通过；Debug APK 为忽略的本地构建产物，未提交。GitHub Actions run `44931113306` 五个 jobs 全部通过（含 Linux native build），后续文档提交 run `44931259879` 也全绿。这证明 Debug native build 与代码检查，不证明 Release、Windows 平台包、真实节点或生产可用。
- **M9 Android 匿名账号恢复路径（2026-11-22，已合并）**：Debug 启动在会话无效后，仅当当前安装持有有效匿名恢复密钥且之前明确同意时，调用后端幂等注册/恢复接口；恢复密钥继续由本地 Keystore-backed AES-GCM 状态保存。显式退出保留密钥但清除自动恢复同意标记，须再次通过入口确认后才能恢复；installation ID 轮换会一并清除旧密钥和同意。Android JVM tests、lint、Debug assemble 与 diff 检查通过，提交 `43e1f3b`。没有 AVD/system image 时尚未验真 Keystore/SharedPreferences 跨进程重启或 API 创建/恢复；两台会员真机未触碰，本轮正准备隔离 emulator。下一步是临时数据库+模拟器验证成功创建、强停重启恢复和显式退出不自动登录。
- **M10 健康熔断状态持久化原型（2026-11-22，已合并）**：新增 SQLite 健康状态表与 `DatabaseNodeHealthStateStore`；Coordinator 对完整状态快照使用 CAS，竞争变化后最多重读/重算 10 次，旧观测不能覆盖新状态。测试覆盖 coordinator 重建后连续失败开路、成功恢复、过期状态排除路由与过期 CAS 冲突。Feature 20 项、57 条断言通过，Pint 与 `migrate:fresh --env=testing` 通过，提交 `1f4f4ff`。只提供可测试持久化原型，不代表节点稳定性验收。
- **M10 本地 gated probe worker（2026-11-22，已合并）**：新增一次性 `nodes:probe-health` 命令与 `NodeHealthProbeBatchRunner`，仅在显式启用且注入 driver 时运行；默认关闭、缺少 driver 时 fail closed。结果只写独立健康状态仓库，不修改节点接流状态或 `last_health_check_at`；重复节点去重并通过 CAS 忽略过期结果。定向 Feature 13 项/52 条断言，M10 与路由回归共 28 项/116 条断言通过；Pint 通过。提交 `aaa91e1`，经 `ops/merge-worktree.sh` 合并推送为 `dca6cbf`。目前仍没有真实网络 driver、生产 scheduler 或 `SubscriptionRouteService` 接线，假 driver 不证明节点在线/稳定。
- **M10 持久健康状态路由过滤（2026-11-22，已合并）**：`SubscriptionRouteService` 在 `NODE_HEALTH_PROBE_ROUTE_FILTER_ENABLED` 显式开启时，解析新线路及执行线路版本操作都会拒绝缺失、未来时间、过期或非健康的持久探测状态；开关默认关闭，TTL 默认 30 秒。过滤只控制候选，不自动切 peer、不改节点接流字段、不绕过设备绑定/撤权回执。Feature 69 项/591 条断言、1 个既有跳过通过，Pint 通过；提交 `b46821e`，合入主线 `353f46b`。真实 probe driver 和 scheduler 仍未接入，当前没有此项真实节点健康证据。
- **E0 多模块离线 CI 检查扩展（2026-11-22，hosted run 通过）**：GitHub Actions 新增 Web 账户展示测试、扩展 Node 测试、Node Agent `go test -race`/`go vet`/build、只 mock 云调用的 ops 安全测试，以及 Android JVM unit tests/lint。Android 任务显式跳过依赖 macOS NDK 路径的 `buildMihomoArm64`，因此只证明 Java 层，不证明 Mihomo/JNI 或 APK 构建。本地合并后 Laravel 221 项中 220 项通过、1 项既有条件跳过（1826 条断言），Web 5 项、扩展 12 项、ops 28 项、Go race/vet/build、Android JVM tests/lint 与 actionlint 均通过。前两次 hosted run 暴露测试密钥与已下架 SDK `tools` 包配置问题；第三次发现 Go→PHP CGI 集成子进程漏传 `APP_KEY`，均已修复。commit `1f4f4ff` 对应 GitHub Actions run `44928503271` 的静态构建、Laravel/Web/API/扩展、Go Agent、Android JVM/lint 与 ops jobs 全部成功。CI 不构建 Mihomo/JNI APK，不是生产发布链；未做压测或云资源写入。下一步：在 M11 纳入可重复的制品来源/校验，并继续补 native/多端任务覆盖。
- **Windows 账户壳阶段（2026-11-22，代码已合并）**：新增 `apps/windows` Tauri/React 账户界面，覆盖登录、会话恢复/退出、匿名会员识别、权益/设备摘要和“线路暂不可用”状态；Rust 侧负责 CSRF、cookie 与系统凭据库边界，前端不接触 session token。Release 忽略运行时 `XLB_API_BASE_URL`，仅使用构建时配置，未注入 origin 时回落到不可路由占位地址 `https://api.xlb.invalid/`，没有臆造生产域名。React 5/5、Rust 8/8、格式检查、本机 Cargo/Vite build 通过；安全、TypeScript、通用代码审查没有发现应用级阻塞。阶段提交 `95888bd`，已由 `ops/merge-worktree.sh` 合并为 `f74c270` 并推送。它只是账户壳，不含 Mihomo/TUN/bridge/线路切换或设备限额验收；Windows MSVC 构建及 Windows Credential Manager 运行时仍未验证，需 Windows runner/设备证据。
- **E0 开发依赖安全修复（2026-11-22，本机检查通过）**：安全审查发现共享测试链中的 `tinypool` 两项 critical 和 `happy-dom` 两项 high advisory；确认均由 Vitest/Web 测试依赖引入，生产依赖审计原本已无已知漏洞。将 API/Web/Windows workspace 的 Vitest 更新到 `5.1.1`、Web/Windows 的 happy-dom 更新到 `21.0.4`。安装锁文件、API 13/13、Web 5/5、Windows 5/5、三个 workspace build、`pnpm audit --audit-level high` 和 `pnpm audit --prod --audit-level high` 均通过；未改生产运行依赖。推送后的 hosted CI 结果仍待观察。
- **并行合并脚本去重修复（2026-11-22，本地回归通过）**：`ops/merge-worktree.sh` 在分支含有已以等效 patch 进入 main 的旧提交时，会尝试空 cherry-pick 并中断。脚本现在按 `git cherry` 过滤等效 patch，只合并分支独有提交；新增 `ops/test_merge_worktree.sh` 在临时本地 bare remote 验证“重复 patch 跳过、唯一提交合并并推送”。bash 语法、回归脚本及 diff 检查通过；此工具改进不改变应用行为或任何云资源。
- **项目全景与连续推进状态复核（2026-11-22）**：总体阶段与退出条件见 [上线工程总体规划 §0.1](launch-engineering-master-plan.md)。本轮起点 `main=origin/main=4a35bec`；Windows、依赖安全、合并工具、M10 worker 与路由过滤等阶段已逐项推送，当前主线含路由过滤 `353f46b` 和 E0 manifest `6121f39`。主 worktree 有既存 `.playwright-cli/`、Android `*.hprof` 和 `output/` 未跟踪产物，必须保留且不得提交。M9 Android 匿名恢复已在隔离 API 35 arm64 emulator 完成 5/5 instrumentation；Web/扩展匿名账号识别已合入。M6 两台既有真机的 installation ID 不同且均已绑定独立 P-256 公钥，**不需要第三台实体手机**；但会员真机线路、凭证复制拒绝和匹配的节点撤权回执仍未验收，隔离节点窗口需按批准计划重新核验后使用。M10 持久状态仓库、默认关闭的假驱动 worker 与默认关闭的路由过滤已合入；没有真实协议 probe driver，也没有生产 scheduler。下一批并行推进 E0 manifest 的 hosted 验证、M11 有界 loopback 控制面实测，并设计基于 Node Agent mTLS/隔离节点契约的真实 probe driver；不得把 Agent 存活检查等同于数据面可用或线路稳定。每阶段以测试、进度更新、提交和主线推送闭环；不得将 emulator、假驱动或隔离节点证据外推成生产稳定、会员真机断流或可发布。
- **20 分钟持续推进哨兵（2026-11-22）**：Codex automation「VPN 项目持续推进巡检」本地配置为 `ACTIVE`、`FREQ=MINUTELY;INTERVAL=20`，绑定当前受控线程；prompt 已要求每次基于 PRD/Git 选择未完成工作并实际写代码/测试/文档、提交推送，禁止以“无新提交”或 turn 状态作为停工理由。当前读到的是配置值；本轮未观察到它按 20 分钟触发的运行事件，因此 cadence 仍不记为运行验收通过。自动化负责唤醒，阶段完成与项目推进仍须以实际提交、测试和 PRD 证据确认。

- **客户端内核决策（2026-11-21，已定）**：维持 Mihomo，不切换内核。用户提供的 Clash Verge 设置截图显示用户侧核心为 `alpha-69c1631 Mihomo`，与项目 Mihomo `v1.19.34` 同属 Mihomo 家族，用户已明确要求继续使用 Mihomo。内核由客户端软件决定，不由订阅 URL 决定；订阅只传节点、规则、代理组等配置。截图不能证明 iGuge 服务端/节点侧内核。`V2rayN` 是客户端 GUI，按兼容配置调用可用的 Xray/sing-box 核心，不是协议。选型和证据分层见 [iGuge 内核与节点稳定性研究](igugu-kernel-and-node-stability-research.md)；此项不再作为待评估任务。
- **节点稳定性研究（2026-11-21，研究结论已出）**：当前代码/验收证明限于隔离测试 Agent 和少量指定网络；production Agent 未开放。测试 TTL 会终止隔离实例、健康记录 300 秒过期会让线路节点不可选、AWS STS 过期阻断管理 API，这些影响层次不同，不应混作“生产隧道断线”根因。协议被识别封锁、长时容量不足及运营商质量目前均属待验证假设。证据、优先级和离线/真实节点改进项见上述研究文档；没有宣称节点已稳定或已上线。
- **会员沙箱与两台真机前置（2026-11-21）**：local/testing 已有可自给测试会员、有效年度权益、`paid_test` 种子订单及建单/模拟付款/模拟退款撤权流程；隔离会员会话不再是外部等待项，但这些状态不代表真实支付验收。用户提供的实机为 HONOR ALT-AN00 与 HUAWEI VOG-AL10，installation ID 不同；本轮用含设备 challenge 实现的 Debug APK 在 ALT-AN00 登录并完成自动同步后，SQLite 确认两台各有不同安装身份且均已绑定 P-256 公钥及 `bound_at`。私钥仍由各设备 Android Keystore 本地生成。设备身份前置满足，但本地会员线路无可用隔离节点；尚未用两台真机完成复制拒绝、会员隧道及真实撤权回执验收。
- **M6/AWS 当前状态（2026-11-21）**：`ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 `arn:aws:iam::289069331089:root`。本轮先只读确认此前实验资源无残留，再按批准计划完成单台 `us-west-2 t3.small`、60 分钟隔离复测；M5 并发、M6 合成 peer 同时/错峰复制拒绝、有效独立 peer 连接、匹配撤权回执/最终流量及撤权后拒连均由 live harness 通过。节点栈和实验 IAM 栈已删除，列明残留项已核查无资源。该结果是隔离协议层证据，不是会员真机或生产验收；两台会员真机虽已有各自 P-256 公钥绑定，M6 会员双真机复制拒绝/隧道/撤权回执仍未完成。
- **M5/M6 合成 peer 隔离节点复测（2026-11-21）**：按验收计划先只读确认旧节点/IAM 栈、角色、profile、安全组、Scheduler、实验标签实例/卷均无资源；复核模板和 IAM 标签策略模拟后，创建专用实验 IAM 栈及单台 `us-west-2 t3.small`、TTL 60 分钟节点，安全组无入站且仅 TCP 443 出站。云端 live harness 通过：12 个独立进程争 3 个容量槽位恰好成功 3 个；节点确认容量有空余时，复制身份的同时及错峰请求都返回 HTTP 403；第二个独立合成 peer 正常连接；撤权返回匹配的 `confirmed_test` 回执和非零最终双向流量，撤权后旧身份重连 403。按计划删除节点栈及实验 IAM 栈；EC2 已终止，卷、ENI、安全组、Scheduler、公网地址及 IAM 角色/profile 均已核查无残留。此次证明限定于隔离节点和 harness 自动生成的两个合成 P-256 peer，不是会员购买/退款链路、真机双身份验收、生产节点或生产断流。测试发现 Agent HTTP server idle timeout 为 35 秒而 live harness 原等待 15 秒会误报未释放；已把归零等待扩为 1 分钟后重跑通过。完整证据见 [M5/M6 隔离节点计划](m5-m6-isolated-node-acceptance-plan.md)。
- **支付适配器契约离线阶段（2026-11-21）**：新增 `PaymentProvider` 与规范化商户订单、渠道交易事件、支付 session 和对账分页类型；`SignedSandboxPaymentProvider` 仅驻留进程内，覆盖 HMAC 验签、5 分钟签名时间窗、商户订单/渠道交易号/金额/币种关联、主动查单及事件分页读取。独立事件决策器对重复事件幂等，对超时付款、金额/币种不符及付款前退款送待核验。定向 Unit/Feature 共 17 项、163 条断言通过；完整 Laravel 套件 182 项中 181 项通过、1 项按既有条件跳过，共 1,627 条断言（测试数据库为内存 SQLite）。现有 local/testing 订单、权益发放和退款状态机仍由既有沙箱服务执行；尚未接入 webhook 路由、持久化 provider event ledger、失败后重试发放/补偿流水或任何真实渠道，也没有商户凭据。此阶段只证明可替换契约与测试模型，不代表真实支付/退款链路验收。
- **支付事件状态机内存参考实现（2026-11-21）**：新增 `PaymentEventProcessor`、`PaymentOrderAggregate` 与 `InMemoryPaymentEventStore` 作为 provider-neutral 的可替换参考模型：同一渠道事件只记账一次，成功付款最多发放一次，只有已发放订单可进入退款补偿；付款前退款、超时付款、未知订单、订单/金额不符进入 review，不自动生效。支付 Unit/Feature 定向测试 21 项、180 条断言通过；完整 Laravel 套件 186 项中 185 项通过、1 项既有条件跳过，共 1,643 条断言。该内存 store 不是生产持久化账本，状态机也未接入 HTTP webhook、重试队列或真实节点撤权回执；退款模拟不代表真实资金退款。
- **支付 provider event 持久化账本离线阶段（2026-11-21）**：新增 local/testing 专用 `PersistentPaymentEventLedger` 和 SQLite 表，按 provider/event ID 唯一记录规范化事件事实（不存原始回调、签名或凭据）；重复事件不重复推进，重复键承载冲突事实则 review。付款先进入 `paid_pending_grant` 并以商户订单唯一键写 fulfillment outbox；失败可重试，只有收到 fulfillment 成功结果后账本才记录权益已发放。退款只接受已确认付款，建立唯一 compensation outbox，失败可重试且成功仅补偿一次。定向 Feature 4 项、27 条断言通过；完整 Laravel 套件 190 项中 189 项通过、1 项既有条件跳过，共 1,670 条断言。当前只是可测试的持久化账本和 worker 结果接口，未接 webhook/API/queue，未实际消费 outbox 写入 `entitlements`/退款账本，未接真实渠道；不代表支付或权益/退款链路验收。
- **local/testing 支付回调与权益发放 worker 阶段（2026-11-21）**：新增 sandbox provider session、HMAC-SHA256 回调验签（300 秒时间窗、订单/渠道交易号/金额/币种核对、64 KiB 请求上限），仅 `local/testing` webhook 路由；新增 `payments:process-sandbox-fulfillments` 命令消费唯一 fulfillment outbox，在一个数据库事务内创建 `granted_test` 权益流水、更新 `paid_test` 订单、写审计及幂等订阅凭证。回调重复不重复入账，签名或时间窗错误不入账，worker 失败保留 outbox 并可重试。定向 Feature 8 项、61 条断言通过；完整 Laravel 套件 194 项中 193 项通过、1 项既有条件跳过，共 1,704 条断言。真实 webhook/商户仍未连接；provider refund compensation 尚无执行 worker，也未真实资金退款；local/testing 结果不等于真实支付或退款验收。
- **provider refund compensation 沙箱 worker 阶段（2026-11-21）**：新增 `payments:process-sandbox-refunds` 消费 provider refund outbox，并调用既有 local/testing `SandboxRefundProcessor` 完成该订单的全额模拟退款、测试权益/发放撤销、佣金冲正、审计及需要时的节点撤权排队；结果再幂等写回 provider compensation ledger。定向 Feature 8 项、71 条断言通过；完整 Laravel 套件 194 项中 193 项通过、1 项既有条件跳过，共 1,714 条断言。此测试模型只接受订单总额一致的沙箱退款，不解决部分退款/剩余时长折算业务规则，不执行真实资金退款；节点 outbox 待匹配回执期间仍不得宣称已断流。
- **sandbox event 对账差异报告（2026-11-21）**：新增 `PaymentReconciliationService`，分页读取注入 provider 的 event page，与持久事件事实比较并报告 `matched`、`missing_local`、`missing_provider`、`mismatch`；此步骤为只读报告，不会自动发放权益、退款或改订单状态。支付定向 Feature 测试 10 项、81 条断言通过；完整 Laravel 套件 196 项中 195 项通过、1 项既有条件跳过，共 1,724 条断言。对账覆盖本地假渠道，不是任何真实商户对账结果。
- **M10 节点路由策略离线模拟（2026-11-21）**：新增未接入运行链路的 `NodeRoutingPolicy` 原型：拒绝不健康/过期（TTL 默认 30 秒）/不可用节点与负载达到 70% 的候选；其余候选按配置权重、容量余量、延迟、丢包与 CPU headroom 计算权重，以稳定 affinity key 做 weighted rendezvous 排序。4 项纯单元测试、5 条断言通过；1:3 权重在 4,096 个 affinity key 的模拟分配误差小于 2%。完整 Laravel 套件 200 项中 199 项通过、1 项既有条件跳过，共 1,729 条断言。这是算法模拟，不会改变现有 `SubscriptionRouteService` 结果，也未验证真实多节点容量或线路质量。D3 尚未拍板，严格设备线路仍不得自动切换或放宽绑定。
- **M10 健康熔断/恢复状态机离线模拟（2026-11-21）**：新增纯状态转移 `NodeHealthCircuitBreaker`：默认连续 3 次失败打开熔断，隔离 unhealthy 节点；打开后需连续 3 次成功才恢复，中间失败重置恢复计数；按 probe 时间戳拒绝乱序旧结果。4 项单元测试、14 条断言通过；完整 Laravel 套件 204 项中 203 项通过、1 项既有条件跳过，共 1,743 条断言；Pint/diff 检查通过。模型尚未持久化节点状态、接入 probe worker、定时器或订阅线路解析，不表示真实节点探测、稳定性或故障切换已验收。
- **M10 熔断状态接入候选排序纯逻辑阶段（2026-11-21）**：`NodeRoutingPolicy` 可注入按 node ID 索引的熔断状态；只接受熔断健康且带有有效、未过 TTL 的最近 probe 时间戳的候选，熔断打开、从未探测及探测过期均排除。定向测试覆盖这些分支与非法状态输入。此接口仍未接入持久化、probe worker、`SubscriptionRouteService` 或客户端；空状态映射保留原静态健康字段模拟行为，不代表线上调度或稳定性验收。下一步是离线设计/测试 probe 采集契约与可控假 driver，之后需真实隔离节点测协议探测阈值与误报率。
- **M10 探测契约与假驱动阶段（2026-11-21）**：新增注入式 `NodeHealthProbeDriver`、结果 DTO 和 coordinator；探测成功/失败、观测时间、延迟、丢包指标及非敏感失败代码经契约校验后交给熔断器。定向测试以脚本化假驱动验证连续失败开熔断、连续恢复阈值、中途失败重置恢复计数、迟到结果忽略、观测时间与质量指标传递及非法结果拒绝；定向 15 项、40 条断言通过，完整 Laravel 套件 211 项中 210 项通过、1 项既有条件跳过，共 1,764 条断言，Pint 与 diff 检查通过。没有实现网络探测、持久化或调度器；真实协议探针、15 秒频率、延迟/丢包窗口与误报阈值仍需隔离节点和目标网络测定。
- **M10 熔断探测状态内存仓库阶段（2026-11-21）**：新增 `NodeHealthStateStore` 与 `InMemoryNodeHealthStateStore`，coordinator 按 node ID 读取、保存连续探测状态，忽略乱序旧结果时不覆盖较新状态，允许假驱动按连续调用验证开路与恢复，无需调用方手工串接状态。定向 16 项、43 条断言通过；完整 Laravel 套件 212 项中 211 项通过、1 项既有条件跳过，共 1,767 条断言。该仓库仅驻留进程内，不是数据库持久化、跨 worker 协调或生产探测服务；真实协议探针和调度器仍未实现。
- **M9 登录与会话恢复边界回归（2026-11-21）**：核实既有登录按大小写归一化邮箱且只接受 active 账户；新增 feature 回归覆盖停用账户不能登录、恢复 active 后大写邮箱可登录并由 `/auth/session` 读回同一账号身份。定向 `PlatformApiTest` 11 项、117 条断言通过；完整 Laravel 套件 213 项中 212 项通过、1 项既有条件跳过，共 1,774 条断言；Pint 与 diff 检查通过。该阶段验证现有登录契约，不实现注册、邮箱验证、扫码授权或跨端联调；路线图 D1/D2 的产品决策仍需按其明确范围处理。
- **M9 登录暴力尝试限流回归（2026-11-21）**：新增 feature 回归验证登录路由配置的 5 次/分钟限流：前五次错误密码返回未授权，第六次请求被限流。定向 `PlatformApiTest` 12 项、123 条断言通过；完整 Laravel 套件 214 项中 213 项通过、1 项既有条件跳过，共 1,780 条断言；Pint 与 diff 检查通过。此测试确认当前请求限流边界，不等于已覆盖分布式代理/IP 策略或生产防刷策略。
- **M9 匿名账号注册 API 离线阶段（2026-11-21）**：新增 `users.account_type`（既有用户默认 `full`）、可空唯一 phone、`upgrade_at`，以及按 installation ID 唯一映射的匿名账号表；只保存客户端恢复密钥解码后 32 字节内容的 SHA-256 哈希和同意时间。`POST /api/v1/auth/register-anonymous` 要求显式 consent、UUID installation ID、规范的 43 字符 base64url 恢复密钥并受 5 次/分钟限流；服务端验证编码格式和解码长度，但密钥随机性由客户端负责。同设备同密钥重试恢复同一 active anonymous 会话，密钥错误、installation 已归属会员设备、账号已升级或停用均冲突拒绝。公开用户对象不暴露占位邮箱或恢复密钥；创建匿名身份不会发放权益、登记会员设备或绕开设备限制。定向匿名注册测试 3 项、32 条断言；完整 Laravel 套件 217 项中 216 项通过、1 项既有条件跳过，共 1,812 条断言；Pint 与 diff 检查通过。Android/桌面端恢复密钥生成和安全区保存、用户确认界面、正式账号升级/邮箱或手机验证、扫码授权 PC 与跨端联调尚未实现；该离线 API 不代表 M9 完成或生产账号体系验收。
- **M9 Android 匿名恢复凭据接线阶段（2026-11-21）**：Android 使用 `SecureRandom` 生成规范的 32-byte base64url 密钥，并通过既有 Keystore-backed AES-GCM 状态加密保存；installation ID 轮换清除密钥，普通退出登录保留密钥。`PlatformApi.registerAnonymousAccount(consent)` 要求明确同意参数、调用后端并校验会话/账号类型；匿名账号不再把 JSON null 邮箱显示为字面字符串。Android JVM 单测 23 项通过，`lintDebug`、`assembleDebug` 和 `assembleRelease` 通过。Keystore-backed 持久化行为尚未在实体设备验证，Windows/macOS 安全区、匿名账号升级和跨端恢复仍未实现。
- **M9 Android Debug 匿名账号同意流程（2026-11-21）**：登录页 Debug 构建显示“创建匿名账号”入口，先展示账号绑定当前安装、卸载/清数据恢复风险、无会员权益及未开放升级/跨端迁移等说明，用户主动点击同意后才创建；Release 不显示入口。该 UI/API 尚未在实体设备验收；匿名账号购买/升级、Windows/macOS 对等流程仍未接入。
- **M9 Android Debug 匿名同意 UI 真机复核（2026-11-22）**：VOG-AL10 运行版本 `0.2.15-debug` / `versionCode 17` 的当前工作区 APK，安装包 SHA-256 与本机构建一致；登录页可见“创建匿名账号”，点击后系统对话框完整显示本机绑定、Keystore 保存、无会员权益及卸载/清数据恢复风险说明。实测点“取消”后返回登录页，查询本地 SQLite `anonymous_accounts` 行数仍为 0，没有创建匿名账号或发起注册请求。此次只验 UI 展示与取消路径；未点同意，未验后端注册、Keystore 持久化或重装恢复。系统侧安装曾出现未知来源风险提示，后续观察到 APK 已安装且哈希匹配；不推断由谁确认。
- **M6 双真机身份状态纠正（2026-11-22）**：复核顶部进度与验收计划后发现旧快照仍把 ALT-AN00 记为未绑定。按 2026-11-21 后续 SQLite 实测，两台现均有不同 installation ID、独立 P-256 公钥与 `bound_at`；更新旧段为历史状态。双身份前置已满足，但本地线路不可用，会员真机复制拒绝、隧道和节点撤权回执仍未验收。
- **M9 浏览器扩展匿名账号状态呈现（2026-11-21）**：扩展识别 `account_type=anonymous`，显示“匿名账户”和暂不支持跨设备迁移提示；仍根据服务端权益状态展示已购权益，有效逐设备订阅仍可复制，不因匿名身份误拦已付费账户。Node 扩展测试 12 项通过。验收使用 API mock，尚无浏览器扩展安装态或真实会话联调；账号升级和跨端恢复未实现。
- **M9 Web 会员端匿名账号状态呈现（2026-11-21）**：Web 会话类型接受 nullable email 与 `account_type`；侧栏和账户页明确显示匿名账号/未绑定邮箱，并说明邮箱跨设备恢复与升级入口暂未开放，full 账户继续显示正常邮箱和权益。Web 测试 5 项通过，TypeScript/Vite 构建通过；测试使用 API mock，尚无真实浏览器/API 联调。该阶段没有改变订单、权益、订阅或设备限制策略。
- **当前外部依赖与放行门（2026-11-22 更新）**：ALT-AN00 与 VOG-AL10 均有各自本地生成并绑定的 P-256 身份，故双 proof-capable 身份前置已满足；后续日志中的“ALT 尚未绑定”均为早期快照。当前 Android Debug 匿名同意 UI 已在 VOG-AL10 显示并验证取消不创建账号；尚未同意注册或验证新匿名账号的真机 Keystore 保存/恢复。M6 会员真机复制拒绝、线路/隧道和撤权回执仍依赖可用隔离 member profile；既有隔离节点栈已回收。生产节点专用 mTLS 材料、Windows/macOS 安全区适配与实际目标环境接入、iOS 验收范围仍未完成。真实商户凭据/回调契约未提供，但不阻塞 provider-neutral 支付契约和 local/testing 沙箱验收。严格设备限制保持不变；合成 peer harness 回执不等于真实会员 peer 或生产断流；无匹配节点撤权回执不得宣称断流或精确限设备；不宣称可发布。

> 本节以下 2026-11-20 进度项保留为当时证据；如其中仍写“当前”“唯一阻塞”或与上述结论冲突，应按历史状态理解，不覆盖本节最新核验。

- **M8 Mihomo profile 内核解析联验（2026-11-20）**：把 Agent renderer 输出固定为共享 fixture `apps/node-agent/testdata/mihomo-local-bridge-profile.yaml`；Node Agent 测试要求 renderer YAML 与 fixture 结构一致，Android `core-native` 测试使用仓库锁定的 Mihomo `v1.19.34` 执行 `config.UnmarshalRawConfig` + `config.ParseRawConfig`，验证 GLOBAL mode、loopback bridge proxy、GLOBAL group/rule 且无 listener。Node Agent `go test ./...`、`go test -race ./...`、`go vet ./...`、`go build ./...` 通过；core-native `go test ./...` 与 debugprofile race 测试通过（本机 JNI 头文件路径用于 cgo；出现既有 C 指针类型 warning）。这是配置解析验证，不是 APK 导入、bridge 连接、节点握手、会员线路或真机验收。
- **M8 proof bridge signer 合同离线验收（2026-11-20）**：扩展本地 bridge 集成测试，让设备证书的 TLS 私钥实现仅暴露 `crypto.Signer`，不提供私钥导出方法；in-memory 节点完成 mTLS、peer proof、SOCKS5 TCP 转发与 echo 回读。定向测试通过。它证明 bridge core 可调用 signer 接口，不证明 Android Keystore/Windows CNG/macOS Keychain 适配、硬件-backed 保证、证书续期或生产数据面。
- **M8 订阅轮换 outbox 回归复核（2026-11-20）**：`apps/php-api` 定向 `MemberDeviceSubscriptionTest` 通过 19 项、252 条断言，覆盖待确认时不提供 URL、固定 request ID 超时重试、旧代迟到回执 superseded、旧 URL 失效以及当前代确认后再开放新 URL。本轮未改 Laravel 逻辑；仅核验已有离线状态机。
- **下一步/外部依赖（2026-11-20 历史记录）**：当时记录的 renderer/Mihomo parser 验证及生产缺口保留作历史证据。有效 local/testing 会员会话现可由仓库沙箱自给；当前生产缺口和 M6 设备绑定状态见本节 2026-11-21 更新。
- **M8 离线 Mihomo proof bridge profile renderer（2026-11-20）**：新增 `MihomoBridgeProfileRenderer`，生成 loopback SOCKS5 proof bridge 配置，使用 Mihomo `GLOBAL` selector 和 `MATCH,GLOBAL` 全局路由；没有 DIRECT 回退、节点直连地址、UDP 或设备秘密。该文件仅配置应用层 loopback 代理，不启用 TUN/UDP；renderer 首轮完成时尚未用 Mihomo 内核解析，后续解析联验见本节顶部。artifact 验证会用 YAML 结构化解析并拒绝未知字段、非 loopback 代理、伪造 direct proxy 和错误路由。Go YAML 依赖锁定为 `go.yaml.in/yaml/v3 v3.0.5`。定向及完整 Go 测试、race、vet、build 通过。该 renderer 尚未注册进 node-agent 启动路径，profile 也未由有效会员会话或真实 bridge 生成/导入；这项离线实现不代表生产线路或发布验收。
- **M8 proof bridge 与订阅轮换 outbox 离线审查（2026-11-20）**：bridge core 接收实现 `crypto.Signer` 的设备密钥并核对其公钥与 proof 证书一致；因此可承接未来不可导出的系统密钥，但当前 Android/Windows/macOS 安全区适配器未实现，CLI 私钥文件仅限 isolated-test。Laravel 订阅轮换现有本地流程会递增凭证代数、立即使旧 URL 失效、按固定 outbox ID 重试绑定；只在当前代回执确认后显示新 URL，并标记第三方客户端需重新导入。现有 Feature 测试覆盖超时重试同 ID、旧代迟到回执、权益变化后 superseded/排撤权及旧 URL 失效。以上是代码与测试覆盖盘点，本轮未重跑 Laravel 套件，也未验证生产 Agent/节点回执。
- **下一步/外部依赖（2026-11-20 历史记录）**：当时共享 quota/control-plane 本地实现和生产 Agent unavailable 的状态保留作历史证据。真实会员会话不再等待用户提供，M6 双设备身份条件按 2026-11-21 实测更新判断；生产 runtime/mTLS、平台安全区和 iOS 依赖仍未满足。
- **M7 隔离实验资源回收只读复核（2026-11-20）**：经 `ops/aws-cli` 的 `xlb-ops` 身份只读查询确认账号为 `arn:aws:iam::289069331089:root`。节点栈 `xlb-lab-us-20261119-m5m6` 与实验 IAM 栈 `xlb-lab-access-usw2` 已不存在（两栈在本轮前复核时均返回 CloudFormation `does not exist`）；本轮没有删除或重建。实例 `i-09e469b6e3501b495` 查询结果 `Reservations: []`；`ManagedBy=xlb-node-lab` 标签下 `ec2:volume`、`ec2:network-interface`、`ec2:security-group` 查询结果 `ResourceTagMappingList: []`；`default` 组 `xlb-lab-` 前缀 Scheduler 查询结果 `Schedules: []`；`XlbLabExpiryRole`、`XlbLabSsmRole`、`XlbLabSsmProfile` 分别返回 `NoSuchEntity`。因此本轮核验时列明的实验栈及关联资源均不存在，状态记为**已回收/当前无残留**，不推断由本轮删除，也不代表账单最终结算。全程只读，无云资源写操作、无新增费用。
- **M7 后续离线接线审查（2026-11-20）**：当前 `cmd/node-agent` 仅接受 `isolated-test` 并在启动时校验对应 runtime 配置；可执行程序的数据面使用 `NewDataPlane`，没有接入共享 entitlement 配额 authority。Go core 已有 `NewRecoveredQuotaAuthorityDataPlane`、持久化 reservation/settlement outbox、mTLS quota client 与恢复失败关闭测试；Laravel quota API 及本机 CGI/mTLS 联调也有本地记录。它们尚未构成生产启动链路。继续接线必须有经审核的生产 runtime/节点身份、节点专属 mTLS CA 与客户端凭证交付、实际目标环境配置和会员 peer 生命周期；本轮不虚构这些输入，也没有改动代码或声称生产 quota 接线完成。
- **下一步（2026-11-20 历史记录）**：当时尚待隔离节点和会员会话前置。当前 M6 状态、用户已批准的隔离窗口及本地沙箱/设备身份进展，以本节顶部 2026-11-21 条目为准。
- **M6/AWS 代理固化与资源只读盘点（2026-11-20）**：通过 `ops/aws-cli` 固化 AWS CLI 的代理绕过：清空大小写 HTTP/HTTPS/ALL proxy 环境变量并设 `NO_PROXY=*`；不关闭 TLS 校验、不改 CA。`xlb-ops` STS 返回 `arn:aws:iam::289069331089:root`。只读查询发现节点栈 `xlb-lab-us-20261119-m5m6` 仍为 `CREATE_COMPLETE`，栈中保留实例 ID `i-09e469b6e3501b495` 但 EC2 describe 无返回；按实验标签查无 EBS 卷，按节点安全组查无 ENI。安全组 `sg-0db2cef785cbeeba3` 仍存在；Scheduler `xlb-lab-us-20261119-m5m6-expiry` 仍 ENABLED，计划时间为已过期的 `2026-11-19T03:03:14` 并指向该实例。实验 IAM 栈 `xlb-lab-access-usw2` 仍为 `CREATE_COMPLETE`，`XlbLabSsmRole`、`XlbLabExpiryRole` 和 `XlbLabSsmProfile` 均存在。故实例当前不在 EC2 查询结果中，但 CloudFormation 节点栈、安全组、过期 Scheduler 与实验 IAM 资源仍残留，**不能记作完整释放**。本轮只读、无创建/删除/延期，费用为 0。M6 四项真实云端证据仍未取得；旧 M5 并发结果不替代 M6，也不代表会员链路或真机验收。
- **本轮后续离线阶段（trusted runtime 可执行接入，2026-11-20）**：`cmd/node-agent` 隔离启动现在必须读取严格解码的 `xlb-node-runtime/v1` 配置，并核对环境为 `isolated-test`、配置 node ID 与进程 node ID 一致、renderer 为 `unavailable`；缺配置、生产配置或身份不符会在监听前失败。补充了启动配置单测和 README。Node Agent Go `go test -race ./...`、`go vet ./...`、`go build ./...` 均通过，运维测试 28 项通过，真实 `ops/aws-cli sts` 仍返回目标 root ARN。该阶段只证明隔离 Agent 消费配置，不实现生产 renderer 或平台安全区密钥交付，不生成 profile。
- **本轮离线实现（Go→Laravel quota mTLS 联调）**：新增 opt-in Go 集成测试，经 TLS 1.3 双向证书连接到本机 Go CGI adapter，再执行 PHP CGI 与实际 Laravel `public/index.php`；使用 NodePeerTrafficQuotaTest 的临时 SQLite 和节点证书指纹映射。真实 Go `HTTPQuotaAuthority` 完成 reservation 及 final settlement，Laravel 数据库最终计入 40 bytes、释放未用 reservation；同一 request ID 的重复 reservation 返回原 lease，重复 final settlement 幂等。无客户端证书在 TLS 握手层失败；证书映射到 `node-test-1` 时伪报 `another-node` 被拒。此本机测试适配器不是生产反向代理、云节点或会员线路验收。
- **本轮接口缺陷修复**：端到端请求发现 quota 路由错误排除了已弃用的 `ValidateCsrfToken` 子类，而 Laravel 13 实际 web middleware 是 `PreventRequestForgery`，导致 Agent POST 返回 419。已改为只从两条 mTLS quota 路由排除实际 middleware 类，节点证书认证、节点映射、限流保持开启；本机 mTLS 联调验证成功。
- **本轮离线实现（trusted runtime v1）**：新增 `TrustedNodeRuntimeConfig` 严格解码和校验 `xlb-node-runtime/v1`：provider-neutral node/provider/region、必须启用的 `xlb-proof-v1` 设备证明、仅回环 bridge listener、按 environment 限定 key-source 声明；未知字段、secret 字段、超大/尾随 JSON、外网监听、测试环境 secure-store 冒用及提前声明 renderer ready 均拒绝。隔离 Agent executable 已加载并校验此配置；production mode 仍 unavailable，不能生成真实 profile。
- **本轮验证**：`NodePeerTrafficQuotaTest` 通过 12 项、111 断言，包含 Go mTLS 客户端至 Laravel CGI/API 联调、重复操作、证书轮换、节点停用/健康降级拒绝和 12 进程跨节点 quota 竞争；Go `go test ./...` 与 `go test -race ./...` 均为 65 项通过（4 packages），`go vet ./...`、`go build ./...`、本轮 PHP 文件 Pint 和最终 `git diff --check` 通过。本地集成只证明当前 checkout 的应用/协议交互，不证明生产 TLS 终止、线上数据库、真实节点或会员购买链路。

- **本轮离线实现（共享流量额度）**：Laravel entitlement byte budget 由所有 node/peer 共用，条件原子更新仅在 `used + reserved + grant <= quota` 时发放 reservation；request ID 同参重放幂等、改参冲突。结算按 node/peer/request/generation 绑定累计方向字节，只有匹配 `final=true` 回执释放未用预留，结果未知时继续占额。usage snapshot 与 reservation receipt 共用单调 peer 水位，避免双计。Go 有 provider-neutral `QuotaAuthority`、持久化 reservation intent、跨进程 byte permit、最终 receipt journal 与 reserve/settlement 重试；新增 `DurableQuotaLeaseProvider` 使用 Laravel 兼容 UUID，并由 `NewRecoveredQuotaAuthorityDataPlane` 在建 plane 前重放有界 outbox，恢复未完成则不返回 plane。PHP 12 进程跨 node/peer 请求竞争共享 entitlement；另有 distinct node/peer 共用同一 budget 用例。该证据限于本地 SQLite 与 fake authority，不代表生产数据库。
- **本轮离线实现（quota mTLS ingress）**：Laravel 已提供 `POST /v1/node-quota/reservations` 与 `POST /v1/node-quota/reservations/{request_id}/settlements`，以 TLS 层 `SSL_CLIENT_VERIFY`/leaf certificate 指纹映射到唯一可用、健康、非测试节点；数据库指纹列唯一。controller 从映射节点推导身份和 `confirmed` 状态，不信任 body 中的节点确认状态，并校验路径、body、证书映射 node 一致。reservation 和 settlement response 由 PHP/Go 共用 fixture 锁定 wire 字段；settlement 不回传内部 confirmation status，满足 Go strict decoder。Feature 测试模拟 TLS 层 server variables，未验证实际 TLS 终止代理配置或生产证书部署。Go mTLS client 测试仍是独立的本机临时证书测试，未与部署中的 Laravel/API 建立真实 TLS 集成。
- **本轮离线实现（provision receipt/profile 契约）**：PHP 与 Go 使用同一结构化 `profile_artifact` 字段：node、peer、entitlement、request、peer generation、device ID/key fingerprint/binding generation、format、renderer ID/version、SHA-256、bridge protocol、connectable 与 content。PHP 对 artifact 做身份/格式/摘要/禁止嵌入客户端凭证校验后加密存储；Go 对相同字段和摘要做校验。production `confirmed` 必须带合法 artifact；隔离 test Agent 仅 `confirmed_test` 且不产出可连接 profile。生产 Mihomo renderer 必须从受信任节点 runtime 与逐设备 peer 派生配置：Mihomo YAML 只能指向本机 proof bridge，不得暴露节点直连或设备 secret；自有 App manifest 使用同一 `xlb-proof-v1` bridge 身份/代数契约。生产 renderer 与 bridge 安全区交付尚未实现，故不生成或伪称存在真实 profile。
- **本轮验证与边界**：Laravel quota/receipt/provision 定向测试 25 项、158 条断言通过；其中 `NodePeerTrafficQuotaTest` 的 12 进程并发预留恰好 3 个 byte 成功，跨 node/peer budget 和两类 response fixture 也通过。Go Agent `go test -race ./...` 52 项通过（4 packages），`go vet ./...`、`go build ./...`、Pint 与 `git diff --check` 通过。恢复测试覆盖 reservation/settlement 已由 authority 执行但响应丢失；outbox 未清时构造 quota data plane 失败。验证是本机 SQLite、多进程、fake authority 和测试证书结果，不是目标生产数据库、部署 TLS 入口或云端节点实测。真实业务数据面尚未连接 Laravel quota authority，因此不能宣称生产快照对账窗口已关闭、实际节点流量不超额或已断流。
- **未完成的生产闭环**：本机 Go mTLS client 已通过隔离 CGI TLS adapter 调用实际 Laravel quota routes，且 feature test 覆盖了证书指纹轮换、节点不可用拒绝和身份不匹配；仍未验证任何部署中的 TLS terminator、生产 CA/证书、目标数据库并发或生产 Agent/data plane 启动。`TrustedNodeRuntimeConfig` 已由隔离 Agent 启动时消费，但生产模式仍拒绝启动；真实 Mihomo/native renderer、可信节点运行参数来源、平台安全区 bridge key delivery 仍缺失，不能由 test Agent、sandbox 或管理员线路替代。
- **下一步（2026-11-20 历史状态）**：该日的资源复核和“等待隔离窗口”建议保留为历史记录；2026-11-21 隔离窗口已获用户批准，但每次云端写入前仍要重新只读盘点并审查 plan。当时记录的 ALT-AN00 P-256 绑定缺口已由 2026-11-21 后续设备 challenge/SQLite 实测更新；生产 renderer/mTLS/平台安全区和 iOS 放行仍未完成。
- **M6/AWS 外部状态（最近只读核验：2026-11-20）**：该日核验的两个实验栈及列明资源均不存在/已回收，详见资源回收记录；本轮没有再次查询这些资源，不将历史结果表述为今日复核。2026-11-21 STS 实测成功，用户已批准的隔离窗口执行前仍须重新只读盘点并审查 plan。该状态不表示 M6 通过或账单最终结清。

- **本轮离线实现（M4 凭证与权益交付）**：设备绑定授权在验证一次性设备签名后，将验证所得公钥指纹仅作为内部参数传给 provisioning 服务；服务在返回 `ready` 前重新核对设备仍登记且未申请移除、peer 所绑定的具体 entitlement 仍有效且 peer 未过期、请求指纹与设备/peer 指纹一致、设备绑定代数一致、节点为新鲜健康的非测试节点，且 peer 已有匹配确认回执和加密 profile。符合条件时才返回 peer/node 身份、代数、peer credential 和 profile；其他状态不含凭证，响应为 `no-store, private`。Provisioning 与第三方 subscription-binding worker 在远端调用前后都校验 peer 的具体 entitlement 与到期时间；权益中途变化时迟到回执标记 superseded 并排队撤权。订阅签发和 profile 拉取也限制为 peer 自身权益有效，账户续费到另一条 entitlement 不会复活旧 peer/旧链接。
- **本轮验证与实现边界**：新增负向回归覆盖未确认、错误设备证明、peer 指纹错配、设备代数过期、缺少有效 peer entitlement、peer 过期/撤权、权益在 provisioning/binding 期间变化，以及旧权益 peer 不再签发/返回 profile。完整 Laravel 套件 163 项、162 项通过、1 项按既有条件跳过、1,497 条断言通过；Pint、`git diff --check` 和本轮涉及文件的空白扫描通过。驱动回执由本地测试 mock 提供，不是云节点会员线路。隔离 Go Agent 仍仅返回 `confirmed_test` 且无 profile，provision processor 继续失败关闭，因此没有可向真实会员交付的生产 peer credential/profile；不宣称 M4 生产闭环或发布就绪。
- **剩余 M4 生产差距**：跨节点共享套餐额度已有 Laravel 原子 reservation/settlement 核心和 Go byte-lease client/state machine 的离线实现，但 Laravel 反向 mTLS API 与生产数据面注入未接通，生产节点仍有快照对账窗口。订阅轮换 outbox 有稳定代数、租约重试、旧 URL 立即失效及匹配回执后才开放新 URL 的本地测试；profile renderer 与平台安全区 bridge 包仍未实现。不能用假 profile 或 test Agent 绕过这些缺口。
- **剩余会员/生产验收依赖（历史快照，已更新）**：local/testing 会员会话和购买/退款可使用仓库内沙箱，不需用户另供会话；早期记录曾显示设备 A 的旧登记无 P-256 公钥、设备 B 已绑定。该快照已由后续设备 challenge/SQLite 实测取代：两台现均有独立安装身份和绑定公钥。剩余会员真机复制拒绝、可用隔离线路及撤权回执仍未验收；生产 mTLS、平台安全区适配和 iOS 验收范围仍待完成。
- **本轮安全红线**：严格限制设备数；复制身份必须拒绝；无节点撤权回执不宣称已断流或已精确限设备；不把 sandbox、账号级凭证或管理员线路当作会员线路；不宣称可以发布；CDN 只承载安装包和静态资源，不承载 VPN 数据通道。
- **安全红线**：严格限制设备数；复制身份必须拒绝；无节点撤权回执不宣称已断流或已精确限制设备；不把 sandbox、账号级凭证或管理员线路当作会员线路；不宣称可以发布；CDN 只承载安装包和静态资源，不承载 VPN 数据通道。

- **本轮离线实现**：修正 Laravel Agent HTTP client 对 Go `{"data": ...}` 响应 envelope 的解包，新增 POST/GET 响应契约测试；在 Agent 撤权等待集合中保留 HTTP 连接已关闭但流量尚未结算的活跃流，只有流结束并累计最终字节后才返回撤权回执；拒绝超出 PHP 整数范围的双向流量增量，避免账单水位溢出。第三方订阅页新增“节点确认中/暂不可用”状态；绑定确认前不显示空二维码或复制入口，轮换后旧 URL 立即失效，新代 URL 仅在匹配回执后显示。新增旧代绑定回执与新代轮换竞态测试、绑定 HTTP payload 测试、流量溢出回归测试及连接关闭后最终计量测试。
- **本轮验证**：Laravel 定向测试 30 项、311 条断言通过；Go Agent `go test ./...` 与 `go test -race ./...` 各 25 项通过，`go vet ./...`、`go build ./...` 通过；Web `pnpm run build` 通过；`vendor/bin/pint --dirty --format agent` 执行完成；`git diff --check` 通过。工作区没有提交或推送。
- **M4 历史状态与剩余差距**：Laravel 设备绑定、逐设备凭证、订阅绑定/轮换 outbox、mTLS Agent HTTP client、用量读取及撤权最终计量控制面均已有本地实现与测试；Agent data plane 的 peer/node/device/subscription 代数校验、复制 peer 身份后拒绝、容量原子预占和流量最终回执也有本地隔离测试。当前共享流量额度 reservation 与本机 HTTP mTLS client 已补齐离线核心，生产反向 API、目标数据库并发与真实节点接线仍未验收。生产闭环仍未完成：真实 Mihomo/自有 App profile renderer、bridge 凭证安全交付与生产 mTLS 配置缺失。隔离文件证书不是硬件保护密钥，sandbox profile 与本地 Agent 都不是会员线路或生产验收。
- **M5/M6 实测状态（历史更新，当前前置见本节顶部）**：实验节点上 M5 的 12 进程争 3 名额通过；早期 M6 同时/错峰复制请求的拒绝无法归因身份校验，A 重连失败且未执行撤权/最终流量。2026-11-21 中午快照曾显示设备 B 已绑定 P-256、设备 A 旧记录为空；同日晚些时候完成设备 challenge 并由 SQLite 核实两台安装均各自绑定 P-256 公钥。云端隔离节点上的 harness 证据与真机会员证据仍须分开；新隔离窗口已获批，任何后续云写入仍须按计划重新盘点和审查 plan。
- **AWS 会话阻塞（历史，已解除）**：旧记录中的 `ExpiredToken` 已被用户本人重新登录后解除；2026-11-21 本轮通过 `ops/aws-cli` 实测 STS 成功。旧 TTL/残留判断以 2026-11-20 只读核验为历史记录，不作本轮资源状态声明。
- **下一步（历史进度，以顶部为准）**：provision receipt/profile artifact 单一字段契约和共享流量额度本地原子 reservation/结算模型已推进。Laravel quota API、证书到节点映射及本机 Go↔Laravel mTLS 联调已完成；生产数据面注入仍未实现。生产 profile 必须由真实受信任节点数据生成且凭证受设备安全区保护；M6 外部复测状态见本节顶部，不以账号级凭证或 sandbox 替代真实会员/独立设备验收。
- **安全红线**：自有 App 不扫码；自有与第三方设备额度不放宽；无节点撤权回执不宣称已断流或已精确限设备；生产订阅签发继续关闭；不宣称可以发布；CDN 仅用于静态资源和安装包，不承载 VPN 数据通道。

### 历史进度（2026-11-19，状态以本节顶部为准）

- **M5→M6 本地 harness 校准（2026-11-19）**：隔离 Agent 新增 mTLS `GET /v1/test/status`，只返回活动会话数和容量；harness 等待会话归零，并在复制身份拒绝前验证有空余容量，直接断言节点返回 `403` 而非 SOCKS 通用失败码或 `409 capacity`。状态端点不会返回 peer 标识，仅存在于 `isolated-test` 服务。状态端点测试、`go test ./...`（22 项）、`go test -race ./...`、`go vet ./...` 均通过（本地，非云端）；修改后的 harness 尚未在节点重跑。
- **M5/M6 隔离节点实测更新（2026-11-19 03:06 UTC）**：在获批的 `us-west-2` 隔离节点 `i-09e469b6e3501b495` 上，12 个独立进程争抢 3 个并发名额的 M5 实测通过，未超卖。M6 harness 中设备 A 经桥接取得 HTTPS 200，设备 B 的自有 peer 也曾成功连接；复制 A 身份的同时与错峰请求均观察到拒绝。但 harness 没有证明 M5 并发会话在这些拒绝前已全部释放，因此拒绝可能由节点容量造成，不能算作跨设备身份校验通过。随后 A 重连返回 SOCKS reply `5`，测试在提交撤权前失败；本轮没有可归因于 peer 身份的拒绝证据、撤权回执、最终流量或撤权后拒连的云端证据。该测试是隔离 Agent 协议验收，不是有效会员购买链路或独立真机验收。
- **资源回收状态**：2026-11-20 经 `ops/aws-cli` 只读复核：CloudFormation 节点栈 `xlb-lab-us-20261119-m5m6`、实验 IAM 栈 `xlb-lab-access-usw2` 均不存在；EC2 实例 `i-09e469b6e3501b495` 返回 `Reservations: []`；`ManagedBy=xlb-node-lab` 标签下 EBS 卷、ENI、安全组返回 `ResourceTagMappingList: []`；`default` 组 `xlb-lab-` Scheduler 返回 `Schedules: []`；IAM 角色 `XlbLabExpiryRole`、`XlbLabSsmRole` 和实例配置文件 `XlbLabSsmProfile` 均返回 `NoSuchEntity`。按当前查询范围，两个实验栈及关联资源均**已不存在/已回收**。本轮只读，不声称由本轮删除；AWS 资源查询不等同账单最终结算，未新建、删除或延长资源。
- **下一步**：无需再清理旧实验栈；M6 修改后的 harness 仍需在新获批隔离窗口完成空位下身份拒绝、有效 peer 重连、撤权回执和最终流量验收。会员购买链路、独立设备密钥和 iOS 验收仍需外部条件。生产签发继续关闭，不宣称已精确限制真实用户设备或可以发布。
- **M5/M6 本地数据面与桥接验收（2026-11-19）**：`apps/node-agent` 新增隔离测试用 TLS 1.3/HTTP/2 TCP 数据面，节点凭设备证书叶证书中的 node/peer/device/subscription 代数和注册公钥指纹授权；节点容量按 peer 会话原子占位，撤权先关闭活动连接、等待流量计量结束，再生成含最终累计流量的测试回执。本机 `xlb-bridge` 暴露回环 SOCKS5 并将 TCP 80/443 请求经 mTLS 节点通道转发。12 个连接争 3 槽位、复制 peer 身份到另一设备密钥后同时/错峰拒绝、活动流撤权及最终流量已有本地集成测试。`go test ./...`、`go test -race ./...`、`go vet ./...`、Linux/amd64 Agent 与桥接构建通过。**这仅是隔离测试数据面，不配置 Mihomo/TUN，不是生产节点或会员服务。**
- **执行前状态（历史记录）**：本地套件和 Linux 包构建门已通过；隔离 test CA、管理端证书和部署包已备齐。Agent 包 SHA-256：`76967f3b8a5ff281f335ea5776137fcfdd4671df06649c77a867a199d8b9c46c`（临时包含仅供实验的服务端私钥，不进入仓库）。AWS 前置盘点、模板校验和策略模拟曾通过；其后已创建隔离实验 IAM 栈和单台节点，最新资源回收状态见本节顶部。
- **真实验收口径**：云端 harness 使用隔离合成 peer 测 M5 并发及节点层跨密钥复制拒绝/撤权回执；这些结果只证明隔离测试 Agent 协议，不替代有效会员会话、真实购买/退款控制面或硬件保护的正式客户端密钥。M6 会员购买链路仍须隔离有效会员会话，本轮合成测试 peer 不冒充会员验收。
- **本轮完成：M2c/M2d 可离线 peer 绑定内核**。逐设备订阅记录现在唯一关联一个 `node_access_peer_id`，同时固定 peer generation 与设备绑定 generation；未绑定设备、缺少已确认 peer、peer/设备/订阅代数不一致时均拒绝签发或返回 profile。peer 开通请求向 Agent 传设备 P-256 公钥、指纹和设备绑定代数。
- **Sandbox 节点授权模型**：新增 `NodePeerConnectionProof` 与 `SandboxNodePeerRuntime`。Fake runtime 逐项验证 node/peer/device/subscription 身份、三类 generation、URL token、peer credential、设备 P-256 签名、一次性 challenge、每 peer 单会话和节点容量模型。测试覆盖复制凭证到另一设备同时/错峰使用均拒绝、超过模型容量的竞争授权被拒、错误 node/peer/request/generation 回执拒绝、撤权 pending 立即拒绝新连接、匹配回执后 peer 不可用、重试幂等和最终流量单调合并。该 in-memory fake 是单进程确定性模型，不证明真实节点多进程并发原子预占；真实 Agent 的节点容量原子预占仍属 M2e 外部验收。
- **逐设备 profile 与撤权**：设备 URL 只从绑定 peer 的加密 profile 读取；本地 peer profile 明确为 `sandbox-only`，使用隔离的 TEST-NET 假节点，不引用管理员演示节点或会员线路。撤销 URL 会立刻停止新 profile 下发并返回 `revocation_pending`；没有匹配节点回执前，不宣称既有流量已断或 peer 已精确限设备。收到 sandbox 回执后才允许重新签发新的 peer 凭证。
- **本轮定向验证**：`SandboxNodePeerRuntimeTest`、`HttpNodePeerProvisioningDriverTest`、`MemberDeviceSubscriptionTest` 共 21 项全部通过、246 条断言；开通绑定定向 Feature 测试 1 项通过、8 条断言。PHP Pint 与 `git diff --check` 通过。为验证共享的 profile 服务，在前一轮定向命令中曾额外包含一次 `SubscriptionProfileTest` 回归（其中可选 Mihomo parser 1 项跳过）；最终验收命令已缩到本轮新增/直接受影响测试。未重跑 M2d 全量回执/并发套件、未重做 M2e 盘点、未探测 AWS。未提交、未推送。
- **阶段结论**：M2c 的“逐设备凭证 ↔ peer 身份/代数”控制面与 sandbox 验证已完成；M2c 整体仍未通过。普通 Clash/Mihomo 不会签署本项目设备 challenge，尚无 proof-capable 客户端/本地桥接与可部署 Agent，因此不能宣称已阻止真实客户端复制凭证后错峰连接，不能把 Clash Verge 等标为严格限设备已支持。
- **M2d 结论**：离线状态机与假 runtime 可验收；真实节点并发限制、mTLS Agent、撤权回执、最终流量和实际断流仍未验收。生产签发保持关闭，不宣称已精确限设备或可发布。
- **AWS 只读盘点更新（2026-11-18）**：用户报告 `xlb-ops` root 登录及 EC2/SSM/CloudFormation/IAM/VPC 只读权限均成功，身份为 `arn:aws:iam::289069331089:root`。本轮未重做身份探测；只读查询确认 `xlb-node-manager-access-usw2` 为 `CREATE_COMPLETE`、其中有 `XlbNodeSsmRole` / `XlbNodeSsmProfile` / `XlbNodeCleanupRole`，默认 VPC `vpc-00ded9986e9e37539` 的 us-west-2a 子网可用且经 IGW 出网，本轮未发现 `xlb-node-lab` 活动实例或 SSM 托管实例。当前角色的名称/标签契约与 `node_lab.py` 不同，不能直接复用；不改现有栈。
- **M5/M6 计划**：隔离节点建设与验收计划见 [M5/M6 隔离节点计划](m5-m6-isolated-node-acceptance-plan.md)。资源范围及 `$1` 预算已获用户批准；本地隔离数据面、测试证书和 Linux Agent 包已就绪，接下来按计划创建独立 `xlb-lab-access-usw2` IAM 栈和一台最长 60 分钟的 `t3.small` 节点。计划费用估算约 `$0.03/小时`。`CAPABILITY_NAMED_IAM` 仅用于创建两角色和实例配置文件的实验权限栈；EC2 节点栈不含 IAM 资源。
- **本地 M5 内核（2026-11-19，历史记录）**：新增 `apps/node-agent` provider-neutral Go peer-store 原型。它使用 0600 状态文件、共享 `flock` 和原子替换，支持稳定 request ID 幂等、peer/device/subscription generation 绑定、一次性 P-256 challenge、自有 App peer 与第三方订阅绑定/轮换、每 peer 单会话、节点容量原子预占、撤权回执及累计流量。12 个独立进程同时竞争 3 个槽位的测试通过；无效签名/容量拒绝也会消费一次性 challenge；另覆盖重启幂等、凭证不以明文写入、旧 URL token 轮换失效、回执节点身份、最终流量累计、过期会话与 challenge 限额。后续本地数据面状态见本节首项。详见 [Node Access Agent 说明](../apps/node-agent/README.md)。
- **Laravel / Agent wire 字段对齐（2026-11-19）**：初次 peer 开通请求已补齐 `device_id`、`peer_generation`、`device_generation`，分别绑定已登记设备、peer 操作代数与设备绑定代数；定向 adapter 单测新增对应断言。初次开通不包含第三方订阅字段是设计要求，订阅必须在 peer 激活后另行 bind/rotate。此项只闭合 payload 字段映射；第三方订阅 outbox 和真实节点接入状态见下方。
- **隔离测试 Agent API（2026-11-19）**：新增 `apps/node-agent/cmd/node-agent` 与 HTTP handler，强制 TLS 1.3、可信客户端证书、严格限长 JSON、loopback 绑定及 `NODE_AGENT_MODE=isolated-test`。mTLS API 集成测试覆盖 12 个并发签名授权请求竞争 3 个槽位，准确接受 3 个；这只验证本地 HTTP/peer-store 状态层，不是云节点并发或 VPN 数据面验收。Agent 返回 `confirmed_test`，不生成会员 profile；Laravel 对该应答不能激活会员 peer。
- **M5/M6 云端验收状态（历史，最近补充见本节顶部）**：实验节点上 M5 的 12 进程争 3 名额通过；早期 M6 同时/错峰复制拒绝无法归因身份校验，A 重连失败且未执行撤权/最终流量。12:23 两台真机沙箱登录快照当时显示设备 A 尚未绑定公钥；后续同日设备 challenge/SQLite 实测已证明两台均绑定独立公钥。无论哪一阶段，隔离节点协议测试都不能替代会员真机复制拒绝、真实会员线路或生产验收。
- **外部依赖清单（2026-11-20 历史快照）**：该快照记录当时尚缺 ALT-AN00 的 P-256 challenge 绑定，以及生产 mTLS 注入条件、iOS 验收范围和公网数据面测试来源。有效 local/testing 会员会话无需用户提供；隔离资源范围和 `$1` 预算已获批，但云写入前需重新盘点。ALT-AN00 绑定缺口已于 2026-11-21 后续实测更新，当前外部依赖见本节顶部。
- **下一步（2026-11-20 历史状态）**：当时建议恢复 AWS 身份、核实旧栈并复测 M6；该工作已于 2026-11-21 完成并回收资源。当前仍需有可用隔离会员线路完成两台真机会员链路验收，以及满足生产 mTLS、平台安全区适配和 iOS 验收条件。普通 Clash/Mihomo 不得标记为严格限设备已支持。
- **安全红线**：严格设备限制不放宽；生产订阅签发保持关闭；无有效节点回执不宣称已断流或已精确限制设备；Sandbox、管理员测试配置和保留地址均不是会员线路；不宣称可以正式发布。

### 历史阶段记录

以下 M2a/M2b/M2c/M2d 与横切质量记录保留各自当时的证据；当前阶段结论以上方最新进度为准。

- **M2a：代码路径完成，真机端到端待验收。** Android 登录/恢复会话读取会员权益，Debug 测试线路仅在开发者工具，连接由用户点击。原有本地种子线路是保留地址，没有真实节点握手证据。
- **M2b：设备绑定控制面部分完成，节点授权未完成。** 新增 `POST /api/v1/me/device-bindings/challenge` 和 `/authorize`：Android Keystore P-256 密钥对签署 5 分钟、一次性挑战；服务端校验会员权益、公钥、原设备状态与共用设备额度，事务内占用名额。Android 登录同步改走该链路，不再拉取账号级订阅。当前响应明确为 `node_authorization.status=unavailable`，没有下发节点凭证或推荐线路；因此 M2b 整体尚未验收通过。涉及 `DeviceBindingController.php`、设备绑定迁移、`routes/web.php`、`PlatformApi.java`、`DeviceKeyStore.java`、`DeviceBindingProof.java`、首页设备名额提示及对应单测。
- **M2c：本地逐设备订阅流程部分完成，严格设备身份与生产兼容未完成。** `subscriptions.member_device_id` 唯一绑定一台已登记设备；member API 可幂等创建、轮换、撤销，并在设备移除时立即禁用 URL；会员中心按设备显示复制、二维码和 Clash Verge 导入入口。轮换响应标记需重新导入。签发和 profile 响应仅限 `local/testing` + `test` scope，生产拒绝。URL 仍是 bearer 凭证，复制到另一台设备后无法仅凭普通 Clash 请求辨认来源，因此不满足严格设备绑定；未接通 proof-capable 客户端/桥接，也未验收 Windows/macOS、扩展或手机实机导入。M2c 未通过。
- **M2d：控制面撤权排队部分完成，节点闭环未完成。** `node-access:reconcile-expired` 每分钟检查会员到期、流量耗尽或账户禁用，把仍活跃 peer 幂等排入撤权 outbox；管理员总览提示待回执数量。沙箱退款判断“还有其他权益”时排除尚未生效的续费、流量耗尽的权益和停用账户。离线测试新增最后一个并发槽位预占及重复撤权请求复用稳定 ID/generation；既有纯逻辑回执测试覆盖 node/peer/request/generation 身份和最终流量单调性。已有 HTTPS/mTLS Agent 客户端及沙箱/禁用 drivers，但没有可部署的 node-side Agent、生产证书或隔离节点，真实节点握手、计量、撤权回执和断流未验收。退款后控制面拒绝新授权与旧订阅拉取；收到真实节点回执前不能宣称已断流。M2e 多端发布验收未开始。
- **横切质量项（2026-11-18，已完成本地验收）**：Android 设置页拆为账户、连接偏好、设备、账户操作、Debug 工具分区；增加默认关闭并持久化的“启动时自动连接”，只在下次启动且已有系统 VPN 授权、会员有效、账户线路可用、当前无隧道时尝试，不自动发起首次授权。Debug 工具由 `BuildConfig.DEBUG` 隔离。跨端异常覆盖审计写入 [设计规格](client-app-design-spec.md)，Figma 矩阵 `94:6` 与 Android 设置浅深主题 `97:9` / `97:61` 已记录；其他逐端异常界面画板仍待补。场景缺口会造成各端恢复文案和动作不一致，影响“各端提示与恢复路径一致”上线门。Figma 节点及本地预览不作为真实节点验收证据。
- **横切质量阶段验证（历史，2026-11-18）**：当时 Android 与 Laravel 测试结果如原记录；“未做真机验收/无隔离会员会话”已由 2026-11-21 用户实测更新，不再代表当前状态。两台真机当前仍无可用线路，真实节点/握手仍未验收。

### M2a 历史记录（2026-11-18）

- **本轮文件**：`apps/android/app/src/main/java/com/xlb/vpn/data/PlatformApi.java`、新增 `AccountProfileFetcher.java`，以及 `presentation/MainActivity.java`、`tunnel/XlbVpnService.java`、新增 `TunnelVerifier.java`、`AndroidManifest.xml`；补充对应的账户配置下载与连接验证单测，并更新 `apps/android/README.md`。
- **实现状态**：Debug 版登录/恢复会员会话后，确认本机登记并复用账号级凭证同步配置；线路保存在内存，不扫码、不自动连接。首页显示线路同步、就绪或暂不可用；Debug 扫码/手输只在设置的开发者工具，导入后也要由用户点击连接。`VpnService` 建立 TUN 后仍须经 VPN 网络完成 HTTPS 探测，才显示“已连接”。Release 版不开放账号级凭证路径，也不展示测试导入入口。
- **验证状态（历史，2026-11-18）**：原 Android 构建及单机状态描述保留作历史记录。2026-11-21 两台 Android 真机已通过 local/testing 沙箱登录并识别有效权益，但现无真实/隔离节点线路，且 ALT-AN00 的已存设备仍未绑定公钥；不能把本地登录或连接中心状态表述成真实节点已跑通。
- **下一步**：在隔离环境提供真实且通过健康校验的账户线路，完成会员登录、手动首次授权、出口探测和失败分支的真机证据；之后再进入自有设备绑定授权、第三方逐设备凭证及真实节点撤权回执。未通过正式放行门前，不对外宣称严格限制第三方设备数。

以上 M2a 历史记录只描述该轮状态；本轮后端设备绑定改动见顶部新进度。所有记录都不代表正式发布。

## 一句话原则

**自有 App 用账号登录并自动同步可用线路，不要求扫描或粘贴订阅链接。** 产品目标是自有 App 和第三方客户端均严格限制设备数；会员中心（主入口）与浏览器扩展（分发入口）提供按设备分配的固定兼容订阅链接与二维码，明确标注“在第三方客户端中使用”。浏览器扩展负责账号、购买和订阅分发，不冒充系统 VPN。

“自动同步线路”不等于登录后立即开启 VPN：首次连接由用户点击“连接”并批准系统 VPN 权限；之后可单独提供“启动时自动连接”开关，默认关闭。

## 用户旅程

| 场景 | 自有 App 看到什么 | 用户操作 | 系统动作 |
| --- | --- | --- | --- |
| 未登录 | 登录 / 注册 | 登录 | 建立安全会话，读取账户、套餐、设备额度 |
| 无有效会员 | 套餐介绍、开通入口；连接按钮不可用 | 购买或续费 | 支付回调确认后刷新权益；不靠 App 本地订单状态判定 |
| 会员有效、线路同步中 | “正在同步账户线路”；保留套餐有效期 | 无须扫码 | 登记本机、校验设备名额、获取该设备的节点授权与推荐线路 |
| 会员有效、有健康线路 | “线路已就绪”；显示真实地区/节点及更新状态 | 点击连接，首次批准系统 VPN 权限 | 建立隧道，完成节点握手与网络探测后显示“已连接” |
| 会员有效、暂无线路 | “服务线路暂不可用”；会员权益仍有效 | 重试、帮助 | 不显示假节点或空列表，不引导扫描第三方订阅码作为补救 |
| 设备额度已满 | “设备名额已满”及设备列表 | 移除旧设备后重试 | 自有与第三方设备均计入额度；旧设备撤权并收到节点回执后才释放名额 |
| 会员到期 / 退款 / 封禁 | 明确原因与续费或申诉入口 | 续费或联系客服 | 立即拒绝新连接并撤销既有授权；收到节点回执前不显示“已断流” |

自有 App 的“订阅”页改为“会员”：展示权益、设备和跳转会员中心的兼容客户端入口；正式版任何页面都不出现“扫码导入订阅”。Debug 版仅在开发者工具保留第三方订阅扫码，用于隔离联调，明确标为“测试线路”。

## 第三方客户端路径

1. 用户在会员中心（主入口）或浏览器扩展（分发入口）登录；已有有效会员直接进入设备额度管理，未开通时先购买，支付回调确认后刷新权益。产品目标是为每台登记的第三方设备分配一条独立、固定、可轮换的兼容订阅凭证；续费保留该设备的原链接。
2. 用户选择已登记设备或在额度内新增设备，再选 Clash/Mihomo 格式并复制链接；支持扫码的第三方客户端可扫描同一设备链接的二维码。入口明确标注“在第三方客户端中使用”。
3. Clash Verge 等客户端自行拉取配置、选择节点和连接；迅连宝网站展示最近拉取时间与配置版本，但不能把“拉取成功”说成“VPN 已连接”。
4. 凭证轮换后服务端更新配置，自有 App 自动同步并轻提示“订阅凭证已更新”，不打断当前操作。第三方客户端的自动刷新和订阅地址替换能力必须逐个验收；若链接本身泄露而必须换 URL，不支持自动替换的客户端须重新导入，不能声称该场景无感。旧凭证立即拒绝新的配置拉取，节点撤权须有回执。
5. **目标是自有 App 与第三方客户端都严格限制设备数。** 第三方必须做到每设备独立凭证、节点侧并发控制和撤权回执，并实测同一凭证复制到另一设备时不能绕过名额。仅按凭证限制并发仍可能允许错峰跨设备复用；若第三方客户端不能提供可验证的设备绑定或等效证明，该客户端不得标为“严格限设备已支持”，也不得进入正式上线范围。现有账号级固定链接只能用于开发期验证，不满足上线门槛。

## 状态与文案

套餐权益、线路同步、隧道连接是三种独立状态，不互相替代。只有节点握手和系统 VPN 网络验证通过才显示绿色“已连接”。灰色表示未连接，琥珀色表示同步或连接中，红色表示失败；所有状态同时有文字。

| 状态 | 首页主文案 | 次要操作 |
| --- | --- | --- |
| 有效权益，线路同步中 | 正在同步账户线路 | 刷新状态 |
| 有效权益，线路就绪 | 线路已就绪 | 更换线路 |
| 启动时自动连接（设置项） | 默认关闭 · 登录后只同步线路 | 用户可手动开启；首次仍须系统 VPN 授权 |
| 连接中 | 正在建立 VPN 连接 | 取消 |
| 已连接 | 已连接 · 实际线路名 | 断开连接 |
| 服务端暂无线路 | 服务线路暂不可用 | 重试 / 帮助 |
| 权益失效 | 会员权益已失效 | 开通 / 续费 |
| 设备名额已满 | 设备名额已满 | 管理设备 |
| 凭证轮换成功 | 订阅凭证已更新（轻提示） | 自动刷新配置；第三方若不能自动替换链接则提示重新导入 |
| 退款 / 到期撤权中 | 连接已禁用 · 正在确认断开 | 查看状态 / 帮助；收到节点回执后才显示已断流 |

“年会员”只在服务端返回有效权益时显示，日期明确标为“账户会员有效至”；不因 VPN 未连接而隐藏权益，也不以有效会员代替可用线路。

## 服务端契约与放行门

现有 `/api/v1/me/overview`、设备登记和账号级固定订阅接口可作为开发期基础。**目标契约**仍须设计自有 App 的设备绑定授权与线路引导接口：按登录会话、权益、设备额度和节点健康返回短期设备配置及版本，不把第三方订阅 Token 当作最终 App 身份。客户端在启动、回前台、续费回调后与配置版本变化时重新同步；离线时只使用仍有效的最小缓存。

**开发期第一步**：仅在本地/隔离测试中，自有 App 登录后复用现有账号级凭证自动取得配置，跑通“登录 → 识别权益 → 同步线路 → 用户点击连接”；用户不扫码。该复用不等于设备绑定授权，不能证明第三方严格限设备，也不进入正式发布。设备绑定授权接口列为下一阶段。

节点必须验证设备授权并上报握手、流量和撤权回执。退款、到期、封禁或移除设备时，**立即**拒绝新连接和新授权，发起已有设备撤权；只有收到节点回执并确认活跃连接终止后才显示“已断流”。回执未知时保持禁用并告警。第三方订阅接口也要在每次拉取时重新校验权益，禁止公共 CDN 缓存带 Token 的响应。

| 阶段 | 实现范围 | 对外口径 |
| --- | --- | --- |
| PRD / Figma 目标 | 自有 App 和第三方都严格限设备；设计设备额满、撤权、告警状态 | 明确这是产品目标，不表示已实现 |
| 开发期主流程 | 自有 App 登录后复用现有账号级凭证自动同步，不扫码；只在隔离测试使用 | 只称“设备额度管理”，不宣称已精确限制第三方设备数；不对外上线 |
| 正式上线放行门 | 每设备独立凭证与可验证绑定、节点侧并发控制和撤权回执、并发及错峰跨设备复用均被拒、逐客户端实测全部通过 | 未通过的第三方客户端不得列为支持范围；不得对外承诺严格设备上限 |

正式上线验收至少覆盖：登录自动同步且不扫码；首次 VPN 授权；一键连接、断开、重连；无健康节点；自有与第三方设备额满；套餐续费不换当前设备链接；到期/退款后正在连接的设备实际断流；第三方客户端扫码/粘贴、刷新、轮换旧链接失效，以及复制凭证在另一设备复用被拒。每项要有客户端版本、配置版本、设备、节点和时间的脱敏证据。

## 当前差距与设计决定

- 当前 Android Debug 版将扫码/手输放在设置页的 Debug-only 开发者工具，正式连接路径不依赖订阅扫码；设备绑定授权目前因没有节点授权驱动而显示线路不可用。第三方订阅曾在真机联通过隧道，但不等于迅连宝会员线路已验证。
- 当前后端有设备绑定控制面，以及仅限本地测试的逐设备订阅凭证管理；尚无可验证第三方设备身份的 Clash 接入方式，也没有生产节点并发控制、撤权回执和计量闭环。
- Figma 的跨端矩阵 `94:6` 是缺口与目标恢复动作记录，不是逐端实现稿；客户端实现与真实节点闭环仍按开发进度推进。iOS 后续沿用同一账户流程，系统 VPN 授权与发布条件另行验收。

## 已确认的默认值

1. 登录后只自动同步线路，连接由用户点击；“启动时自动连接”另设开关，默认关闭。
2. 自有 App 与第三方客户端均以严格设备上限为目标；未完成放行门之前不对外承诺。开发期账号级凭证复用只验证登录自动同步。
3. 线路不可用时显示“服务线路暂不可用”及重试/帮助，不展示假节点或空列表；会员权益继续显示有效。
4. 凭证轮换后自动刷新可控客户端配置，轻提示“订阅凭证已更新”，不打断当前操作；第三方自动刷新及 URL 替换能力按客户端验收，不能无条件承诺无感。
5. 第三方订阅链接/二维码只在会员中心（主入口）和浏览器扩展（分发入口）提供；iOS 后续采用相同账户流程，VPN 系统授权与发布另行验收。
