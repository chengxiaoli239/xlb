# 上线工程总体规划：批量部署 / 快速上下线 / 压测负载均衡 / 四端研发 / 多云长期演进

日期：2026-11-27（滚动执行状态复核）
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

### 0.1 当前完成度与工作队列（2026-11-27）

**2026-11-27 M6 remote runner 参数/设备 guard 纳入 CI**：新增 fake-adb 回归确保预期出口不是公网 IPv4、control origin 非 HTTPS 或 node ID 含注入字符时，在联系 ADB 前拒绝；既有 AVD guard 也在 Android job 中执行。两组 guard 本机通过，CI `45886280116` 全绿。此为安全启动自动化证据，不代表真实 Node Agent/TUN 已运行，也不增加 5/12 首发门计数。

**2026-11-27 MySQL provisioning interop CI 失败信息补足**：CI `45881725783` 的 MySQL 8.4 全套 Laravel 首次运行中，Go Node Agent TLS 1.3/mTLS provisioning case 的 outbox 状态偶发为 `retry`；同 run 重跑失败 job 后通过，现象没有复现。测试断言已补充 peer/outbox 状态、脱敏错误码与尝试次数上下文，定向 suite 9/9 与 Pint 通过。该改动改善后续归因，不解决或证明该间歇性失败根因已消失，不影响首发门计数。

**2026-11-27 Node Agent KMS identity 启动诊断收敛（离线）**：AWS KMS 服务端故障只输出安全的 ErrorCode 或错误类型，避免把原始响应消息写入节点日志；identity startup 保留脱敏错误链。`go test -race ./...` 207/5 packages、`go vet`、`go build` 通过。它不证明当前实例具备 KMS 权限或 Agent 已启动；AWS root session 过期后，需用户重新 `aws login` 才能检查实际 `NodeKmsKeyArn` 授权并继续有界复测。

**2026-11-27 M6 remote Android instrumentation runner（离线）**：新增 Android 远端 Node Agent instrumentation runner，强制复用专用 AVD guard，输入 endpoint、expected public egress IPv4 和临时 mTLS 文件，将凭据复制到隔离 app cache 并在退出时清理；脚本语法与缺参拒绝检查通过。该 runner 消除了远端 test class 需要人工手工部署证书/构造 instrumentation extras 的步骤；无 AWS session 时未执行真实节点测试，M6 仍未通过、12 项首发门不变为 5/12。

**2026-11-27 M6 Android→隔离节点实测准备未通过**：当前 `xlb-lab-us-20261127-m6member2` 栈为一台 `t3.small`、单一 `/32` TCP 443 入站和 60 分钟 Scheduler TTL；截止时间为 2026-11-26 18:55:28 UTC。实例运行检查通过且 SSM Online；Android AVD instrumentation 的 Debug/AndroidTest/JVM 构建通过，并已编译基于显式预期公网 IPv4 的 VPN 出口断言，Node Agent test service 因 KMS TLS identity 加载失败而停止，未产生该节点的 provisioning receipt、TUN 流量或撤权回执。18:00 UTC 只读 KMS 查询显示签名 key 为 `Enabled`，覆盖较早的 `PendingDeletion` 状态记录；随后 `xlb-ops` STS 返回 `ExpiredToken`，因此尚未确认 `XlbLabSsmRole` 的当前单 key 权限，也未清理 CloudFormation 节点栈。下次用户登录后先只读检查 IAM 参数和资源状态，随后仅在有效授权窗口内继续；不增加首发放行门，整体仍 5/12，Android/M6 2/3、Web/API+支付 1/2、Node Agent 1/2、发布运维 1/3。

**2026-11-27 M6 隔离会员入口准备**：node-lab 模板默认无入站；只有显式单个全球可路由 IPv4 `/32` 时才开 TCP 443 并以 HAProxy TCP passthrough 转发至 loopback Node Agent，PHP 管理仍走 SSM。ops 安全回归 78/78 与 AWS CloudFormation `validate-template` 通过，本轮无资源写入。当前目标是一次性用该 source-IP 窗口联调 Android/PHP 沙箱和隔离 Agent，再完整回收临时节点；不作为生产网络策略，用户双真机与匹配撤权回执仍未验收，放行门保持 5/12。

**2026-11-26 GitHub 候选环境保护已配置**：已在仓库创建 `android-release-candidate` Environment，required reviewer 为仓库所有者/用户账号 `chengxiaoli239`（ID `5234259`），deployment branch policy 显式只允许 `main`，并关闭管理员绕过（`can_admins_bypass=false`）。`prevent_self_review=false`，目前唯一 reviewer 可批准自己发起的候选，这不是双人审批。`gh` 只查询 environment secret/variable 名称元数据，没有读取 secret value；预期四项 secrets `XLB_ANDROID_RELEASE_KEYSTORE_BASE64`、`XLB_ANDROID_RELEASE_STORE_PASSWORD`、`XLB_ANDROID_RELEASE_KEY_ALIAS`、`XLB_ANDROID_RELEASE_KEY_PASSWORD` 均未配置，variable `XLB_ANDROID_RELEASE_API_BASE_URL` 也未配置。`python3 ops/verify_android_release_environment.py --repo chengxiaoli239/xlb` 实测 `release environment preflight passed`。本阶段只配置人工保护与分支 allowlist，不运行签名候选 workflow、不部署。受保护环境准备子项已完成，但 12 项首发门 #11 仍未通过，正式签名材料、候选 APK、制品审查和 staging/回滚仍缺，整体 5/12、模块发布运维 1/3 不变。

**2026-11-26 AWS 会话恢复后只读盘点（临时 key 创建前基线）**：`ops/aws-cli sts get-caller-identity --profile xlb-ops` 成功，身份为账号 `289069331089` root。us-west-2 限定盘点没有活动 XLB lab/node 栈、`Project=xlb` 活动 EC2、`/xlb` SSM 参数或名称匹配 xlb 的 Secrets Manager 项。仅发现旧测试权限栈 `xlb-node-manager-access-usw2`，含 `XlbNodeCleanupRole`、`XlbNodeSsmRole` 和 `XlbNodeSsmProfile`，均未改动。该区仅有两个 AWS 托管 EBS/Secrets Manager 对称加密 key，没有可用于节点 mTLS 的 XLB P-256 签名 key。没有读取 secret 值、写入或删除云资源，也没有检查全账号账单/其他区域。首发门仍 5/12，模块门仍 Android/M6 2/3、Web/API+支付 1/2、Node Agent 1/2、发布运维 1/3。真实 Node Agent key/certificate/CA/profile 与会员撤权回执仍是硬缺项；创建有费用的 KMS key 需先明确成本授权。

**2026-11-26 成本只读检查（临时 key 创建前）**：Cost Explorer 对 11 月 1–26 日账户级未混合成本返回约 USD 685.75，其中 us-west-2 约 USD 0.07；主要花费发生于其他区域的非 XLB 标识资源，未据此把其他项目费用算给迅连宝。账户没有 AWS Budget。该估算有账单延迟且非最终金额。记录该快照时尚未执行本轮临时 KMS key 测试，之后仅创建一枚用户批准的临时 key 并安排待删除。本项目不修改其他区域资源；本轮单枚临时 KMS key 已获用户费用授权并保留给集中的隔离验收；删除尚未重新安排，测试完毕后再统一设待删除。若要重建或长期保留，仍须另行批准成本上限。

**2026-11-26 Live KMS P-256 signer 验收**：用户明确授权临时 key 费用及随后释放。`us-west-2` 临时 `ECC_NIST_P256/SIGN_VERIFY` key 已通过真实 AWS SDK public-key/sign 调用完成 TLS 1.3 handshake acceptance（PASS）；最初按计划设为 `PendingDeletion`，后根据用户要求集中验收取消了待删除并重新启用同一 key；随后用于 KMS-backed Node Agent live acceptance，测试结束后已设 `PendingDeletion` 至 2026-12-03 16:11 +08，待删除期间持续计费。Key 不会被用于生产，节点侧生产 identity/profile/CA 与会员设备回执仍未满足；首发门保持 5/12。

**2026-11-26 KMS-backed Node Agent M5/M6 live acceptance 完成**：在新建且随后回收的 `xlb-lab-us-20261126-kms` t3.small、30 分钟 TTL 节点上，以 EC2 instance profile 通过同一 P-256 KMS key 提供 TLS 1.3 mTLS 节点身份，执行 `TestLiveNodeProtocolAcceptance` race test PASS（48.298 秒）。12 进程争 3 槽、复制身份并发/错峰 403、独立 peer 可连、`confirmed_test` 撤权回执的双向最终流量非零、旧身份重连拒绝及 active sessions 归零均由测试断言。安全组无入站、仅 TCP 443 出站；删除后 EC2 terminated，EBS/ENI/SG/Scheduler 无残留。为避免重复建栈，`xlb-lab-access-usw2` 无成本 IAM test roles/profile 保留供复用；不接触 `xlb-node-manager-access-usw2` 和业务服务器。临时 KMS key 已设为 7 天待删除，预计 2026-12-03 16:11 +08 完成。该结果仅适用于隔离 synthetic peers，不改变 5/12 放行门数。

**2026-11-26 隔离节点 KMS role 最小授权（live node 前计划快照）**：node-lab IAM 模板支持可选精确 KMS key ARN，只有专用 `XlbLabSsmRole` 获得 `GetPublicKey`/`Sign`，不会修改现有 manager 或生产角色。CloudFormation validate 成功，IAM simulator 显示指定 key allowed、另一 key implicit deny，node-lab 5 项测试通过。本段记录的是节点创建前的 IAM plan 状态；随后 KMS-backed isolated-node live acceptance 与资源回收结果见本节最新记录。

**2026-11-26 Q2 支付对账分页修复**：持久化 provider 每次按 event ID 取最多 500 条，cursor 依原始批次推进，避免窗外旧事件过滤后丢失 cursor，使后续页永远不可见。回归构造 500 条早期事件加一条目标时间窗事件，并通过真实 `PaymentReconciliationService` 断言 matched。ledger Feature 17/17（171 assertions）、sandbox payment Feature 7/7（87 assertions）、完整 Laravel 280 项（279 passed、1 skipped，2,484 assertions）、Pint passed。该修复只覆盖 local/testing 持久化 adapter，不验收真实商户 callback、对账服务或支付放行门。

**2026-11-26 Live KMS signer handshake 夹具（准备阶段快照）**：新增默认跳过、仅显式 `XLB_LIVE_KMS_KEY_ARN`/`XLB_LIVE_KMS_REGION` 启用的 Go 验收，使用临时本地证书和 TLS 1.3 handshake 实测 KMS `crypto.Signer`；不创建或删除资源。Go race 192/5 packages、vet/build 通过。本段记录夹具建立时的待执行状态；后续实际 KMS 握手与 key deletion 状态见紧随其后的更新。

**AWS 重新登录前的历史快照（2026-11-26）**：权威主线 `main=origin/main=09e3298a7790b404b4a0025006926bd6ec0ee87c`，CI `45764929395` success，工作区干净。本机 Node Agent 全量 race tests 192 项/5 packages、`go vet` 与 `go build` 通过。`codex/aws-kms-node-identity` worktree 在 `961406d` 且无 upstream；其 KMS signer、证书文件保护和 trusted runtime 能力已由主线较新实现覆盖，旧 quota/runtime 改动未作为集成基线。`ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 `session expired`，所以 AWS 资源状态未复核，无云查询或写入。12 项首发门仍为 5/12（42%），不是工时或上线概率；Android/M6、Web/API+支付、Node Agent、发布运维分别为 2/3、1/2、1/2、1/3。下一项继续做不依赖云的 Android/Node Agent 离线验收；会话恢复后先只读核查已有 KMS/IAM/节点证书材料，任何有费用的资源操作须先核成本并取得明确授权。

**Node Agent 配额前置配置校验（2026-11-26，离线 Go）**：runtime v1 只允许隔离测试环境，production-shaped v2 则必须携带 quota authority HTTPS endpoint/CA、byte grant、有限 recovery batch 和有界 timeout。测试覆盖缺失/不安全端点、非法 CA、额度与上限越界。定向 runtime tests 49 项通过；Node Agent 全套 race 187 passed/5 packages、vet/build 通过。该阶段只校验未来接线的必需配置，尚未在 Agent executable 构造线上 quota authority 或 journal，也不打开 production mode；首发仍 5/12。

**Node Agent quota data-plane initializer 与 client 身份校验（2026-11-26，离线 Go）**：`HTTPQuotaAuthority` 构造时预验 client cert 有效期、digital-signature、ClientAuth EKU、与 signer 公钥匹配；`NewRuntimeDataPlane` 按环境选择，隔离测试维持 test plane，生产形状 v2 则构造 HTTPS/TLS 1.3 mTLS quota authority，并在返回 plane 前恢复 bounded durable reservation/settlement outboxes。trusted runtime 将单次 reservation grant 限制在 1–64 MiB。正/反配置测试及 client cert 错误测试通过；Go race 190/190（5 packages）、vet/build 通过，Laravel quota feature 12/12、111 assertions 通过。production executable 仍 fail closed，初始化器未作为生产服务启用，不增加 5/12 首发门数；真实节点证书需同时允许 ServerAuth/ClientAuth，控制面身份映射/信任链仍待材料确认与实测。

**Node Agent quota reservation 上限（2026-11-26，离线 Go）**：production-shaped trusted runtime 的单次 grant 限为 64 MiB，拒绝更大的 reservation；该配置上限控制中心额度一次预留量，与 32 KiB 本地 permit 分块相容。Go race 191/191（5 packages）、vet/build 通过，Laravel quota feature 12/12、111 assertions 通过。此项只限制配置，不证明用户额度对账或 production quota transport 已启用。

**Node Agent runtime quota initializer → Laravel CGI mTLS（2026-11-26，本机集成）**：Go/Laravel 临时 CGI 测试现经 `NewRuntimeDataPlane` 构造 production-shaped NodeTLS identity 与 quota authority transport，恢复空 durable outbox 后 reserve/settle 并核对累计 `57 used / 0 reserved`。Go race 192/192、vet/build 与 Laravel quota suite 12/12、112 assertions 通过。该测试使用临时 SQLite/证书和本机 CGI，不验证 production KMS/IAM、部署 TLS terminator、目标数据库或会员隧道；production gate 保持关闭。

**Node Agent trusted runtime config 文件保护（2026-11-26，离线 Go）**：文件 loader 现在使用 `Lstat`、open 后 `fstat` 与 inode equality 检查，拒绝链接、非普通文件、组/其他用户可写权限及替换竞态。命令入口定向 tests 26/26 通过；阶段总测试和 CI 尚待本次提交后确认。该校验不替代生产运行身份/签名配置交付。

**Laravel → Node Agent mTLS credential file checks（2026-11-26，`393d51a`）**：PHP control-plane HTTP client 现拒绝 symlink、非普通/空/>1MiB credentials，private key 必须 owner-only，certificate/CA 禁止 group/other write，且 open 后 fstat 的设备与 inode 必须与 lstat 匹配。定向 unit tests 5/5、Node provisioning/client tests 14/14（146 assertions）、Pint passed；CI `45763371527` 全部成功。这是本机文件配置门槛，不是 production secret mount/deployment evidence。

**Node Agent KMS 区域绑定（2026-11-26，代码 `ab671b3`、回归 `2a861fd`）**：isolated-test 的 AWS KMS signer ARN 必须匹配 runtime node Region，SDK Region 由 trusted node 配置提供而不采信 `AWS_REGION`；production-shaped v2 同样把 node Region 传入身份选择结果。新增 AWS SDK 层测试，确认环境 Region 与显式 trusted Region 冲突时，client 采用 trusted Region。定向 command tests 25/25、新 Region 回归 1/1、全套 race 179/179（5 packages）、vet/build 通过；CI `45754890724` 对 `2a861fd710857b251bf98533bfe77784baaaff3d` 全部成功。此项没有访问 AWS 或打开 production executable gate，不增加 5/12 门数。下一项仍为离线 production startup/mTLS 与 PHP provisioning 接线审查。

**2026-11-26 后续检查点**：M5/M6 live harness 已补撤权前后累计流量单调性与最终快照/回执相等断言，并在 `xlb-lab-us-20261126-m5mon` 隔离节点重新运行通过。专用节点/IAM 栈随后回收，资源核验无残留。此轮在主线复跑 Node Agent `go test -race ./...` 178 passed/5 packages、`go vet ./...` 与 `go build ./...` 通过。放行门仍为 5/12（42%）；下一步继续离线推进生产 Node Agent runtime/mTLS 注入契约与 PHP provisioning 接线，真实 profile 和节点身份/CA 材料到位后再做会员真机联验；合成 peer 证据不能外推到真实会员线路。

**Q2 运维诊断增量（2026-11-26）**：设备授权 API 的服务端 `X-Request-Id` 已持久关联到 provisioning outbox；异步 worker 带该 ID 与稳定 `node_operation_id`/peer ID 写安全日志并清理上下文，重复请求仍只复用原 operation。Laravel 全套 275 passed、1 skipped、2,457 assertions，Pint 通过。该改进不改变首发放行门计数，也不代替 production runtime、节点回执或 staging 验收。

**2026-11-26 quota race 隔离修正与 hosted CI**：`142a639` 移除 race test 内的 CREATE/DROP DATABASE，只接显式 `XLB_QUOTA_DB_RACE_URL` 指向的 loopback `xlb_quota_race_*` DB；CI 在各自 disposable MySQL/PostgreSQL service 中预建 run-scoped database，主 `xlb_test` 保持给常规测试。PRD 记录本地 SQLite 预期跳过和完整断言。随后 Android AVD startup-revocation instrumentation 修正提交 `89c6731`。CI `45832866755` 全部 jobs 成功，包含 MySQL 8.4、PostgreSQL 16 全 Laravel database compatibility、常规 Tests、Android native/lint、Node Agent、ops、安全构建及 SBOM provenance。前一轮 `45830759202` 常规 PHP job 曾单次出现 async provisioning outbox `retry`/`confirmed` 时序断言失败，local targeted/full-file reruns passed，本次全量主线 CI 未复现。此处不声称生产数据库风险为零或配置了生产环境。

**M6 沙箱 grant→Android system TUN→隔离 Agent 回执离线复验**：专用 `xlb-m9-recovery` AVD 完整 harness `test-anonymous-recovery-emulator.sh` 退出码 0，覆盖 Keystore/native proof bridge、server-authorized sandbox grant/profile、TCP echo 经 system TUN 与合成 Agent、撤权 `confirmed_test` 回执；启动期间 revoke 的检查在 STOP 完成后核对 probe 计数不再增长。Android JVM、lint、Debug/AndroidTest build 与 31 项主 instrumentation 执行完成，VPN-consent 预存状态条件用例跳过。结果只补强离线 Android 集成证据，不表示实体会员双机、生产节点/真实 profile、真实会员撤权或上线门通过；总门保持 5/12（42%）。

**Node Agent mTLS 离线接线增量（2026-11-26）**：已接入 AWS SDK v2 KMS public-key/sign API adapter 与只含 public cert/CA 的 PEM provider，并允许 isolated-test 启动器通过显式 `NODE_AGENT_TLS_IDENTITY_SOURCE=aws-kms` 使用默认 credential chain 和 node-scoped key ARN；trusted runtime v2 要求该 key ARN 与 node Region 一致并支持 standard/Multi-Region key ARN。source resolver 单测确认 production-shape config 使用 trusted key ARN 并拒绝环境覆盖，但 production executable gate 仍关闭。管理/数据/探针 server 共用 opaque signer identity。Go race 176 passed/5 packages、vet/build 通过，fake KMS API 与本地 TLS 1.3/HTTP2 回归通过。生产 mode 仍在加载配置或访问 AWS 前拒绝启动；没有实际 KMS key、IAM policy、签发/部署的节点证书，也没有调用 AWS 资源 API 或产生资源费用。上线门仍为 5/12（42%），这些离线/fake KMS 结果不计 production mTLS 放行证据。

**Node Agent production-shaped runtime v2 trusted listener/certificate inputs（2026-11-26，offline Go）**：v2 配置现要求独立 loopback management/data-plane listener、不同端口、受保护 runtime 文件中的绝对证书链与 client-CA 路径；解析器拒绝 wildcard/public bind 以及 listener、certificate、CA 和 private-key 的环境变量覆盖。v1 isolated-test 保留原 loopback defaults、loopback-only 边界和文件凭据输入。当前 production `run()` gate 仍在配置读取和 AWS 调用之前拒绝启动；外部节点暴露/TLS passthrough 与防火墙策略尚未实现或定稿。本阶段只闭合 trusted input contract，没有开启 production 服务、生成真实 profile 或接触 AWS。Go race/vet/build 及 schema/override 回归通过，Node Agent/首发门仍为 1/2 与 5/12。

**Node Agent runtime server assembly seam（2026-11-26，offline Go）**：将 validated runtime、peer store、opaque TLS identity 构造成 management/data-plane `http.Server` 对象；v1/v2 均重新验证 listener 来源，production shape 只能精确使用 trusted v2 loopback config。该 seam 不调用 `Listen`，fresh-store fixture 以临时 TLS 证书验证 server/TLS client-auth 组合，无 KMS 或 AWS 调用；若已有待恢复 quota outbox，启动恢复本身仍会连接可信 quota authority。production `run()` gate 未变，真实 listener 暴露拓扑和节点验收仍待定；总放行门 5/12、Node Agent 模块 1/2 不变。

**CI / AWS 检查点（2026-11-26）**：Node Agent AWS SDK 依赖缺少 Android 本地替换模块 sums 已补入，hosted CI `45749846197` 全绿（Android native build、PHP/MySQL/PostgreSQL、Node Agent 与 provenance 均通过）。本机完整 Gradle 因 Temurin 26.0.1 与当前 Kotlin Gradle compiler 不兼容未通过；CI JDK 21 构建已通过。随后 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 session expired，因此真实 KMS/IAM 只读盘点未完成，无云资源写入。下一轮需本人重新 `aws login` 后先只读盘点现有 KMS key 与 IAM role；无现成获批 key 时，KMS key 创建与持续费用必须先明确核定。

**后续 AWS/KMS 只读复核（2026-11-26）**：随后登录已恢复，`ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 root principal。KMS key `dd29fe54-38e5-4094-8dde-0c1a1cd8c872` 当前为 `PendingDeletion`，预计 2026-12-03 16:11 +08 删除；隔离节点栈已不存在且没有运行中的 `ManagedBy=xlb-node-lab` 实例。为避免重复创建，`xlb-lab-access-usw2` IAM 栈保留，其中 KMS policy 只允许对显式单一 key ARN 执行 `kms:GetPublicKey` 和 `kms:Sign`；业务服务器和 `xlb-node-manager-access-usw2` 未改动。Cost Explorer 对 2026-11-01 至 2026-11-27（结束日期不含）报告 AWS KMS `us-west-2` 月度区域/服务汇总约 USD 0.000048；该值有延迟，不是单 key 计费明细或最终账单。用户确认必要的多日隔离测试资源费用可以接受，要求集中安排资源阶段、优先复用并避免无必要的重复建删；后续如需 KMS live 测试，先核查现有 key 的可复用状态及等待删除期限，再决定是否恢复使用或申请新资源。

| 工作流 / 里程碑 | 当前有证据的完成部分 | 尚未完成与依赖 |
| --- | --- | --- |
| M1–M2 产品与盘点 | PRD、边界、账号/节点/支付现状盘点已固化 | 随新实现继续维护，不代表发布审查完成 |
| M3–M4 会员控制面 | Android Debug 登录、读取权益、设备 challenge 绑定；Laravel 设备、订阅和撤权 outbox 有本地测试；订阅 profile 成功及 token 轮换/撤权后 404 均要求 `Cache-Control: no-store, private`，API Feature regression 已补齐 | member profile/真实节点授权、生产凭证和真实数据面尚未接通；cache header tests 不代替 CDN 配置审查或真实节点撤权回执 |
| M5 / M7 隔离协议与回收 | 本地 Go race/vet/build；2026-11-26 在新建且随后回收的隔离节点上重跑 12 进程争 3 槽、不同 P-256 peer 复制拒绝、独立 peer 连通、`confirmed_test` 撤权及非零最终双向流量 | 只证明指定隔离 Agent 与合成身份的协议行为；不能外推生产数据库、会员客户端、最终流量历史单调性或长期容量 |
| M6 双身份与会员线路 | 两台现有 Android installation 已完成沙箱登录和 P-256 challenge 绑定；isolated live harness 证明合成独立 key 的 simultaneous/staggered copy denial 与撤权后重连拒绝；两机足够，无需第三台 | 真实会员设备连接与复制拒绝、真实 member profile 隧道、匹配会员节点撤权回执仍未完成。2026-11-26 隔离测试 IAM/node 栈已删除，EC2 terminated，卷/ENI/安全组/Scheduler/SSM/IAM 残留核查为空；没有生产资源写入，账单未最终结算。真实 member profile、控制面 provisioning、节点身份/endpoint 和生产用途 mTLS 材料仍是 M6 硬依赖 |
| 支付适配器与本地沙箱账本 | provider-neutral 契约、签名/时间窗验签、重复及乱序决策、SQLite 事件账本、fulfillment/refund outbox 与 local/testing webhook/worker 已贯通；订单、权益、退款只在沙箱状态机处理。补偿失败保留权益并幂等重试已有回归；本轮新增 local/testing 每分钟处理两个 sandbox outbox 的调度注册，production 不注册；调度定向 3/3；对账分页回归 17/17（171 assertions）；完整 Laravel 280 项（279 passed、1 skipped，2,484 assertions） | 未接任何真实商户/真实收单，不发生真实扣款或退款；真实商户 sandbox、回调契约、凭据及合规意见仍未到位。调度注册不证明 staging scheduler 常驻；离线代码不能代替真实支付/退款验收 |
| M8 proof bridge | Mihomo renderer/解析 fixture、Go `crypto.Signer` bridge 合同和隔离数据面有测试；Android core-native proof bridge Manager→Node Agent PeerStore/DataPlane 的 TLS 1.3/HTTP2+loopback SOCKS 集成 race test 已纳入 CI；专用 AVD 全套 17 项实测 Android Keystore→JNI→Go bridge→Agent peer 授权、不同 installation 公钥复制拒绝、撤权 `confirmed_test` 与非零最终流量及撤权后重连拒绝；Debug-only isolated service action 在 release gate 关闭时验证 Android `VpnService.Builder`、真实 TUN、Mihomo→proof bridge→Node Agent TCP echo、合成撤权回执、活动流 EOF 和后续流拒绝，且不发布 `CONNECTED`；新增 AVD 联验由 fake member API 实际验证 Android Keystore challenge 签名、`PlatformApi` 生成身份绑定 grant，再经同一隔离数据面执行 echo 和 `confirmed_test` 撤权；新增 attempt-bound member tunnel health monitor，连续两次 VPN route probe 失败后会清理 session/TUN 并发布失败状态；bridge close 现先关闭 loopback SOCKS 流、等待处理器退出，再经当前 mTLS/HTTP2 会话调用 disconnect 并关闭 transport，Go race 集成断言 active peer session 归零；Windows CNG signer、server-authorized capability 与默认关闭 bridge gate 有 hosted Rust/MSVC 证据；`AppState` 现持有 `BridgeSupervisor`，restore/login/logout 前执行 stop→force-stop，均失败时 fail closed 阻止会话变更，fake-process 回归由 Windows run `45532748910` native tests 49/49、UI 6/6、MSVC build 验收；PHP 校验/加密保存每节点 endpoint/CA 并向 Android grant 返回完整 descriptor/metadata；Node Agent v1 的显式 `isolated-test` runtime config 可注入 Mihomo bridge renderer并生成 `confirmed_test` profile；2026-11-25 新增 v2 production-shaped Mihomo renderer 配置选择及 `NodeTLSIdentitySource` crypto.Signer 注入合同，支持本地 `confirmed` artifact 和 TLS 1.3 mTLS signer 测试，`NodeTLSIdentitySource` 可注入 opaque `crypto.Signer`，本地 TLS 1.3 mTLS regression 通过；`go test -race ./...` 154 passed、vet/build 通过 | 隔离 TUN/revoke 和 API grant 联验只用 fake API、本机合成 Node Agent fixture，不访问生产会员 API、云节点或公网目标；Node Agent v2 和 signer source 仍未接入生产 executable，具体 secure-store/KMS backend 与 mTLS runtime injection 未实现。普通 `MemberTunnelSession` lifecycle instrumentation 中 `establishTun`/`verifyTunnel` 仍为计数 stub。运行期 health monitor 是 HTTP 204 route probe，不是 peer generation/节点撤权事件订阅，只能在隧道数据路径不可达时失败关闭。Android Debug fixture 不改变 release gate，`MEMBER_PROOF_BRIDGE_INTEGRATED=false` 保持不变。Windows supervisor 当前由 fake adapter/process 测试，`PROOF_BRIDGE_ENABLED=false`；没有 Windows production adapter、bridge 启动/打包命令、TUN 或节点数据面。Node Agent `cmd/node-agent` production runtime 仍拒绝启动；v2 renderer test不证明 secure-store adapter、生产 mTLS 注入、真实 member profile 或生产 Agent deployment；真实节点 profile/endpoint/CA/mTLS、生产会员授权、双真机复制拒绝及匹配撤权回执未完成。以上隔离结果不证明生产连接或撤权断流 |
| M9 账号统一 | 匿名注册 API（明确同意、恢复密钥哈希、幂等与限流）；Android Debug 同意入口、匿名账号幂等恢复及显式退出后关闭自动恢复；恢复秘密与 session Cookie 现有 SharedPreferences instrumentation 验证只持久化 Android Keystore 加密的 `encrypted_state`、原始/Base64 解码均无明文；篡改 AES-GCM 加密状态后 Cookie/同意/安装身份/恢复秘密均失败关闭并重建本地身份，专用 AVD 完整 harness 通过；Android/Web 登录表单不预填种子账号凭据；Web/扩展可辨识匿名账号并保留权益状态；AndroidX Keystore 状态与 API instrumentation 已在隔离 API 35 arm64 emulator 上通过；Windows Tauri/React 账户壳已合入 `f74c270`，账户 API origin 与 Credential Manager hosted run `44945777899` 全绿；Android 授权状态快照保留已本机 JVM 验收；Windows CNG challenge/authorize 合入 `ada4de2`，Windows Rust 35/35、Credential Manager smoke、MSVC build 与主 CI run `44977351487` / `44977351461` 全绿 | 邮箱/手机升级、跨端登录和 PC 扫码授权仍未实现；Windows 新 enrollment 只展示授权状态，bridge/TUN 仍未接线；Android/Windows 均未验收真实会员线路、撤权断流或严格设备复用拒绝；不得清除现有会员设备数据 |
| M10 智能路由 | 加权候选、熔断、SQLite CAS 健康仓库、默认关闭的健康命令（按未探测/最久未探测节点优先，受限批次可轮转）/条件 15 秒 scheduler/route filter、PHP opt-in HTTPS/mTLS transport 与 Go opt-in loopback TLS 1.3 listener 均有离线测试；新增 `isolated-test` runtime-config opt-in HTTPS canary executor，固定 HTTPS/443 target、显式 CA、无代理/redirect、响应有界且 challenge 字符串精确匹配；Go race tests 覆盖 mTLS probe listener→HTTPS canary，PHP transport 另有全链本机集成覆盖 PHP→Go Agent mTLS→本地 HTTPS canary；M10 profile 健康过滤、route version 发布/回滚、合成熔断恢复与不健康 canary 回退均有 Feature 测试 | executor 默认关闭，生产 runtime 不支持且保留 `503 probe_unavailable`；没有获批真实 canary URL/CA 或隔离节点运行，因此尚无真实健康观测，probe/route filter/scheduler 继续默认关闭，不能称稳定性/切换验收。后续需获批 canary 与节点身份后实测超时、证书失败、状态新鲜度及恢复；严格 peer profile 不变，D3 范围仍待决且不能绕过 peer revoke/provision/receipt |
| M11 与 E0 工程基础 | GitHub Actions 覆盖 Web/API、PHP/API/Web/扩展测试、Go race/vet/build、mock-only ops 安全测试及 Android pinned NDK/Go 下 Debug APK/JVM/lint；主线 `ccb3f2e` 的 CI `45586652685` 全绿；候选来源 commit 校验、Environment 只读预检和候选下载后审核流程均已集成 | 本机 M11 80 请求/并发1与4结果只是 PHP dev server + 临时 SQLite smoke，不是生产容量门。GitHub Environment 数量仍为 0，正式签名候选仍无法运行。SBOM provenance 不等于 APK/Release 签名；生产签名制品/回滚、staging、静态资源发布、监控告警与成本预算仍缺 |

### 0.1.1 受控首发进度量尺（2026-11-25）

首发范围按已确认的 **Android + 网站/API 单服务商受控试点**计算。总进度只按下列 12 个放行交付项计数，不折算为 AI 工时、日历进度或“已接近生产可用”。每项权重相同，只要退出条件未有对应证据就不计完成；范围变化时先修改清单与分母。

| # | 首发交付门 | 状态 | 证据/缺口 |
| --- | --- | --- | --- |
| 1 | 首发范围与严格设备/回执/支付边界确认 | 已完成 | Android + 网站/API；不开放通用客户端严格限设备承诺，不以合成回执宣称真实断流 |
| 2 | local/testing 网站/API 会员、权益、购买、退款链路 | 已完成 | Laravel 与 Web 测试及本地浏览器沙箱联验；不是真实支付 |
| 3 | 两台 Android 当前安装的独立身份与 P-256 绑定 | 已完成 | ALT-AN00、VOG-AL10 沙箱身份记录；无需第三台手机 |
| 4 | Android Keystore→proof bridge→Mihomo→系统 TUN 隔离链 | 已完成 | 专用 AVD、fake member API、合成 Agent；不外推真实节点 |
| 5 | 首发代码矩阵、制品来源校验与候选审核准备 | 已完成 | 主线 `ccb3f2e` CI `45586652685` 全绿；候选 commit 和 provenance 检查已有自动化/审核模板 |
| 6 | 生产 member profile 与可运行 Node Agent/mTLS 接线 | 未完成 | 生产 Agent 仍拒绝启动；真实 endpoint/CA/身份注入材料缺失 |
| 7 | 两台真机真实会员连接与复制身份拒绝 | 未完成 | 需要真实会员授权与隔离节点；本地安装身份不等于在线数据面验收 |
| 8 | 匹配真实节点的撤权回执和最终流量单调证据 | 未完成 | 需要 `node/peer/request/generation` 匹配回执；不得以 `confirmed_test` 替代 |
| 9 | 真实商户 sandbox 回调与对账 | 未完成 | 商户契约、沙箱凭据和支付负责人未提供 |
| 10 | 首发市场书面合规意见 | 未完成 | 合规责任人和书面结论未登记 |
| 11 | 受保护签名 Environment、正式 APK 与制品审查 | 未完成（Environment 子项已完成） | `android-release-candidate` 已设 required reviewer `chengxiaoli239`、仅 `main` branch allowlist、关闭管理员绕过，预检通过；当前唯一 reviewer 可自审；正式签名凭据/API origin、签名 APK 与候选制品审查仍缺 |
| 12 | 获批 staging smoke、回滚、值守与费用边界 | 未完成 | staging、维护窗口、回滚负责人及批准的资源/费用限制未落实 |

**总体进度：5/12 = 42% 的首发交付门已有证据。**这是等权门清单比例，不是剩余工时或上线概率；仍有 7 项未通过，任何一个真实节点/支付/合规/发布硬门都可阻止上线。2026-11-26 M5/M6 synthetic live protocol 复测提升了隔离验收证据，但没有关闭 production/runtime/会员设备数据面门，因此门数仍为 5/12。

| 模块 | 可计数子门 | 当前进度 | 下一项 |
| --- | --- | --- | --- |
| Android / M6、M8 | 本地独立身份；隔离 TUN/proof/revoke；真实双机连接与匹配回执 | 2/3 = 67% | 真实节点/profile 材料到位后，先单设备连接，再双机复制拒绝和撤权回执 |
| Web/API 与支付 | local/testing 会员购买退款；真实商户 sandbox 与对账 | 1/2 = 50% | 商户负责人提供回调/验签/重试/对账契约及受控凭据 |
| Node Agent / M5–M10 | 合成 peer/健康调度隔离证据；生产 runtime 与真实节点回执 | 1/2 = 50% | 真实节点身份、secure-store/mTLS 注入方案和获批窗口 |
| 发布与运维 / M11 | CI、来源追溯及候选审核准备；受保护签名候选；staging/回滚 | 1/3 = 33% | Environment 的 owner reviewer、main-only 和 no-admin-bypass 保护已配置并通过只读预检；当前唯一 reviewer 可自审。下一步经受控渠道准备正式签名/API origin 凭据并人工审批生成候选，再做 staging/回滚 |
| Windows / macOS / iOS | 首发范围内验收门 | N/A，后续范围 | 不计入 Android + 网站/API 首发分母；首发后另立目标和分母 |

模块百分比只反映表内子门计数，不能相加作为总进度。完整放行清单和已验收项的边界以 PRD 开发进度和 §0.2.3 为准。

**M8 Windows 状态更正（2026-11-25）**：上方旧快照中“AppState 无 bridge supervisor/process handle”的描述已被本日实现取代：AppState 现在持有生命周期 supervisor，restore/login/logout 必须先 stop/reap，失败则阻止会话变更；fake-process 成功/失败回归已加入。Windows hosted run `45532748910` native tests 49/49、UI 6/6 与 MSVC build 全通过，一般 CI `45532748858` 全绿。生产 adapter、TUN/节点数据面仍未实现，bridge gate 仍关闭。

### 0.2 一周首版冲刺（检查更新：2026-11-25；检查点 2026-12-01）

目标范围沿用已确认的 Android + 网站/API 单服务商受控试点。工作按并行泳道推进，外部放行材料不能替代，也不因代码完成自动通过。

| 时间 | 可并行工作 | 退出证据 / 阻塞处理 |
| --- | --- | --- |
| 11-24，基线日（历史） | 锁定首发范围与 go/no-go 清单；Android/Web/API/支付继续离线回归；运维侧只读核验 AWS 身份与实验资源 | 当日主线 CI `45322097403` 六个 jobs 全绿；当日 AWS 包装器曾确认 STS 有效、实验资源盘点无活动项；这是 11-24 历史状态 |
| 11-25 至 11-26（滚动） | Android 进程死亡后的显式冷启动恢复、Web/API 订单取消 UI 阶段均完成；后续只补新证据揭示的首发离线缺口。外部仍需配置签名 Environment、审批人和签名/API origin 凭据，提供 member profile/endpoint/CA/mTLS/撤权回执，确认首发市场合规意见并确定 staging 回滚窗口 | 本机与相应 hosted CI 阶段已验收。11-25 Environment 数量仍为 0；XLB 隔离实验资源范围的只读盘点已完成且未发现活动实验资源，本轮无云写入；节点与发布窗口负责人尚未确认。任一外部门未就绪时不以沙箱或合成 peer 代替，并继续无依赖离线工作 |
| 11-27 至 11-28 | 在材料齐备时构建受保护签名候选并验 APK/SBOM provenance；部署到获批 staging，跑网站/API 登录、会员权益、商户沙箱、Android 安装/连接/退款撤权与回执核对 | 必须有可复现候选摘要、真实 staging smoke、真实商户沙箱回调/对账、两台 proof-capable 身份的会员线路和匹配撤权最终流量回执；否则这些门保持未通过 |
| 11-29 至 11-30 | 故障/回滚演练，检查指标、告警、客服和资源费用；仅对已通过的链路做小范围受控试运行准备 | 记录错误预算、停止扩量与回滚责任人；未做真实节点和 staging 验收不发布付费试点 |
| 12-01 | Go/no-go 审查与候选交付 | 所有 P0 放行门通过后才可宣布受控试点上线。若外部材料延迟，交付签名候选/内部技术预览并列明阻塞，不把日期写成已上线承诺 |

一周目标是压缩 AI 可控开发与审查周期。11-25 GitHub Environment 数量为 0 的记录已由 11-26 受保护 Environment 配置更新；当前目标环境的 owner reviewer 与 main-only allowlist 已通过只读预检，但正式签名/API 凭据、签名候选和 staging 仍缺。us-west-2 XLB 隔离实验资源范围盘点已完成；最新 AWS 复核见 11-26 资源状态记录，未触碰业务服务器。节点/撤权材料、staging、商户沙箱与合规书面意见仍未登记到责任人和窗口。因此 12-01 只能作为内部冲刺检查点，当前没有证据支持受控试点上线日期预测；新增或剩余 AI 可控工作继续按新缺陷与首发验收路径排序。

### 0.2.1 四象限复盘与本周工作重排（2026-11-25）

#### 项目全景与工作量排序（2026-11-24 更新）

本计划按知识库的四象限规则管理 Codex、DeepSeek Harness 和 WorkBuddy 的开发任务：四象限决定先后，WBS 将工作拆成通常不超过两小时的可验收单元；AI 主动编码时间、CI/设备运行时间、外部等待分别记录。每轮以 PRD 最新进度和 `main`/`origin/main` 为基准，不以某个旧 worktree 的分支差异代替主线状态。

**2026-11-25 基线复核（后续更新见下项）**：主线 `main` 与 `origin/main` 同步于 `3a6c6b8`，整仓 CI `45581869634` 全绿。§0.1.1 固定 12 个首发交付门，5 项已有证据、7 项未通过，计数 42%；此数是等权门项比例，不代表投入时间、剩余工期或上线概率。后续 M5/M6 隔离协议重跑结果及 11-26 最新主线状态见下项和 PRD 开发进度顶部。

**2026-11-26 本轮进度（主线快照更新）**：主线现为 `1865f461c6e1231ad850028427d1258457983c5a`，CI `45751109316` success；本机 Node Agent `go test -race ./...` 178 passed/5 packages、`go vet ./...` 和 `go build ./...` 均通过。首发放行比例仍为 5/12 = 42%，M5/M6 隔离协议复测没有满足 production profile/runtime 或真实会员数据面门。11-26 隔离节点测试已记录删除与残留核验，账单尚未结算；当前 AWS `xlb-ops` STS 实际为 expired，因此不能声称当前资源状态已复核。责任人按用户指定为穿山甲。下一工作重点是 Q1 Android 真实会员 profile 与 PHP→Node Agent production-shaped 接线边界审查；在 profile/身份/mTLS 注入前，不把生产 runtime 开关设为可用，不宣称会员隧道或真实撤权通过。

本轮阶段汇报采用两种分开的进度口径：首发放行门仍为 5/12（42%）；工程活动记录里，本轮实际完成 1 个独立云端隔离验收包（M5 并发、M6 合成身份复制拒绝/撤权及回收），同时补全 1 个主线进度记录阶段。后一项不是上线完成比例。

| 优先级/工作流 | 当前证据与主要工作量 | AI 主动投入估计 | 依赖与安排 | 下一项可验收结果 |
| --- | --- | --- | --- | --- |
| **Q1 Android 会员数据面放行** | 两台真机身份、Android Keystore、Go bridge、Mihomo/TUN 的隔离 harness 和客户端安全诊断已有证据；生产 gate 仍关闭。最大不确定性在真实 member profile/节点凭证接线、双机复制拒绝、撤权回执与最终流量匹配 | 有材料后按 1–3 个开发/验收日估算，不能把外部排队算作 AI 工时；具体需根据节点契约复估 | 节点负责人提供 endpoint、CA/mTLS 注入方式、profile、获批窗口和回执字段。资料未到期间不改 gate、不模拟真实结论 | 材料齐备后先做单设备 happy path，再做第二身份复制拒绝和撤权；每项保留请求/节点/peer/generation 对应证据 |
| **Q1 M11 签名候选与 staging** | APK、runtime SBOM、摘要 manifest、provenance workflow 有离线/CI 验证；`android-release-candidate` protected Environment 已配置 owner reviewer、main-only allowlist、admin bypass 关闭，预检通过；无签名候选及 staging 部署/回滚验收 | 候选前置配置已完成；签名材料受控核验与候选复核仍需约 2–4 小时，staging 演练约 0.5–1.5 日，凭据准备和环境/责任人等待另计 | 当前唯一 reviewer 是仓库所有者且可自审，不是双人审批；凭据负责人经批准通道提供正式签名配置/API origin，获批者人工运行候选；staging 负责人给环境、窗口和回滚人。AI 不读取/提交凭据，不部署 | 获批者产出同一 commit 的签名 APK、SBOM、manifest、provenance；staging smoke 与回滚形成独立记录 |
| **Q1 商户沙箱与首发合规** | provider-neutral 契约和 local/testing 付款退款账本已经存在；真实商户 sandbox、首发地区合规意见未验收 | 商户契约到位后约 0.5–1.5 日接入/验收，审查时间由外部负责人决定 | 支付负责人提供回调、签名、重试、对账字段；合规负责人确认地区和书面意见。不得以本地假渠道宣称真实支付已验收 | 测试下单→回调→权益→退款→对账可追溯，并取得书面放行意见 |
| **Q2 Android 离线失败处理** | 连接与 API 失败的安全诊断编号已有实现；首页连接失败有“重试连接”、账户节点线路不可用卡有“重试同步”。本轮为 attempt-bound monitor 补上“probe 进行中旧 attempt 被替换”回归；JVM 全量、AndroidTest compile、lint、Debug APK 本机通过，主线 `f07465d` CI `45406085919` 六个 jobs 全绿。未发现还需重复建设诊断 UI 的离线缺口 | 本阶段已完成；后续由新的复现问题驱动 | 真实节点材料缺失期间可继续其它工作，但不把客户端 probe 说成节点撤权事件/线路稳定证明 | 将 Android 诊断入口从本周 AI 待办移出；真实 member profile、节点证书和回执到位后转入 Q1 真机验收 |
| **Q2 Android 恢复秘密静态存储回归** | 专用 AVD 覆盖 `SecureSessionStore` 在 KeyStore 加密状态下持久化恢复秘密/Cookie；本轮进一步确认 SharedPreferences 只有 `encrypted_state` 且解码后仍无明文，完整隔离 recovery harness 成功，主线 `099ccfd` CI `45570424762` 全绿 | 本轮新增测试和本机 harness 已验收 | 仅 Android 端本地静态存储行为，不证明硬件安全等级或生产账号保护 | 本地恢复存储证据补齐；不改变真实会员 profile、节点数据面或上线放行状态 |
| **Q2 Android API grant→system TUN 隔离联验** | 专用 API 35 AVD fake member API 实际验证 Android Keystore challenge 签名，`PlatformApi` 生成 verified grant 后经 Debug-only test action 建立 Android VPN TUN；Mihomo→proof bridge→隔离 Node Agent echo 字节一致。第二个独立 Keystore 安装获得 fake API 测试 grant 后，尝试复用第一设备 Node Agent peer 被拒 403 `unauthorized`；第一身份随后完成 `confirmed_test` 非零流量撤权，活动 TCP EOF、后续探针 403、VPN network 消失且无生产 `CONNECTED` | 完整 disposable harness 主 instrumentation 26/26，新双身份/TUN 用例 1/1；Android JVM、AndroidTest compile/lint/APK build 均通过；代码提交 `6aace71` hosted CI `45498488441`、双身份提交 `a0296cc` CI `45501333924` 和进度文档 CI `45498771193` 均全绿 | 第二身份 grant 来自 fake API，拒绝证据只证明 Node Agent peer key binding，不证明 PHP 设备名额或生产撤权；所有 API/节点均为临时 fixture，production gate 仍关闭 | 已验收第二 installation key 无法复用合成 peer，以及第一身份隔离 TUN/revoke 组合；Q1 仍需两台真机、真实会员 API/node/profile/CA/mTLS 和匹配回执复验 |
| **Q2 Android 设备移除提示** | 确认框此前无条件承诺移除即释放名额，与服务端 pending 撤权规则不符；现明确只有节点要求撤权时等确认后释放，并提示可查看状态 | Android `testDebugUnitTest lintDebug assembleDebug` 在 Android Studio JBR 21 + 本机 SDK 通过；源码本地已核验 | 仅 `apps/android/app/src/main/java/com/xlb/vpn/presentation/MainActivity.java` 与 PRD；不改服务端状态机 | 由最新主线 CI 对 Android build/lint 验收；UI 文案不作为真实节点回执或断流证据 |
| **Q2 Web/API 沙箱与数据库兼容性** | 本机 MySQL 8.4 与 PostgreSQL 16 各跑 Laravel 全套 **266 passed、2 skipped、2,363 assertions**；SQLite 全套 **267 passed、1 skipped、2,404 assertions**。修复 TEXT 默认值、过长索引名、SQLite 专属撤权计数、非 SQLite 下临时 SQLite quota test 默认连接和不合法测试夹具；CI action pin guard 63/63。hosted CI `45493715210` 的 7 个 jobs 全绿，两个 SQLite 文件库/fork TLS 专属测试继续由 SQLite job 覆盖 | 三驱动本机与 hosted CI 验收完成；本地数据库兼容阶段关闭 | 生产库仍待选择，真实部署迁移、备份恢复和目标负载并发仍无证据 | 生产库决策后按目标版本做迁移/恢复演练和目标库并发测试；此前不重复跨库基线工作 |
| **Q2 订单付款状态与 provider 路径互斥** | 取消后迟到 paid webhook 记为 `review` 且不排 fulfillment/grant；已接受付款后取消返回 409，fulfillment 单次发放；直连 sandbox payment 与 provider session 双向互斥。MySQL/PostgreSQL 均通过关键 suites；锁序统一为订单→provider→fulfillment、重试记录 provider→outbox。SQLite 全套 267 passed、1 skipped、2,404 assertions，Pint 通过 | 沙箱状态转换与三驱动关键用例 CI 已验收 | 跨驱动 Feature suite 不等于实际生产并发负载或商户 webhook/对账证据 | 后续由新复现问题驱动；按真实渠道契约验证异步付款退款及最终生产库并发 |
| **Q2 发布可追溯性** | 候选 artifact 校验、SBOM/provenance CI 和 §0.2.3 go/no-go 清单复核已完成；`main@f507db0` 的 CI `45456754780` 六个 jobs 全绿；2026-11-26 `android-release-candidate` Environment 已配置仓库 owner reviewer、仅 `main` allowlist 和 no-admin-bypass，预检通过 | 离线清单与环境保护子项关闭；正式候选时间取决于正式签名凭据/API origin 和获批的候选审查 | 环境不再是缺项；当前单 reviewer 可自审，双人审批未配置；keystore/password/API origin 仍由凭据负责人经受控渠道提供，不写入仓库/聊天 | 材料就绪后由获批者构建并审查 APK/BOM/manifest/provenance；当前不放行 |
| **Q2 Windows proof capability 离线接线** | CNG P-256 non-exportable signer 与 server-authorized peer proof capability 的绑定签名测试通过；Windows UI 6/6/build 与 CI `45463883844` MSVC `cargo test`/build 全绿，一般 CI `45463883966` 全绿 | 本机无 Windows/Rust target；native 验收由 hosted Windows runner 完成 | 本阶段只增加 test seam；production bridge gate 继续 false | capability key-binding 单元关闭；下一阶段需先设计并测试 Tauri proof lifecycle/stop/logout/revoke adapter，仍不接生产 TUN/节点 |
| **Q2 Windows proof 生命周期失败关闭** | AccountClient loopback identity mismatch→session 401、grant 身份/代次/HTTPS origin/CA/profile digest 校验、敏感数据清理均有测试。新增 `AppState` supervisor 在 restore/login/logout 前 stop/reap，失败则阻止会话变更；hosted Windows run `45532748910` native 49/49、UI 6/6、MSVC build 全绿。 | supervisor 的 fake-process 生命周期阶段已完成；下一项是生产 adapter/bridge transport 的最小可运行接线和 Windows 本机/hosted runtime 验收。真实节点 profile/runtime 与在线撤权重验仍需受控节点材料；本机没有 Windows/Rust runtime 环境。 | `apps/windows/src-tauri/src/lib.rs`、`proof_bridge.rs`；grant 只驻内存，debug 脱敏并 Drop zeroize，`PROOF_BRIDGE_ENABLED=false`。fake adapter 不得当作生产 transport 或 VPN。 | AppState stop/reap 合同已验收；production adapter、bridge 启动/打包、TUN、真实节点连接与运行中撤权协议仍未完成，不启用 gate。 |
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

### 0.2.4 Worktree 未合并成果复核（2026-11-25）

**主线推进后的 worktree 补审（2026-11-25，`main@24dd1b9`）**：再次检查 39 个已登记 worktree。`git cherry -v main HEAD` 仍显示 4 个分支有 patch-unique 提交，分别是 `codex/android-node-auth-status` 1 条、`codex/android-start-stop-cleanup` 1 条、`codex/aws-inventory-status-refresh` 3 条、`codex/fleet-recovery-preview` 1 条；与下表逐项核对后，这些都是已被主线实现覆盖的代码或过时进度文字，没有新的可验收功能可合并。主 worktree 的 `.playwright-cli/` 与 `output/` 仍有未跟踪文件；`codex/android-native-ci` 与 detached `android-revoked-permission` 两个 Android worktree 各有一项跟踪文件改动和一个未跟踪文件，均未读取、暂存、清理或覆盖。没有发现可直接并入主线的新阶段。

**Web/API 本地浏览器联验（2026-11-24）**：用 Playwright CLI 对本机会员 portal 与 Laravel 完成登录→套餐选择→建单→确认沙箱付款；HTTP 201 创建测试订单和接受模拟付款，UI 展示 `paid_test`、权益延长、固定订阅 QR/link。随后 sandbox refund 清理本次订单，并在 SQLite 核验订单和 entitlement grant 均为 revoked 测试态。浏览器请求没有触达商户 API；不代表真实收款、生产支付、staging 或节点撤权回执。订阅 URL 型凭证只在本机临时浏览器快照显示，本轮快照已删除。

**M11 外部签名配置复查（2026-11-24，只读 GitHub 元数据）**：本轮再次查询 repository environments 与 Actions variables，均为空（Environment `total_count=0`、variables `total_count=0`）；没有读取 secrets、创建 Environment 或发起候选 workflow。`android-release-candidate` 仍需仓库管理员设置 main-only 部署规则与审核者，并由凭据负责人通过受控通道配置正式签名/API origin 后，才可进行审批后的手动候选构建。

本轮只读盘点发现 39 个 worktree。该盘点以 `2918b4c` 为基线；此后请求诊断与节点操作编号阶段已分别提交到 `0aa1f10`、`4f3de03`、`da9d2b6`、`5e6c299`，对应代码 CI `45551133373`、`45552121951`、`45553204296`、`45556194672` 全部 jobs success，`main` 与 `origin/main` 继续同步。逐分支以 `git cherry -v main <branch>` 比较 patch 等价性后，绝大多数领先提交已在主线有等效补丁；少量非等效提交逐项核对如下：

| 分支 | 独有 patch | 复核结果 |
| --- | --- | --- |
| `codex/android-node-auth-status` | 1 条旧进度说明 | 授权状态代码及更新后的 Android 状态说明已在主线；旧段落仍写“待合并”，不再 cherry-pick |
| `codex/android-start-stop-cleanup` | 1 条清理顺序测试 | 主线 `MemberTunnelSessionTest` 已有同一阶段清理顺序断言，不重复合并 |
| `codex/aws-inventory-status-refresh` | 3 条 grant handoff / foreground-start 提交 | 当前主线已包含 handoff ID、陈旧 intent 和前台服务生命周期行为及更新的 Android 测试；旧实现不重复 cherry-pick |
| `codex/fleet-recovery-preview` | 1 条旧估期与 AWS 状态说明 | 与当前 2026-11-25 计划及过期会话事实冲突；只保留当前路线图口径 |

其余分支领先提交均为 patch 等价或没有领先提交。主线保留未跟踪的 `.playwright-cli/` 与 `output/`；`codex/android-native-ci` worktree 有 `apps/android/app/build.gradle` 编辑及未跟踪 instrumentation 文件，detached `android-revoked-permission` worktree 有未跟踪 instrumentation 文件和 `java_pid90057.hprof`。这些内容均未检查修改、未暂存、未提交、未清理。除当前主线工作外，本轮没有合并或改写其他 worktree。后续仍按实际代码与测试语义判断，不用 ahead 数量代替可验收价值。

### 0.2.3 Android 候选阶段 Go/No-Go 检查清单（2026-11-25 复核）

这是候选构建与后续受控试点的检查单，不授权创建 GitHub Environment、写入 secrets、部署或发布。

| 阶段 | Go 条件 | 当前结论/动作 |
| --- | --- | --- |
| 源码冻结 | 候选来源为已审阅的 `main` commit；常规 CI 六个 jobs 全绿；构建理由可关联到 issue/审批记录 | 当前 main `24dd1b9` 的 CI `45571571648` 全部 jobs success；正式候选冻结仍须把构建理由关联到审阅/审批记录 |
| 签名权限 | `android-release-candidate` Environment 存在、仅允许 main、要求人工审核且 admin bypass 关闭；签名 secrets 和 approved API origin 由负责人经受控通道配置 | 2026-11-26 已创建环境并要求仓库所有者 `chengxiaoli239` 人工审核，显式 branch allowlist 仅为 `main`，`can_admins_bypass=false`；只读预检实测通过。`prevent_self_review=false` 且当前仅此一位 reviewer，可自审但非双人审批。四项签名 secrets 与 API origin variable 尚未配置，正式签名候选不可运行 |
| 候选构建 | 获批者手动运行 workflow；Release unit tests、lint、APK signature/package identity 检查、runtime SBOM、APK+SBOM source-bound manifest、manifest 校验及两项 provenance attest 全部成功 | 常规 CI 仅以临时测试密钥检查签名配置并产出 Debug APK/SBOM；正式 workflow 仍受 `workflow_dispatch`、`main` 与 protected Environment 限制。没有正式凭据，未构建签名候选 |
| 候选审查 | 下载 APK/BOM/manifest；核对 manifest source commit 等于审阅 commit、摘要一致；验证 APK signer/package 与来源证明；记录审阅结果 | `verify_artifact_manifest.py --expected-commit` 现可强制 manifest commit 与审阅目标相同，signed candidate workflow 传入 `GITHUB_SHA`；错配/非法输入测试通过，ops 66/66，主线 CI `45562668654` 全绿。当前无正式签名候选制品，候选审查本身仍未通过 |
| staging 放行 | 负责人提供已批准 staging、数据保留与回滚窗口；候选摘要固定后部署；登录/权益、商户沙箱、Android 安装/线路和撤权回执 smoke 通过；回滚演练有记录 | 尚无 staging 环境、窗口与回滚责任人记录；不部署、不产生云费用 |
| 付费试点 Go/No-Go | Android 双身份复制拒绝和匹配节点撤权最终流量回执、商户 sandbox 对账、目标市场合规意见、签名/制品审查、staging 和支持/回滚责任均有证据 | **No-Go**：真实会员节点/撤权回执、真实商户 sandbox/对账、合规意见、正式签名候选、staging 与责任人日期均未齐。离线 CI、AVD、合成 peer、local/testing 不能代替放行证据 |

**候选下载后复核命令模板**：获批审核者下载 workflow 的 APK、`build-manifest.json` 与 runtime BOM 后，在仓库 checkout 中设置 `candidate_dir`、`expected_commit` 及下载包内三个文件的相对路径。下面的 manifest 检查将 bundle 目录作为根，GitHub attestation 检查同时固定仓库、签名 workflow、main 来源引用和审阅 commit。APK signer 的 SHA-256 指纹还须与通过受控渠道批准并记录的 release signer 指纹一致；不要把 keystore 或密码写入这些记录。

```bash
set -euo pipefail

candidate_dir="/absolute/path/to/downloaded/candidate"
expected_commit="replace-with-reviewed-main-commit"
manifest_rel="path/to/build-manifest.json"
apk_rel="path/to/app-release.apk"
sbom_rel="path/to/bom.json"

python3 ops/verify_artifact_manifest.py \
  --repo-root "$candidate_dir" \
  --expected-commit "$expected_commit" \
  "$manifest_rel"

"$ANDROID_HOME/build-tools/35.0.0/apksigner" verify --verbose --print-certs "$candidate_dir/$apk_rel"
"$ANDROID_HOME/build-tools/35.0.0/aapt" dump badging "$candidate_dir/$apk_rel" | grep -F "package: name='com.xlb.vpn'"

gh attestation verify "$candidate_dir/$apk_rel" \
  --repo chengxiaoli239/xlb \
  --signer-workflow github.com/chengxiaoli239/xlb/.github/workflows/android-release-candidate.yml \
  --source-digest "$expected_commit" \
  --source-ref refs/heads/main
gh attestation verify "$candidate_dir/$sbom_rel" \
  --repo chengxiaoli239/xlb \
  --signer-workflow github.com/chengxiaoli239/xlb/.github/workflows/android-release-candidate.yml \
  --source-digest "$expected_commit" \
  --source-ref refs/heads/main
```

该模板尚未用于正式签名候选。`android-release-candidate` Environment 已配置 owner reviewer 和 `main` allowlist，预检通过；正式候选和人工来源证明尚未验收，签名凭据及 API origin 尚未配置。

**离线检查证据**：ops workflow 权限/步骤和 artifact manifest 正负例测试曾 10 项通过，常规主线 CI `45455644892` 六个 jobs 全绿。它们验证规则和候选构建技术路径，不实际运行正式签名 workflow、不访问 secrets、不产生正式候选 APK，也不代表 protected Environment 或发布放行已验收。

**2026-11-25 主线设备名额复核**：在 `main@158d93f` 运行 `apps/php-api/tests/Feature/MemberDeviceApiTest.php`，19/19、248 assertions 通过。包括名额为 1 时第二个不同安装身份经设备 challenge 签名后仍被 `409 device_limit_reached` 拒绝，登记数保持 1；这是 Laravel Feature 控制面证据，不替代 Android 真机、生产 API、真实节点复制拒绝或撤权回执。此次复核不改变 Go/No-Go：Environment 为 0，签名候选、staging、商户 sandbox、合规意见及真实会员节点回执仍未验收。

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
| 会员节点与撤权回执 | 11-25 经项目包装器完成 us-west-2 XLB 实验资源范围只读盘点，历史实验栈均已删除，既有 node-manager access 栈保留；无云写入。会员专属线路/节点材料仍缺 | 节点/运维负责人提供隔离节点 endpoint、节点身份、CA 与生产用途 mTLS 注入路径、获批 canary 地址；没有新协议变更时不重建节点；材料与窗口获批后先生成并审查有界 plan，再按授权执行 | 节点 `node/peer/request/generation` 匹配的回执、最终累计流量与重连拒绝；两台设备各自输出脱敏版本/时间证据 | 负责人/材料尚未确认；12-01 仅内部检查点，缺材料不得称受控试点上线 |
| Android 受保护签名候选 | 候选 workflow 和签名配置离线验证通过；2026-11-26 `android-release-candidate` protected Environment 已创建，预检证明 owner reviewer 与 main-only allowlist 有效 | 凭据负责人按批准通道注入 keystore/password 与 approved API origin，不在仓库/聊天传递；获批 owner 人工审阅候选 workflow | 获批者手动运行 workflow，保存 APK、BOM、manifest、provenance 与审核结果；不自动建 GitHub Release 或上架 | 环境保护准备已完成；正式签名凭据和 API origin 尚缺，因此没有正式候选/审查结论；11-27 仍是旧计划检查点、非候选日期承诺 |
| staging 与回滚 | CI 容器构建/HTTP smoke 通过；无 staging 部署记录 | staging 负责人确认隔离 URL、数据库/数据保留、部署权限、回滚责任人与维护窗口；任何新增费用/云资源先单独审批 | 按同一源 commit 部署、健康检查、登录/权益/支付沙箱 smoke、回滚演练记录 | 11-25 确认窗口；11-28 前验收，否则不开放付费试点 |
| 商户沙箱与首发合规 | provider-neutral 支付及退款状态机只有 local/testing 证据；真实商户与首发市场书面意见未登记 | 支付负责人提供单一商户 sandbox、回调契约、签名/重试规则和对账字段；合规负责人确认首发地区、退款/隐私/服务条款并给书面意见 | 商户 sandbox 下单→回调→权益→退款→对账记录，以及可审计合规结论 | 11-25 确认资料/责任人；11-28 前验收，否则只保留本地沙箱演示，不宣称可收真实款或可上线 |

**历史判断快照（2026-11-25，当前配置见 §0.1）**：当时负责人及日期没有确认，核心节点材料与签名 Environment 缺失；XLB 实验范围只读盘点已完成，未发现活动实验资源；没有进行全账号账单核验或云写入。12-01 为内部冲刺检查点，不是可信上线承诺。没有节点材料和新协议变更时不创建节点；外部泳道与离线代码并行。M10 真实健康/切换结论仍待获批 canary 和节点材料。

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

**worktree / 并行成果复核（2026-11-25，基线 `main=c9779b2`）**：只读盘点 34 个登记 checkout，主线仅有预存 `.playwright-cli/`、`output/` 未跟踪目录。`android-native-ci` 保留 1 处 Gradle runner/AndroidX instrumentation 依赖改动和未跟踪 AndroidTest 文件；detached `android-revoked-permission` 保留 1 个未提交 instrumentation 用例及 `java_pid90057.hprof`；均未改动、暂存或清理。默认 checkout `android-stale-grant-foreground` 为 `e65718a`，相对 `origin/main` 落后 77 个提交、领先 5 个；这 5 个提交的 patch 均已存在于主线 `5be4a7a` / `88f5f0c`，不重复合入。`aws-inventory-status-refresh`、`android-node-auth-status`、`fleet-recovery-preview` 仍各有 patch-ID 不同的历史提交；逐项核对发现 grant handoff/foreground stale-intent 行为与测试、节点授权状态映射与诊断测试、review-only fleet recovery CLI 与单测均已在当前主线存在，另外只剩过时进度记录/估期文字，不是未交付功能。未发现需要倒合的功能；不删除旧 worktree，不触碰用户改动。后续新 worktree 应从更新后的 main 创建，旧分支只在其新增独立验收价值重新出现时处理。

近期按“共用契约先锁定、平台实现并行、真实节点串行验收”推进。下列早期 11-24 至 11-25 事件与会话记录均按其日期保留为历史，不表示当前外部会话或环境状态：

1. **Android M8 离线证明阶段（2026-11-22 已完成）**：专用隔离 AVD instrumentation 已验证同 installation ID 稳定复用 P-256 公钥、不同 installation ID 得到不同公钥、challenge 签名可由对应公钥验证、私钥不可导出；完整 instrumentation 6/6 通过。此阶段不证明硬件 backed provenance，也未接入会员隧道。
2. **Windows M8 平台密钥阶段（2026-11-22 hosted 已通过）**：Windows-only CNG P-256 signer 使用直接 `windows-sys 0.61.2` bindings，existing key 每次打开均核对 signing-only/zero-export/P-256；Windows hosted 29 tests 与 MSVC build 均通过（run `44959472000`）。本机无 Cargo/Rust；bridge 仍关闭，不能外推为 sidecar/TUN/会员线路验收。两个平台只共享 `xlb-proof-v1` wire/身份语义，不共享私钥或把一个平台的测试结果外推到另一个平台。
3. **M10 Agent probe 阶段（隔离实现及 PHP→Agent→canary 本机全链通过，真实目标待验收）**：PHP 按 node ID 配置 HTTPS endpoint 与逐节点 CA bundle，使用 client cert/key、关闭 redirect 和随机 challenge/node ID 复核；仅显式选择 `NODE_HEALTH_PROBE_TRANSPORT=http` 才注册 transport。Node Agent 已增加仅在 trusted runtime config 显式选择 `https-canary` 时启用的 executor，固定 `/.well-known/xlb-node-probe/v1`，只连经公网 IP 筛选的 TCP/443，要求 TLS 1.3 并按显式 CA 验证，无代理/redirect，限制 JSON 响应并要求 challenge string 完全一致。Go 全套 race 133、vet/build 通过；PHP→Go mTLS→本地 TLS canary 定向测试 1/1、32 assertions 通过。runtime 配置缺少该项时保持 unavailable；Agent probe listener 仍要求独立 loopback `NODE_AGENT_PROBE_LISTEN`，production mode 仍拒绝启动。所有集成使用本机 loopback fixture，不代表获批真实节点；仍需真实 canary URL/CA 与隔离节点窗口验实际 endpoint、超时、证书失败及健康状态新鲜度。PHP fake、合成 canary 与 `/healthz` 均不能证明真实节点健康或会员稳定性。
4. **M6 会员节点阶段（身份已齐，云端验收前串行）**：两台物理设备足够，不需要第三台。ALT-AN00 与 VOG-AL10 当前安装均已完成沙箱登录和独立 P-256 challenge 绑定；两条旧设备记录保留。11-25 经 `ops/aws-cli` 在 us-west-2 完成 XLB 隔离实验标签范围只读盘点：实验栈均 `DELETE_COMPLETE`，仅既有 `xlb-node-manager-access-usw2` 保持 `CREATE_COMPLETE`；实验标签下无实例、卷、网卡、安全组、地址、Scheduler 或 SSM 托管实例，`XlbLab*` IAM 角色/配置文件也为空。没有云写入，不含全账号账单核验。当前仍缺 server-verified member profile、节点身份/endpoint、mTLS 注入材料与匹配节点回执。材料到位后再做有效会员连接、双机同时/错峰凭证复制拒绝、匹配 node/peer/request/generation 的撤权回执与最终流量单调验证。无节点回执不得宣称断流。
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
| 4 | M6 两台现有会员真机数据面验收 | Android/API/验收记录，云资源另按计划 | 两机同时/错峰复制均拒绝；有效会员连接成功；退款撤权有匹配回执且最终流量单调 | 两台设备登录和独立绑定均已具备，不需要第三台手机；11-25 us-west-2 XLB 隔离实验资源范围盘点已完成且无活动实验资源。真实会员 profile、节点身份/端点/证书及可核对的节点回执仍缺；获批材料/窗口到位后按范围生成 plan 并复测 |

M11 隔离控制面负载工具已在主线，包含最多 100 个本地请求、并发 1/4、loopback-only、临时 SQLite、无 Cookie 重放及 health preflight。它只形成开发机控制面基线，不得外推到生产容量或 VPN 隧道稳定性。D2 跨端登录方式、D3 智能线路范围、高可用拓扑/SLO 与 E4 资源费用仍需用户决策；可并行的离线工作不因此暂停。任何阶段碰到外部门时，继续执行其他无依赖离线工作。

### 0.3 一周 AI 冲刺目标与整体放行时间分开估计（2026-11-25 更新）

此前“3–6 个月、约 2027-02 至 2027-05”是包含节点、商户、合规、staging 和试运行等待的低置信整体日历估计，并非 AI 编码工期。其“两周内外部材料到位”前提未满足，已撤销为当前上线预测。当前以 2026-12-01 为内部冲刺检查点，覆盖 Android + 网站/API 单服务商受控试点的代码、离线验收和候选审查；受控试点能否按期上线，仍取决于真实节点、商户沙箱、合规、正式签名候选与 staging 放行门。GitHub `android-release-candidate` 环境现已配置仓库 owner reviewer 和 main-only allowlist，但签名密钥/password/API origin、签名 APK 与 staging 均未就绪；历史 11-25 “Environment 数量为 0”记录已被 11-26 配置更新取代。因此当前仍无证据支持受控试点具体日历上线日期。

| 交付范围 | 当前估计 | 说明 |
| --- | --- | --- |
| Android + Web/API 单服务商受控试点的 AI 可控代码与离线验收 | 目标 1 周，至 2026-12-01；低置信 | 冲刺范围为代码/自动化/离线测试达到可审核候选状态，包含 Android 失败诊断与准入失败关闭、会员授权/API 错误状态回归、跨模块回归、候选制品校验及发布清单。若发现新的安全或数据完整性缺陷，优先修复并如实更新目标。AWS/节点/商户/合规/受保护签名环境等外部等待不计入编码周期；完成冲刺不代表真实线路、真实支付或受控试点已放行 |
| Android + Web/API 单服务商受控试点 | 暂无可靠日历日期 | 需真实 member profile、节点 endpoint/CA/mTLS 与撤权回执，真实商户沙箱/回调契约、合规意见、受保护签名环境、staging/回滚/监控和试运行观察。取得可用材料和责任人日期后，再按实际验收步骤重算 |
| Android、Windows、macOS、iOS 全平台公开发布，并完成多云与放量门 | 至少数月，尚无可承诺区间 | 首发受控试点之后再排期，另需 Windows sidecar/TUN、macOS/iOS 系统扩展与商店/签名验收、第二家 provider conformance、容量模型及 E6/E7 放量退出门 |

当前 M8/M6 关键路径仍是 Android member bridge 与真实会员数据面。两台现有真机 installation 的独立 P-256 绑定已具备，不需要第三台手机；专用 AVD 本轮再次通过沙箱 grant→proof bridge→Android system TUN→合成 Agent echo→`confirmed_test` 撤权回执集成 harness，离线开发不依赖手机在线。Android member admission gate 仍关闭，production Node Agent runtime 尚无生产凭据/endpoint/CA 注入材料。2026-11-26 `ops/aws-cli` STS 只读复核有效，KMS key 为 `PendingDeletion`（预计 2026-12-03 16:11 +08），`xlb-lab-access-usw2` 为 `CREATE_COMPLETE`，`ManagedBy=xlb-node-lab` 活动实例为空；未触碰业务服务器。GitHub `android-release-candidate` 环境现有 owner reviewer 和 main-only allowlist，预检通过。真实会员 profile、节点 endpoint/CA/mTLS 材料及匹配撤权最终流量回执仍未提供。CI `45837776131` 的当前全模块验证成功；这些离线/CI 证据不代表生产签名候选、真实会员线路或 M6 放行。剩余离线工作继续按 §0.2 及 PRD 逐阶段推进。

**需要用户/外部协助，但不阻断上述离线工作：**

1. 首发范围：已确认第一阶段为 Android + Web/API 单服务商受控试点；Windows、macOS、iOS、第二家云厂商不作为首发放行门，后续单独排期。E3/E6/E7 仍是后续阶段。
2. 产品决策：确认 D2 跨端登录方式、D3 智能线路是否只对账号级兼容线路自动选路（建议严格设备线路保持逐设备 peer 受控切换）、首发高可用拓扑、SLO/错误预算和 E4 资源费用上限。代码与无费用验证继续进行；涉及资源创建/费用仍需逐项批准。
3. 节点联调材料：由节点/运维侧提供可用于获批验收的 member profile 生成条件、node ID、管理员控制的 endpoint、证书身份映射和受控 mTLS CA/client/server 证书注入方式，以及能核对 node/peer/request/generation 的真实回执。凭据走本机受控安全存储，不经聊天、仓库或普通文档。现有合成 peer 回执不能替代此项。
4. 商业放行材料：真实支付服务商的商户沙箱账号、API/验签与回调契约，以及目标市场对应的合规书面结论；凭据使用安全注入。provider-neutral 支付代码可继续，但不能宣称真实支付已验收。
5. 分发条件：若 Windows/iOS 纳入首发，需要相应代码签名、分发/商店账号和可用验收设备；若不纳入，可在 Android 首发之后推进。
6. Android signed candidate：Environment reviewer/branch/admin-bypass 保护已配置，但其四项 secrets 与 API origin variable 尚缺。仓库管理员/发布凭据保管人需在 GitHub `Settings → Environments → android-release-candidate` 通过 UI 安全录入 `XLB_ANDROID_RELEASE_KEYSTORE_BASE64`、`XLB_ANDROID_RELEASE_STORE_PASSWORD`、`XLB_ANDROID_RELEASE_KEY_ALIAS`、`XLB_ANDROID_RELEASE_KEY_PASSWORD`，并设 `XLB_ANDROID_RELEASE_API_BASE_URL` 为已批准的 HTTPS API origin；不要把 keystore、password 或 values 发给 Codex、写进仓库或普通聊天。若当前没有正式 keystore，先由发布凭据保管人确定并安全备份发行密钥后再配置。当前唯一 reviewer 是 repository owner `chengxiaoli239`，`prevent_self_review=false`；可由 owner 自行审阅自己的候选，若业务要求双人审批，仓库管理员需另加一名独立 reviewer。

**时间判断更新（2026-11-26）**：AI 可控代码和离线候选原以 2026-12-01 为内部冲刺检查点；这不是受控试点上线承诺。受控试点没有可靠日历日期，须等真实会员节点及撤权回执、商户沙箱契约、合规书面意见、正式签名候选和 staging/回滚门落实后，再按实际验收计划重估。`xlb-ops` STS 当前有效，AWS 资源最新状态见 §0.1，不需要再次登录才能继续只读复核。`android-release-candidate` 环境已设 owner reviewer 与 main allowlist，但正式签名材料/API origin 尚缺。全平台公开发布及多云放量仍需数月以上，且涉及 Android/Windows/macOS/iOS 各自系统隧道、签名/分发审核和第二服务商 conformance。

### 0.4 四象限调度与主要工作量（2026-11-25）

四象限的通用定义、适用 Codex、DeepSeek Harness、WorkBuddy 的规则已记录在知识库 `10-全局/规范/AI项目四象限与持续推进.md`。本节维护迅连宝当前排序，按放行影响和真实截止判断紧急度，不按“容易做”排序。用户报告本轮目标已历时约 2 天 12 小时，这是日历耗时；仓库没有逐项 AI 主动工时记录，因此不把这段时间折算成代码工时或完成率。

| 象限 | 当前事项与证据 | 下一步、依赖与估时口径 |
| --- | --- | --- |
| Q1 重要且紧急 | **最大技术工作量与首发关键路径**是 Android M8/M6 生产形状闭环：服务端签发的 member grant/profile → 设备 proof bridge → Mihomo/TUN → 节点 peer 授权，再覆盖重连、停止、退款撤权和匹配节点回执。离线/AVD 与本轮 synthetic live protocol 已有证据，生产 gate 仍关闭，不能将它们当作会员线路验收。 | 继续核查 PHP member provisioning/mTLS 接线与 Node Agent production runtime 在真实 member profile 前可完成的最小实现；真实 profile/endpoint/CA/身份注入及匹配回执契约未齐前，不改生产 gate、不宣称会员线路通过。AWS 隔离节点已回收，无需重复创建同类资源。 |
| Q1 重要且紧急 | **发布阻塞主要是外部放行工作**：GitHub `android-release-candidate` Environment 已设置仓库 owner `chengxiaoli239` 为 required reviewer 且仅允许 `main`，只读 preflight 通过；正式签名密钥/password/API origin 仍未配置。真实节点与撤权证据、商户沙箱契约、合规意见、staging/回滚窗口和负责人日期也未齐。 | Environment 保护子项已完成；签名材料由凭据负责人经受控渠道提供，staging/书面材料由责任角色推进。当前继续无依赖离线工作。us-west-2 KMS key 及隔离 IAM/node 状态见顶部最新只读核验；不触碰业务服务器。外部日历等待不可由 AI 编码压缩。 |
| Q2 重要但不紧急 | Android `MemberTunnelSessionTest.stopRequestDoesNotWaitAtAnyUnresponsiveStartupStage` 覆盖 fake runtime 最终返回时资源按 TUN/core→bridge→credentials→certificate 清理；2026-11-25 新增专用 AVD 服务级竞态回归，在 isolated Node Agent echo probe 阻塞时撤权并停止，确认无 `CONNECTED`、无保留 VPN/member session、health monitor 不再探测。完整 AVD harness `test-anonymous-recovery-emulator.sh` 退出码 0，主 instrumentation `OK (27 tests)`，Android JVM/lint/Debug build 通过。 | 此项服务级覆盖仅限 fake/local API、合成 Agent 与可取消 echo probe；不证明永久阻塞 JNI 可强制回收，也不证明真实会员节点或撤权断流。真实双机/节点验证仍走 Q1，材料到位后按回执核验。 |
| Q2 重要但不紧急 | Node Agent proof bridge 现按活动 SOCKS 隧道数维护共享 peer session；并发流未全部结束时保持心跳，最后一条流结束即发送 disconnect 并关闭 idle HTTP/2 连接；本地 isolated 回归覆盖空闲释放、重新建连、heartbeat 503 后保留会话并继续重试，以及 heartbeat 401/403 后关闭桥接和活动隧道，race case 连续 20 次通过。 | 本地合成状态只证明客户端重试/失败关闭行为；真实网络切换、Android 后台/休眠、生产节点稳定性和会员撤权断流仍未验收。后续以真实 endpoint/CA/mTLS 在获批节点和两台会员真机验收。 |
| Q2 重要但不紧急 | PHP quota reservation 在 peer 行锁后复查 request ID；同 peer 重试幂等及跨 peer request ID 唯一键冲突回归已有屏障。2026-11-26 复核发现旧 loopback host allowlist 不能证明数据库为 disposable，已改为单独 `XLB_QUOTA_DB_RACE_URL` 并移除测试内数据库创建/删除；CI 在一次性 service 中预建 run-scoped race DB，`xlb_test` 留给正常套件。主线 CI `45832866755` 全绿，MySQL 8.4/PostgreSQL 16 race compatibility 均成功；本机 Laravel suite 282 passed、2 skipped、2,517 assertions，Pint 通过 | SQLite `IMMEDIATE` 与无专用 DSN 的本地环境会跳过两 peer 锁竞争；hosted fixture 验收只证明 CI disposable service 里的 race 行为，不验证生产数据库压力或容量。 |
| Q2 重要但不紧急 | Tauri `AppState` 持有 lifecycle supervisor，并在 restore/login/logout 前 stop/reap；若进程无法回收则 fail closed 阻止会话变更。Fake-process 成功/失败回归已实现，Windows hosted run `45532748910` native tests 49/49、UI 6/6、MSVC build 通过。Windows 数据面不属于当前 Android + Web/API 试点放行范围。 | supervisor 生命周期合同本阶段关闭；本机没有 Rust 工具链，Windows runner 提供 native 验收。生产 adapter/TUN/节点仍列后续，不因 supervisor 接线提前放开 gate。 |
| Q2 重要但不紧急 | M10 健康探测批次现按 UTC 最近尝试时间轮转，覆盖首次探测、陈旧观测重复占位和超出显式批次上限的拒绝；本地定向 29/29、Laravel 270 passed/1 skipped，提交 `5802a79` 的 CI `45548542173` 全绿。 | scheduler、probe driver 和线路过滤仍默认关闭；未获批真实节点/canary 观测前不启用、不宣称稳定或故障切换完成。资料到位后由节点负责人提供 endpoint/CA/mTLS 和目标，按真实探测回执校准 TTL、误报及恢复阈值。 |
| Q2 重要但不紧急 | PHP API 生成服务端 UUIDv4 `X-Request-Id` 并隔离请求日志上下文；Web 与 Android 错误提示只显示格式合法的服务请求编号。设备授权状态另显示稳定 `node_operation_id`，它与 outbox/Agent 回执的 operation `request_id` 一致；Android 专用 AVD 联验新增用例 1/1 通过，所属 7 项 instrumentation class 有 6 passed、1 项因预存 VPN consent 跳过。 | Laravel ProvisioningService/MemberDevice API 27/27、274 passed/1 skipped，Web 10/10 与 build 通过，Android JVM/lint/AndroidTest compile/Debug build 及专用 TUN/revocation AVD 通过。提交 `0aa1f10`、`4f3de03`、`da9d2b6` 的 CI `45551133373`、`45552121951`、`45553204296` 全绿。HTTP ID 与异步 outbox operation ID 仍无持久映射，也无持久故障事件或 telemetry；是否增加此类映射需先评估留存范围，当前不扩大节点协议或遥测范围。 |
| Q3 紧急但相对次要 | 20 分钟哨兵状态播报、重复状态核对和临时进度汇报。 | 自动化唤醒后检查真实提交/测试/阻塞并执行下一项离线工作；“无代码变化”不是停止理由。用一条同步的模块状态和可验收队列减少重复巡检开销。 |
| Q4 不重要且不紧急 | 本次受控 Android + Web/API 试点之外的全平台扩展、外观细节，以及 Reality/uTLS 等抗审查伪装。 | 移至首发之后；Reality/uTLS 只保留为已知差距，不作为本周冲刺或已解决事项。 |

**本周执行顺序**：Android + Web/API 是已确认的试点范围。先完成直接服务该范围的离线安全回归和受保护候选检查；节点、商户、合规、Environment 与 staging 外部泳道由用户稍后指定负责人后并行推进；真实节点材料齐备后串行做隔离节点验收及两台现有会员真机复测。Windows grant 生命周期属于可并行的后续平台工作，不能占用 Android 试点关键路径。若外部材料未到，持续交付有测试证据的离线阶段和准确的放行缺项，不把离线通过包装成上线。每个代码包限定接口/文件边界、独立测试和退出证据，已合入主线的诊断阶段不重复开发。

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

本节针对 Android + 网站/API 首发受控试点。它是待负责人填完并演练的模板，不是当前可执行发布授权。试点人数/流量/费用上限、停止阈值、值守与升级联系人、回滚负责人必须先经批准登记；没有 staging 观测数据时，不预设峰值倍数或 5%→100% 扩量阶梯。分级扩量与热备容量属于规模化发布门，须另按获批 staging 数据制定。

| 时点 | 动作 |
| --- | --- |
| T-1 | 冻结变更；核对审阅过的源码 commit、候选摘要、签名与 API origin；验证备份/恢复步骤、告警通道、批准的用户与资源上限、值守升级联系人及回滚负责人；完成一次获批的 staging 回滚演练 |
| T-0 | 只开放已批准的试点 cohort 与资源上限；每次增加用户或流量前由值守负责人按已批准阈值检查连接、API、支付/权益对账、节点余量、工单和费用；没有批准的下一档时保持当前范围 |
| T+观察窗口 | 按已批准的试点观察周期检查真实连接与撤权回执、错误预算、客服问题及实际费用；不把观察窗口或候选 CI 结果表述为长期稳定性证明 |
| 触发停止/回滚 | 命中批准的停止阈值、账务不平或数据完整性异常时停止新增试点用户并执行已演练的配置/路由/应用回滚；保留支付账本和审计记录；撤权只按节点协议处理，有回执后才记录断流结果 |
| T+1 | 对照获批的回滚与数据恢复记录；核对支付/权益、节点事件、告警和工单；值守及变更负责人复盘后关闭本次试点窗口 |

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
