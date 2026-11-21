# 迅连宝会员连接流程 PRD（产品口径定稿，待验收实现）

确认：2026-11-18 09:44。范围：自有 Android / Windows / macOS 客户端、会员网站、浏览器扩展，以及 Clash Verge 等兼容客户端。本文区分产品目标、开发期验证和正式上线门槛；不表示当前代码或正式节点已实现。阶段进度见下文。

## 开发进度（2026-11-21）

- **客户端内核决策（2026-11-21，已定）**：维持 Mihomo，不切换内核。用户提供的 Clash Verge 设置截图显示用户侧核心为 `alpha-69c1631 Mihomo`，与项目 Mihomo `v1.19.34` 同属 Mihomo 家族，用户已明确要求继续使用 Mihomo。内核由客户端软件决定，不由订阅 URL 决定；订阅只传节点、规则、代理组等配置。截图不能证明 iGuge 服务端/节点侧内核。`V2rayN` 是客户端 GUI，按兼容配置调用可用的 Xray/sing-box 核心，不是协议。选型和证据分层见 [iGuge 内核与节点稳定性研究](igugu-kernel-and-node-stability-research.md)；此项不再作为待评估任务。
- **节点稳定性研究（2026-11-21，研究结论已出）**：当前代码/验收证明限于隔离测试 Agent 和少量指定网络；production Agent 未开放。测试 TTL 会终止隔离实例、健康记录 300 秒过期会让线路节点不可选、AWS STS 过期阻断管理 API，这些影响层次不同，不应混作“生产隧道断线”根因。协议被识别封锁、长时容量不足及运营商质量目前均属待验证假设。证据、优先级和离线/真实节点改进项见上述研究文档；没有宣称节点已稳定或已上线。
- **会员沙箱与两台真机前置（2026-11-21）**：local/testing 已有可自给测试会员、有效年度权益、`paid_test` 种子订单及建单/模拟付款/模拟退款撤权流程；隔离会员会话不再是外部等待项，但这些状态不代表真实支付验收。用户提供的实机记录为 HONOR ALT-AN00 与 HUAWEI VOG-AL10 登录同一沙箱账号、两条 installation 记录；VOG-AL10 已绑定 P-256 公钥，ALT-AN00 为旧记录且 `public_key` / `bound_at` 为空。因此双设备身份复制拒绝前置尚不完整，必须先让 A 重新完成 challenge 签名绑定；两台线路当前均因没有真实/隔离节点而显示不可用。设备私钥由每台客户端在本地安全区生成，不向用户索要或由服务器下发。
- **M6/AWS 当前状态（2026-11-21）**：`ops/aws-cli sts get-caller-identity --profile xlb-ops` 本轮返回 `arn:aws:iam::289069331089:root`。最近一次资源逐项只读核验记录（2026-11-20）为旧节点栈、IAM 栈及关联资源已不存在/已回收；本轮未重新查询资源，不能将该历史结果说成今日复核。用户已批准最多 60 分钟、us-west-2 单台 t3.small、预算上限 `$1` 的隔离窗口；写操作前仍须按验收计划重新只读盘点、审查当前 plan 并确认资源标签/TTL。M6 云端证据尚未通过。
- **M5/M6 合成 peer 隔离节点复测（2026-11-21）**：按验收计划先只读确认旧节点/IAM 栈、角色、profile、安全组、Scheduler、实验标签实例/卷均无资源；复核模板和 IAM 标签策略模拟后，创建专用实验 IAM 栈及单台 `us-west-2 t3.small`、TTL 60 分钟节点，安全组无入站且仅 TCP 443 出站。云端 live harness 通过：12 个独立进程争 3 个容量槽位恰好成功 3 个；节点确认容量有空余时，复制身份的同时及错峰请求都返回 HTTP 403；第二个独立合成 peer 正常连接；撤权返回匹配的 `confirmed_test` 回执和非零最终双向流量，撤权后旧身份重连 403。按计划删除节点栈及实验 IAM 栈；EC2 已终止，卷、ENI、安全组、Scheduler、公网地址及 IAM 角色/profile 均已核查无残留。此次证明限定于隔离节点和 harness 自动生成的两个合成 P-256 peer，不是会员购买/退款链路、真机双身份验收、生产节点或生产断流。测试发现 Agent HTTP server idle timeout 为 35 秒而 live harness 原等待 15 秒会误报未释放；已把归零等待扩为 1 分钟后重跑通过。完整证据见 [M5/M6 隔离节点计划](m5-m6-isolated-node-acceptance-plan.md)。
- **支付适配器契约离线阶段（2026-11-21）**：新增 `PaymentProvider` 与规范化商户订单、渠道交易事件、支付 session 和对账分页类型；`SignedSandboxPaymentProvider` 仅驻留进程内，覆盖 HMAC 验签、5 分钟签名时间窗、商户订单/渠道交易号/金额/币种关联、主动查单及事件分页读取。独立事件决策器对重复事件幂等，对超时付款、金额/币种不符及付款前退款送待核验。定向 Unit/Feature 共 17 项、163 条断言通过；完整 Laravel 套件 182 项中 181 项通过、1 项按既有条件跳过，共 1,627 条断言（测试数据库为内存 SQLite）。现有 local/testing 订单、权益发放和退款状态机仍由既有沙箱服务执行；尚未接入 webhook 路由、持久化 provider event ledger、失败后重试发放/补偿流水或任何真实渠道，也没有商户凭据。此阶段只证明可替换契约与测试模型，不代表真实支付/退款链路验收。
- **支付事件状态机内存参考实现（2026-11-21）**：新增 `PaymentEventProcessor`、`PaymentOrderAggregate` 与 `InMemoryPaymentEventStore` 作为 provider-neutral 的可替换参考模型：同一渠道事件只记账一次，成功付款最多发放一次，只有已发放订单可进入退款补偿；付款前退款、超时付款、未知订单、订单/金额不符进入 review，不自动生效。支付 Unit/Feature 定向测试 21 项、180 条断言通过；完整 Laravel 套件 186 项中 185 项通过、1 项既有条件跳过，共 1,643 条断言。该内存 store 不是生产持久化账本，状态机也未接入 HTTP webhook、重试队列或真实节点撤权回执；退款模拟不代表真实资金退款。
- **支付 provider event 持久化账本离线阶段（2026-11-21）**：新增 local/testing 专用 `PersistentPaymentEventLedger` 和 SQLite 表，按 provider/event ID 唯一记录规范化事件事实（不存原始回调、签名或凭据）；重复事件不重复推进，重复键承载冲突事实则 review。付款先进入 `paid_pending_grant` 并以商户订单唯一键写 fulfillment outbox；失败可重试，只有收到 fulfillment 成功结果后账本才记录权益已发放。退款只接受已确认付款，建立唯一 compensation outbox，失败可重试且成功仅补偿一次。定向 Feature 4 项、27 条断言通过；完整 Laravel 套件 190 项中 189 项通过、1 项既有条件跳过，共 1,670 条断言。当前只是可测试的持久化账本和 worker 结果接口，未接 webhook/API/queue，未实际消费 outbox 写入 `entitlements`/退款账本，未接真实渠道；不代表支付或权益/退款链路验收。
- **local/testing 支付回调与权益发放 worker 阶段（2026-11-21）**：新增 sandbox provider session、HMAC-SHA256 回调验签（300 秒时间窗、订单/渠道交易号/金额/币种核对、64 KiB 请求上限），仅 `local/testing` webhook 路由；新增 `payments:process-sandbox-fulfillments` 命令消费唯一 fulfillment outbox，在一个数据库事务内创建 `granted_test` 权益流水、更新 `paid_test` 订单、写审计及幂等订阅凭证。回调重复不重复入账，签名或时间窗错误不入账，worker 失败保留 outbox 并可重试。定向 Feature 8 项、61 条断言通过；完整 Laravel 套件 194 项中 193 项通过、1 项既有条件跳过，共 1,704 条断言。真实 webhook/商户仍未连接；provider refund compensation 尚无执行 worker，也未真实资金退款；local/testing 结果不等于真实支付或退款验收。
- **provider refund compensation 沙箱 worker 阶段（2026-11-21）**：新增 `payments:process-sandbox-refunds` 消费 provider refund outbox，并调用既有 local/testing `SandboxRefundProcessor` 完成该订单的全额模拟退款、测试权益/发放撤销、佣金冲正、审计及需要时的节点撤权排队；结果再幂等写回 provider compensation ledger。定向 Feature 8 项、71 条断言通过；完整 Laravel 套件 194 项中 193 项通过、1 项既有条件跳过，共 1,714 条断言。此测试模型只接受订单总额一致的沙箱退款，不解决部分退款/剩余时长折算业务规则，不执行真实资金退款；节点 outbox 待匹配回执期间仍不得宣称已断流。
- **sandbox event 对账差异报告（2026-11-21）**：新增 `PaymentReconciliationService`，分页读取注入 provider 的 event page，与持久事件事实比较并报告 `matched`、`missing_local`、`missing_provider`、`mismatch`；此步骤为只读报告，不会自动发放权益、退款或改订单状态。支付定向 Feature 测试 10 项、81 条断言通过；完整 Laravel 套件 196 项中 195 项通过、1 项既有条件跳过，共 1,724 条断言。对账覆盖本地假渠道，不是任何真实商户对账结果。
- **M10 节点路由策略离线模拟（2026-11-21）**：新增未接入运行链路的 `NodeRoutingPolicy` 原型：拒绝不健康/过期（TTL 默认 30 秒）/不可用节点与负载达到 70% 的候选；其余候选按配置权重、容量余量、延迟、丢包与 CPU headroom 计算权重，以稳定 affinity key 做 weighted rendezvous 排序。4 项纯单元测试、5 条断言通过；1:3 权重在 4,096 个 affinity key 的模拟分配误差小于 2%。完整 Laravel 套件 200 项中 199 项通过、1 项既有条件跳过，共 1,729 条断言。这是算法模拟，不会改变现有 `SubscriptionRouteService` 结果，也未验证真实多节点容量或线路质量。D3 尚未拍板，严格设备线路仍不得自动切换或放宽绑定。
- **当前外部依赖与放行门（本轮真机复核）**：ALT-AN00 的 `public_key` / `bound_at` 仍为空；重启 App 后回到登录页，本地 PHP API 曾退出，恢复 API 后本轮 UI 登录没有产生新的 challenge 记录，因此没有完成重绑定。当前 ADB 列表只有 ALT-AN00，VOG-AL10 未连接，不能做双真机复制测试。需要在测试手机上恢复 member 沙箱会话并让 `synchronize()` 完成 challenge 签名授权；不清 App 数据、不移除设备、不暴露测试密码。生产节点专用 mTLS 材料、Android/Windows/macOS 安全区适配与实际目标环境接入、iOS 验收范围仍未完成。真实商户支付凭据/回调契约未提供，但不阻塞 provider-neutral 支付契约、假渠道及 local/testing 购买退款离线验收。严格设备限制保持不变；合成 peer harness 回执不等于真实会员 peer 或生产断流；没有匹配节点撤权回执不得宣称已断流或精确限设备；不宣称可发布。

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
- **下一步（2026-11-20 历史状态）**：该日的资源复核和“等待隔离窗口”建议保留为历史记录；2026-11-21 隔离窗口已获用户批准，但每次云端写入前仍要重新只读盘点并审查 plan。当前 M6 双身份前置缺 ALT-AN00 的 P-256 公钥绑定，生产 renderer/mTLS/平台安全区和 iOS 放行仍未完成。
- **M6/AWS 外部状态（最近只读核验：2026-11-20）**：该日核验的两个实验栈及列明资源均不存在/已回收，详见资源回收记录；本轮没有再次查询这些资源，不将历史结果表述为今日复核。2026-11-21 STS 实测成功，用户已批准的隔离窗口执行前仍须重新只读盘点并审查 plan。该状态不表示 M6 通过或账单最终结清。

- **本轮离线实现（M4 凭证与权益交付）**：设备绑定授权在验证一次性设备签名后，将验证所得公钥指纹仅作为内部参数传给 provisioning 服务；服务在返回 `ready` 前重新核对设备仍登记且未申请移除、peer 所绑定的具体 entitlement 仍有效且 peer 未过期、请求指纹与设备/peer 指纹一致、设备绑定代数一致、节点为新鲜健康的非测试节点，且 peer 已有匹配确认回执和加密 profile。符合条件时才返回 peer/node 身份、代数、peer credential 和 profile；其他状态不含凭证，响应为 `no-store, private`。Provisioning 与第三方 subscription-binding worker 在远端调用前后都校验 peer 的具体 entitlement 与到期时间；权益中途变化时迟到回执标记 superseded 并排队撤权。订阅签发和 profile 拉取也限制为 peer 自身权益有效，账户续费到另一条 entitlement 不会复活旧 peer/旧链接。
- **本轮验证与实现边界**：新增负向回归覆盖未确认、错误设备证明、peer 指纹错配、设备代数过期、缺少有效 peer entitlement、peer 过期/撤权、权益在 provisioning/binding 期间变化，以及旧权益 peer 不再签发/返回 profile。完整 Laravel 套件 163 项、162 项通过、1 项按既有条件跳过、1,497 条断言通过；Pint、`git diff --check` 和本轮涉及文件的空白扫描通过。驱动回执由本地测试 mock 提供，不是云节点会员线路。隔离 Go Agent 仍仅返回 `confirmed_test` 且无 profile，provision processor 继续失败关闭，因此没有可向真实会员交付的生产 peer credential/profile；不宣称 M4 生产闭环或发布就绪。
- **剩余 M4 生产差距**：跨节点共享套餐额度已有 Laravel 原子 reservation/settlement 核心和 Go byte-lease client/state machine 的离线实现，但 Laravel 反向 mTLS API 与生产数据面注入未接通，生产节点仍有快照对账窗口。订阅轮换 outbox 有稳定代数、租约重试、旧 URL 立即失效及匹配回执后才开放新 URL 的本地测试；profile renderer 与平台安全区 bridge 包仍未实现。不能用假 profile 或 test Agent 绕过这些缺口。
- **剩余会员/生产验收依赖（2026-11-20 历史记录）**：local/testing 会员会话和购买/退款可使用仓库内沙箱，不需用户另供会话；2026-11-21 实测两台安装均已登录，但设备 A 的旧登记仍无 P-256 公钥/`bound_at`，设备 B 已绑定，因此不能把两台均称为 proof-capable。需要通过已授权开发设备流程重新绑定 A，并在获批隔离窗口复测；生产 mTLS、平台安全区适配和 iOS 验收范围仍待完成。
- **本轮安全红线**：严格限制设备数；复制身份必须拒绝；无节点撤权回执不宣称已断流或已精确限设备；不把 sandbox、账号级凭证或管理员线路当作会员线路；不宣称可以发布；CDN 只承载安装包和静态资源，不承载 VPN 数据通道。
- **安全红线**：严格限制设备数；复制身份必须拒绝；无节点撤权回执不宣称已断流或已精确限制设备；不把 sandbox、账号级凭证或管理员线路当作会员线路；不宣称可以发布；CDN 只承载安装包和静态资源，不承载 VPN 数据通道。

- **本轮离线实现**：修正 Laravel Agent HTTP client 对 Go `{"data": ...}` 响应 envelope 的解包，新增 POST/GET 响应契约测试；在 Agent 撤权等待集合中保留 HTTP 连接已关闭但流量尚未结算的活跃流，只有流结束并累计最终字节后才返回撤权回执；拒绝超出 PHP 整数范围的双向流量增量，避免账单水位溢出。第三方订阅页新增“节点确认中/暂不可用”状态；绑定确认前不显示空二维码或复制入口，轮换后旧 URL 立即失效，新代 URL 仅在匹配回执后显示。新增旧代绑定回执与新代轮换竞态测试、绑定 HTTP payload 测试、流量溢出回归测试及连接关闭后最终计量测试。
- **本轮验证**：Laravel 定向测试 30 项、311 条断言通过；Go Agent `go test ./...` 与 `go test -race ./...` 各 25 项通过，`go vet ./...`、`go build ./...` 通过；Web `pnpm run build` 通过；`vendor/bin/pint --dirty --format agent` 执行完成；`git diff --check` 通过。工作区没有提交或推送。
- **M4 历史状态与剩余差距**：Laravel 设备绑定、逐设备凭证、订阅绑定/轮换 outbox、mTLS Agent HTTP client、用量读取及撤权最终计量控制面均已有本地实现与测试；Agent data plane 的 peer/node/device/subscription 代数校验、复制 peer 身份后拒绝、容量原子预占和流量最终回执也有本地隔离测试。当前共享流量额度 reservation 与本机 HTTP mTLS client 已补齐离线核心，生产反向 API、目标数据库并发与真实节点接线仍未验收。生产闭环仍未完成：真实 Mihomo/自有 App profile renderer、bridge 凭证安全交付与生产 mTLS 配置缺失。隔离文件证书不是硬件保护密钥，sandbox profile 与本地 Agent 都不是会员线路或生产验收。
- **M5/M6 实测状态（历史更新，当前前置见本节顶部）**：实验节点上 M5 的 12 进程争 3 名额通过；早期 M6 同时/错峰复制请求的拒绝无法归因身份校验，A 重连失败且未执行撤权/最终流量。2026-11-21 用户提供的真机实测补充为：设备 B 已绑定 P-256 公钥，设备 A 仍为空公钥，因此双 proof 身份复制测试仍未形成完整证据。新隔离窗口已获批，云端写入前按计划重新盘点并核对 plan。
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
- **M5/M6 云端验收状态（历史，最近补充见本节顶部）**：实验节点上 M5 的 12 进程争 3 名额通过；早期 M6 同时/错峰复制拒绝无法归因身份校验，A 重连失败且未执行撤权/最终流量。12:23 两台真机沙箱登录实测补充了设备事实，但设备 A 尚未绑定公钥；不得将其当成双身份 M6 通过。隔离节点协议测试仍不能替代真实会员线路或生产验收。
- **待用户/外部提供清单（当前状态）**：1) 无需用户提供有效 local/testing 会员会话；种子会员与模拟购买/退款可离线复现。2) 双设备 proof 前置尚缺 ALT-AN00 重新登记并签署一次性 P-256 challenge；私钥应在设备安全区本地产生，不向用户索取。3) 生产专用 mTLS client cert/key/CA 的受控注入渠道（不得进入仓库或聊天；隔离测试可用临时 test CA）。4) iOS 验收范围、真机和代码签名条件。5) 若实际验收公网 VPN 数据面，使用隔离计划里的既有网络边界；扩展入站规则须单独审批。AWS 隔离资源范围和 `$1` 预算已获批准，但节点栈不存在与否需写入前重新只读核实。
- **下一步**：先恢复同一 AWS 身份并核实、清理已到期实验栈及残留资源；之后定位 M6 重连失败，在重新获准的隔离窗口完成撤权回执和最终流量测试。随后修补 Laravel subscription bind/rotate outbox 与真实回执持久化；M6 会员链路仍待隔离有效会员会话及合规设备身份。普通 Clash/Mihomo 不得被标记为严格限设备已支持。
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
