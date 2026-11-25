# M5/M6 隔离节点验收计划

- **AWS 资源只读盘点（2026-11-25，us-west-2，获批隔离实验标签范围）**：`ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 root principal。`cloudformation list-stacks` 显示所有 `xlb-lab-*` 与 `xlb-auto-*` 历史栈为 `DELETE_COMPLETE`，仅既有 `xlb-node-manager-access-usw2` 为 `CREATE_COMPLETE`。按 `ManagedBy=xlb-node-lab` 筛选 EC2、EBS、ENI、安全组、公网地址均为空；`xlb-lab-*` Scheduler 为空；`XlbLab*` IAM role/instance profile 为空；带 `ManagedBy` 标签的 SSM 托管实例数为 0。全部经项目包装器只读查询，没有创建、删除、延长 TTL 或修改云资源。该结果仅覆盖所列 XLB 实验资源筛选范围，不是全账号账单盘点。由于 11-21 M5/M6 synthetic live harness 已通过且没有新的协议变更，本轮未生成或执行云端 plan；真实 member profile、节点 endpoint/身份、生产用途 mTLS 材料与匹配回执仍未提供。
- **较早 AWS 查询快照（2026-11-25）**：用户告知已重新 `aws login` 后，项目包装器的 STS 请求返回 root 身份，但紧接着的 CloudFormation、EC2、EBS、ENI、安全组、Scheduler 与 SSM 查询报 `session expired`。该较早错误在同日后续复核未重现；后续的有界盘点结果见本文件第一条。此较早查询期间没有创建、删除、延长 TTL 或改动任何云资源。

- **当前 AWS 会话与资源状态（2026-11-24，只读复核）**：项目 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 `arn:aws:iam::289069331089:root`，当前会话有效。us-west-2 活动 XLB CloudFormation 栈只有既有 `xlb-node-manager-access-usw2`；历史 `xlb-lab-*` 栈均为 `DELETE_COMPLETE`；按 `ManagedBy=xlb-node-lab` 查询的 EC2、EBS、ENI、安全组、公网地址、Scheduler 均无结果，SSM 托管实例查询为空。只读，无资源写入。11-21 合成 peer live harness 已通过，之后 peer-store/data-plane 没有协议变更，未为重复验收重建资源；真实 M6 member profile、节点 endpoint/身份及可注入 mTLS 材料仍未提供。没有匹配节点回执时不宣称会员数据面断流。

- **历史只读复核（截至 2026-11-23）**：当时 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回获批账号身份。us-west-2 活动 XLB CloudFormation 栈只有既有 `xlb-node-manager-access-usw2`，状态 `CREATE_COMPLETE`；精确查询 `xlb-lab-us-20261121-m5m6` 和 `xlb-lab-access-usw2` 均为不存在。`ManagedBy=xlb-node-lab` 标签下无 EC2/EBS/ENI/安全组/EIP/Scheduler；SSM managed instances 为空；`XlbLab*` IAM role/instance profile 为空；历史安全组 `sg-0db2cef785cbeeba3` 不存在。当时只读，没有 apply、创建、删除、延长 TTL 或其他云写入。该快照不证明当前资源状态。

- **历史只读复核（2026-11-22，用户重新 `aws login` 后）**：经项目 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 验证获批账号会话有效。`us-west-2` 仅有既有 `xlb-node-manager-access-usw2` 处于 `CREATE_COMPLETE`；所有 `xlb-lab-*` CloudFormation 历史栈为 `DELETE_COMPLETE`。只读查询确认无 `ManagedBy=xlb-node-lab` EC2、EBS、ENI、安全组、公网地址或 Scheduler，无 SSM managed instance，无 `XlbLab*` IAM role/instance profile；历史安全组 `sg-0db2cef785cbeeba3` 不存在。默认 VPC `vpc-00ded9986e9e37539` 的 us-west-2a 默认子网 `subnet-01b72ddfdcc197d20` 可用且经 main route table/IGW 出网；当时 AL2023 x86_64 最新 SSM 参数解析为 `ami-09f6e62a9fe578f92`（Owner `137112412989`、`available`）。当时没有执行 `--apply`：11-21 M5/M6 synthetic live harness 已通过，之后 peer-store/data-plane 文件无改动。该快照不证明当前资源状态，也不授权扩大公网入站或接入真实会员隧道。

日期：2026-11-19
状态（2026-11-20 更新）：原计划范围与 `$1` 预算上限已获批；实验 IAM 栈 `xlb-lab-access-usw2` 和单台 `t3.small` 节点栈 `xlb-lab-us-20261119-m5m6` 曾创建。M5 的 12 进程争 3 名额通过；旧 M6 证据不满足身份拒绝归因、有效 peer 重连及撤权最终回执等放行标准。清除 HTTP/HTTPS/ALL proxy 环境变量并设置 `NO_PROXY=*` 后，`ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 `arn:aws:iam::289069331089:root`。只读盘点显示节点栈仍为 `CREATE_COMPLETE`，其 EC2 实例 ID 仍列在栈资源中，但 EC2 `describe-instances` 无返回；相关 EBS 卷和 ENI 查询无结果；安全组 `sg-0db2cef785cbeeba3` 仍存在，且到期 Scheduler `xlb-lab-us-20261119-m5m6-expiry` 仍为 ENABLED，计划时间已过。实验 IAM 栈仍为 `CREATE_COMPLETE`，`XlbLabSsmRole`、`XlbLabExpiryRole` 与 `XlbLabSsmProfile` 均存在。因此节点资源栈和实验权限栈尚未完整回收。此次仅做只读查询，没有创建、删除或延长任何资源，未取得 M6 四项新证据。以下旧状态说明均为历史记录，当前结论以本段为准。

**最新复测（2026-11-21）**：按获批范围新建实验 IAM 栈与独立节点栈 `xlb-lab-us-20261121-m5m6`，单台 `us-west-2 t3.small`、60 分钟 TTL、预算上限 `$1`；EC2 两项状态检查转为 `ok`、SSM `Online`，安全组确认无入站且仅 TCP 443 出站。隔离 live harness 实测通过：12 个进程争 3 槽位接受数恰为 3；在活动容量有空余时，复制身份同时及错峰连接均由节点直接返回 `403`；另一独立合成 peer 可用；匹配撤权请求得到 `confirmed_test` 回执及非零最终上传/下载字节，撤权后原身份重连 `403`。这些是自动生成的两个不同 P-256 私钥合成 peer，不是两台会员真机或生产节点证据。测试结束后节点栈和实验 IAM 栈均已删除；节点为 `terminated`，卷、ENI、安全组、Scheduler、公网地址及实验 IAM 角色/profile 均只读核验不存在。本次仅为获批范围内创建/删除，无额外资源、入站规则或 TTL 延期。Harness 的空闲会话归零等待已从 15 秒调整为 1 分钟，以超过 Agent 35 秒 HTTP idle timeout；改后 live harness 通过。

本地计划复核：2026-11-19。`apps/node-agent` 已有离线 Go peer-store 核心和多进程容量测试（12 个进程争 3 个槽位）；本复核没有重复 AWS 只读盘点。

本地 harness 校准：隔离 Agent 新增 mTLS `GET /v1/test/status`，只暴露活动会话数与容量；M5 后等待活动会话归零，身份拒绝前确认有空位，并直接检查节点返回 `403`。本地测试通过，修改后尚未云端复验。

## 现状与可推进范围

- 用户本轮提供的 AWS 只读结果：`xlb-ops` root 身份为 `arn:aws:iam::289069331089:root`，EC2/SSM/CloudFormation/IAM/VPC 盘点权限通过；当前无 EC2 实例、无 SSM 托管实例；`xlb-node-manager-access-usw2` 为 `CREATE_COMPLETE`，`XlbNodeSsmRole` 与 `XlbNodeCleanupRole` 已存在；默认 VPC 为 `vpc-00ded9986e9e37539`。本轮未重复调用 AWS，也未执行任何云端写操作。此前记录的默认子网、路由及 AMI 信息仍须在获批执行前重新核对。
- **登录前会话状态（历史，2026-11-22）**：本轮早些时候 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 返回 session expired；用户随后本人重新 `aws login`。当前登录后身份及资源状态以本文顶部“当前只读复核”为准。
- **登录前会话复核（历史，2026-11-22）**：此前的 session-expired 结果不代表重新登录后的状态；已在用户重新认证后重新只读盘点，未执行云写入。
- 已在执行前重新查询并核对 Amazon Linux 2023 x86_64 AMI：`ami-062e367aff5bc494b`，Amazon 发布、状态 `available`、创建于 2026-11-18，us-west-2。本次模板强制 IMDSv2；该 AMI ID 仅用于本轮实验，后续部署前仍需重新核对。
- **现有访问栈不能直接供 `ops/node_lab.py` 使用**：该脚本和 `ops/aws-node-lab.template.json` 要求 `XlbLabSsmProfile` / `XlbLabExpiryRole`，并将资源标记 `ManagedBy=xlb-node-lab`；现有 `XlbNode*` 角色和策略按 `xlb-node-manager` 标签授权。不要通过改标签、扩大现有角色权限或更新既有访问栈来绕过差异。
- **M5 本地与云端数据面**：本地 Agent 增加 loopback-only TLS 1.3/HTTP2 数据面与本机 SOCKS5 桥；设备证书身份绑定 node/peer/device/subscription 代数，节点容量原子预占，撤权关闭流并等待最终用量回执。离线测试及云端隔离节点 12 进程争 3 名额均通过。云端节点只运行 `isolated-test`，不是 Mihomo/TUN 或会员生产 Agent。
- **M6 跨设备凭证复用**：普通 Clash/Mihomo 不签署设备 challenge。本轮云端 harness 使用两个不同密钥的合成 peer；复制身份同时和错峰请求均观察到拒绝，但因未证明拒绝时节点有空余容量，不能据此证明身份校验。Harness 在 A peer 重连时收到 SOCKS reply `5` 并提前退出，未测试撤权回执、最终流量或撤权后拒连；需先让 M5 会话释放可观测，再重跑隔离验收。没有有效会员会话或两台独立真机，不能称会员链路验收。
- **实验 Agent 部署边界**：Linux/amd64 Agent 一次性构建包和 test mTLS 证书已准备，使用 SHA-256 `76967f3b8a5ff281f335ea5776137fcfdd4671df06649c77a867a199d8b9c46c`。包仅用于隔离节点，含临时服务端私钥，须经 SSM 回环端口转发传输并在测试后删除。Agent 只返回 `confirmed_test`，不生成会员 profile；Laravel 也尚无 subscription bind/rotate driver/outbox，不能接会员流量或称作生产 Agent。
- **生命周期顺序约束**：现有控制面先开通设备 peer；只有 peer 激活后，`SubscriptionCredentialService::ensureForDevice` 才创建/轮换第三方订阅 URL。因此初次 peer provisioning 不应依赖尚未存在的 subscription ID/token。建议 Agent `ensure peer` 只绑定设备身份、peer credential 和 device/peer generation；随后由独立幂等的 `bind/rotate subscription` 操作绑定 subscription ID、token hash 与 credential generation。自有 App 的 device proof 与第三方订阅 proof 也要分开定义，第三方普通客户端不能因拿到 URL 就绕过设备私钥校验。
- 当前 `ops/aws-node-lab.template.json` 只配置 EC2、无入站安全组、实例内到期 shutdown 和 Scheduler 终止，不会安装 Node Access Agent。获批创建节点也不能跳过 Agent 构建、校验摘要与通过 SSM 的受控部署步骤。

## 建议资源

| 资源 | 建议配置 | 用途与边界 |
| --- | --- | --- |
| 区域/VPC | `us-west-2` / `vpc-00ded9986e9e37539` / `subnet-01b72ddfdcc197d20` | 使用现有默认 VPC 的公网子网，不改 VPC、路由表或网关 |
| EC2 | 1 台 `t3.small`，Amazon Linux 2023 x86_64，标准 CPU 积分 | 2 vCPU、2 GiB；为 Agent 与短时并发测试留出比 `t3.micro` 更合理的内存余量 |
| 根盘 | 12 GiB 加密 gp3，实例终止时删除 | 只放临时 Agent 与测试状态；不存生产数据 |
| 安全组 | 新建 1 个；入站规则为空；出站仅 TCP 443 到公网 | SSM、固定来源下载和受控 HTTPS 测试；不开放 SSH 或 VPN 公网端口 |
| 公网 IPv4 | 使用子网自动分配的临时地址，不申请 EIP | SSM Agent 主动连接 AWS；按小时计费，删实例后释放 |
| 到期调度 | 1 个 EventBridge Scheduler 定时 `TerminateInstances` | 计划 60 分钟到期，另有实例内 shutdown→terminate 兜底；调度可能延迟，不是账单硬上限 |
| 实验权限栈 | `xlb-lab-access-usw2`：2 个专用 IAM Role + 1 个 Instance Profile | 与 `xlb-node-manager-access-usw2` 隔离；验收结束后在无节点依赖时删除 |
| 节点栈 | `xlb-lab-us-20261119-m5m6`（创建时再保证唯一） | 仅创建实验 EC2、安全组和 Scheduler；CloudFormation 失败时 `OnFailure=DELETE` |

建议标签按现有 `node_lab.py` 固定值执行：`Project=xlb`、`Environment=test`、`ManagedBy=xlb-node-lab`。实例另带 `NodeId=xlb-lab-us-20261119-m5m6` 与 `ExpiresAt=<UTC>`；根卷继承实验标签。禁止给生产栈或 pd01/pd02 添加这些标签。

本计划采用 `t3.small`。若本地 Agent/客户端负载测试表明 `t3.micro` 足够，可在申请执行前调整规格；不得在测试中临时升配或追加节点而不重新确认费用和资源范围。

## 费用估算

以下是预算估算，不是 AWS 账单报价；按 us-west-2 Linux On-Demand 的常见公开基准估算，创建当天应重新核价：

| 计费项 | 估算单价 | 60 分钟估算 |
| --- | ---: | ---: |
| `t3.small` EC2 | 约 `$0.0208/小时` | `$0.0208` |
| 自动分配公网 IPv4 | 约 `$0.005/小时` | `$0.0050` |
| 加密 gp3 根盘 12 GiB | 约 `$0.08/GB-月` | 约 `$0.0013` |
| Scheduler 单次到期调用 | 约 `$1/百万次调用` | 低于 `$0.00001` |
| CloudFormation、基础 SSM 管理 | 无单独栈创建费；标准 SSM 层通常无节点附加费 | `$0`（不含可选高级层/日志） |
| Internet 数据传输 | 依账户聚合免费额度、目的地与实际字节计费 | 不作固定承诺 |

合计约 `$0.027/小时`，60 分钟约 `$0.03`，未含税、数据传输、可选日志和账单舍入。建议本次操作预算上限按 `$1` 预留；这只是人工预算，不是 AWS 硬支出封顶。保持请求极小，不跑带宽压力或长时间下载。官方价格页：[EC2](https://aws.amazon.com/ec2/pricing/on-demand/)、[VPC IPv4](https://aws.amazon.com/vpc/pricing/)、[EBS](https://aws.amazon.com/ebs/pricing/)、[EventBridge](https://aws.amazon.com/eventbridge/pricing/)、[Systems Manager](https://aws.amazon.com/systems-manager/pricing/)；账单可能延迟，TTL 仍是主要资源成本保护。

## IAM 与 `CAPABILITY_NAMED_IAM` 审批范围

`CAPABILITY_NAMED_IAM` 是 CloudFormation 对“模板将创建具名 IAM 资源”的显式确认参数，不会自行授予 IAM 权限。

1. 仅在单独创建 `xlb-lab-access-usw2` 时传入 `CAPABILITY_NAMED_IAM`。该栈仅创建 `XlbLabSsmRole`、`XlbLabSsmProfile`、`XlbLabExpiryRole`；不更新/覆盖现有 `xlb-node-manager-access-usw2`，不创建用户、访问密钥、生产角色或管理员策略。
2. 操作身份需要对实验权限栈精确名称拥有 CloudFormation 创建、读取和删除权限；IAM 管理动作应约束到上述两个具名 Role 和一个 Instance Profile，包括创建/标记角色、附加指定 `AmazonSSMManagedInstanceCore`、写入模板内到期角色策略、创建/关联实例配置文件。
3. `iam:PassRole` 仅允许把 `XlbLabSsmRole` 交给 `ec2.amazonaws.com`，以及把 `XlbLabExpiryRole` 交给 `scheduler.amazonaws.com`。到期 Role 仅能在 `us-west-2` 终止同时具有 `Project=xlb`、`Environment=test`、`ManagedBy=xlb-node-lab` 标签的实例；信任策略限定本账户和 default schedule group。
4. 节点栈 `xlb-lab-us-*` 不包含 IAM 资源，不传 `CAPABILITY_NAMED_IAM`；其操作权限只覆盖该实验栈、实验标签资源、实验日志/调度读取及上述精确 `PassRole`。不得用 `*` 扩展到 `XlbNode*`、生产资源或其他区域。
5. `XlbLabSsmRole` 附加的 AWS 托管 SSM 策略内部存在较宽的资源范围；本方案只接受它用于临时实验实例，不将其描述为逐资源最小权限。权限边界和 SCP 需允许上述精确动作。创建前先做 IAM policy simulation/CloudFormation validate；验证失败就停，不扩大范围。
6. 用户已批准上述精确实验 IAM 栈、创建 1 台 `t3.small` 最长 60 分钟的节点栈、预算预留 `$1`、测试后删除节点栈并核查残留，以及删除实验 IAM 栈。执行前仍须完成 Agent 数据面实现与清单；未通过检查前不执行 AWS 写操作。

## 建设与验收步骤

### A. 节点创建前，不产生云费用

1. 先补齐 Agent wire contract：统一 Laravel ensure/revoke payload；peer provisioning 与后续 subscription bind/rotate 的分离契约；subscription、peer、device generation 的含义；challenge/authorization 请求响应、幂等键、超时重试及数据面提交/撤回语义。未经授权的连接不能进入代理数据面。
2. 已实现 loopback-only TLS 1.3/HTTP2 TCP 数据面、逐设备证书 peer 校验、节点会话容量预占、流关闭与最终流量计量，并通过本地 race/集成测试；本轮云节点只用于隔离实测，不构成生产故障恢复或控制面闭环验收。
3. 隔离 test CA 与 Agent 服务端/管理端证书已在仓库外生成，Linux/amd64 Agent 包 SHA-256 已固定。临时包经 SSM 端口转发传输；私钥不进入命令参数、仓库或文档，结束后销毁。生产 mTLS 凭证仍待受控密钥渠道提供。
4. 准备两个互不共享私钥的 proof-capable 客户端身份和可复现测试 harness；准备脱敏测试数据。普通 Clash Verge/Mihomo 只可作为“不能证明设备身份”的负例，不能列为严格限设备验收通过。
5. 为 `aws-node-lab.template.json` 准备可审计的 SSM 部署包和 SHA-256；节点侧只在 `127.0.0.1` 监听 Agent 管理 API。若 M6 要验证真实 VPN 隧道，还需要另行批准最小来源 IP /32 的数据面入口变更；本计划的无入站节点只覆盖 SSM 转发的 Agent API。
6. 已核验节点模板、Amazon Linux 2023 AMI、SSH/公网入站关闭、TCP 443 egress、60 分钟双重 TTL、清理策略和隔离回收角色策略模拟。只有这些本地执行前检查通过后才创建获批 IAM/EC2 栈。

### B. 获批后创建并验证 SSM 通道

1. 已重新只读确认实验权限栈/角色名不存在冲突，目标 us-west-2a 子网经 IGW 出网，Amazon Linux 2023 x86_64 AMI 为 available；两模板 `validate-template` 通过。回收角色策略模拟：实验标签允许、错误 Project 标签隐式拒绝。
2. 先创建独立实验 IAM 栈，并传 `--capabilities CAPABILITY_NAMED_IAM`。确认 `CREATE_COMPLETE`、输出中的 Instance Profile/到期 Role ARN 与上方精确名称一致。
3. 生成全新的 UTC 到期时间，运行 `ops/node_lab.py create` 的默认 plan 并复核账号、区域、栈名、标签、子网、AMI、规格、角色、TTL。确认后才由用户明确授权的执行步骤加 `--apply` 创建 60 分钟节点栈。
4. 每 15 秒查询 EC2 状态与 SSM 管理状态，最多等 5 分钟；确认 EC2 checks 正常、SSM `PingStatus=Online`、实例和卷标签正确、安全组无入站且只有 TCP 443 出站。未在期限内就绪则立即删栈，不延长 TTL。
5. 用 `ops/aws-cli ssm start-session --target <instance-id>` 验证交互会话。此链路只通过 AWS SSM，不开 SSH，也不改本机系统代理。

### C. Agent mTLS 端口转发

Agent 只监听节点 `127.0.0.1:9443`。另开终端执行：

```bash
ops/aws-cli --profile xlb-ops --region us-west-2 ssm start-session \
  --target <instance-id> \
  --document-name AWS-StartPortForwardingSession \
  --parameters '{"portNumber":["9443"],"localPortNumber":["19443"]}'
```

在本机第二个终端通过 `https://127.0.0.1:19443/healthz` 使用隔离 test CA 和 client cert 验证 TLS、Agent 身份及健康状态；禁止 `curl -k`。若本机端口冲突，改用另一个仅本机监听的高端口。停止 session 后确认该端口不可达。所有请求使用稳定 ID，日志只记 request/node/peer ID 与状态，不打印凭证、私钥、profile 或订阅 URL。

### D. M5/M6 实测门

- **M5 并发原子性**：在 Agent 状态存储设置确定的节点容量，使用不同 peer 和签名 challenge 进行屏障同步并发开通（本轮配置为 12 个进程争 3 个名额）。验收成功数必须恰为 3；重复 request ID 只产生一个 peer；超时后用同 ID 重试得到原结果；Agent 多进程并发与重启后检查仍无超卖。保存脱敏 request/receipt 清单和持久状态计数。
- **M5→M6 隔离**：不得只等待客户端进程退出就假定节点槽位已释放。隔离 Agent 的 mTLS `GET /v1/test/status` 仅返回活动会话数与容量；harness 必须等 M5 后活动数归零，且在复制身份请求前确认仍有空余容量。只有节点返回 `403` 且活动数不增加才算 proof 拒绝；`409 capacity` 或 SOCKS 通用失败不算。该 endpoint 不返回 peer 信息，仅隔离测试服务提供。
- **本轮隔离状态探针**：Agent mTLS 管理端新增 `GET /v1/test/status`，仅返回 `active_sessions` 与 `capacity`，不返回 peer 标识。Live harness 使用它确认 M5 会话归零、复制身份请求前有空位，并检查节点直接返回 `403`；`409 capacity` 与 SOCKS 通用失败都不算身份拒绝。修改后本地测试通过，尚未云端重跑。
- **M6 同时复制**：通过隔离有效会员会话建立设备 A 的独立 peer/凭证。将 A 的订阅 URL 与 peer 凭证复制到身份 B，但用 B 的另一私钥、设备 ID 和 peer ID 发起连接；在确认有空余名额的条件下，服务端必须明确因 proof/peer 身份不匹配而拒绝，A 的会话仍按预期工作。
- **M6 错峰复制**：A 断开并确认槽位已释放后，B 再使用同一复制凭证；仍必须明确因身份不匹配而拒绝。随后撤权 A，先验证状态为 `revocation_pending` 且无“已断流”声明；提交匹配 node/peer/request/generation 的回执并核对最终流量单调，之后再次尝试必须拒绝。
- M6 真实会员链路需要隔离有效会员会话和可撤销设备；身份 B 必须由第二台设备或独立 proof-capable 客户端实例产生。单进程伪造、管理员演示线路、账号级订阅链接和 stock Clash 均不能替代这些证据。
- 通过 SSM/端口转发的 Agent 验收不等于公网 VPN 隧道、数据面并发限制、国内运营商质量、Android/iOS TUN、Windows/macOS 或第三方客户端完整验收。本计划当前不开放任何 VPN 公网入站。

### E. 到期与资源回收

`node_lab.py` 的 EventBridge Scheduler 到期动作是终止 EC2 实例，不是自动删除 CloudFormation 整栈；调度可能有分钟级延迟，不是精确的硬账单上限。实例内 shutdown→terminate 是第二道兜底；二者都不代替整栈清理。测试提前结束时立即运行该节点的 `node_lab.py delete` plan，核对节点栈标签和资源类型，再经获批步骤执行删除。

等待节点栈 `DELETE_COMPLETE`，逐项确认 EC2 已终止、gp3 根卷、ENI、安全组、Scheduler 不再存在，临时公网 IPv4 已释放；随后确认无其他 lab 节点依赖，再删除精确的 `xlb-lab-access-usw2` IAM 栈并核对两个实验 Role 与 Instance Profile 消失。CloudTrail 记录到期调用；账单延迟，不能声称费用已归零。任何释放检查失败都按未释放告警并继续核查，不能只把后台状态改成 released。

### 本轮实际回收异常

2026-11-19 02:58 UTC 前的最后一次成功盘点看到实例 `i-09e469b6e3501b495` 仍为 `running`，节点栈和实验 IAM 栈均为 `CREATE_COMPLETE`。TTL 过后于 03:06 UTC 尝试删除节点栈，`xlb-ops` 返回 `ExpiredToken`，AWS 未接受删除请求；当时未切换或轮换身份。该段仅保留故障发生时的历史证据。2026-11-21 已按本计划获批范围重新盘点、复测并完成独立栈回收，最新实测及无残留证据见本文顶部“最新复测”；不得将 11-19 的旧栈 ID 与本次栈混为一谈，也不声称账单最终结清。

## 尚需用户/外部提供

1. local/testing 隔离会员会话可由仓库沙箱自给，不再是外部依赖；真机会员链路要覆盖退款/撤权时仍需有效测试权益与线路，账号凭据不得进入报告或仓库。
2. 生产专用 mTLS client cert/key/CA 的受控密钥注入条件（不进仓库或聊天）；隔离节点使用一次性 test CA。
3. 两个当前 Android installation 的身份前置已于 2026-11-22 核实完成：ALT-AN00 与 VOG-AL10 各有不同 installation ID、registered 状态、P-256 公钥指纹和 `bound_at`；SQLite 共 4 条记录，较早两条保留，不需要第三台手机。未解决的是会员真机凭证复制拒绝、真实 member profile/线路/隧道及匹配撤权回执；当前两机均显示服务线路不可用。普通 Clash Verge 不满足挑战签名条件；云端 synthetic peer harness 的两个合成密钥只证明隔离协议拒绝复制身份，不替代会员实机数据面证据。
4. 若验收目标扩展到公网 VPN/TUN 或运营商质量，另行确认测试源 IP /32、协议端口和开放时限；本计划的 SSM-only 节点没有公网入站。
5. iOS 验收范围、真机和代码签名/分发条件。

2026-11-21 `ops/aws-cli sts get-caller-identity --profile xlb-ops` 和隔离节点实测为历史证据。2026-11-22 用户重新 `aws login` 后 wrapper STS 只读验证成功；当前 inventory 显示 lab 资源已回收、既有 node-manager access 栈保持不动，详见本文顶部。11-21 synthetic peer live harness 已通过，peer-store/data-plane 相关文件其后未改，本轮没有为重复验收重建栈。当前两机身份/公钥绑定已核实，但会员真机线路、凭证复制拒绝、隧道和匹配节点撤权回执仍未验收；生产 mTLS 凭证、安全区适配和 iOS 验收未完成。生产签发保持关闭；合成测试回执不代表真实会员节点撤权；未取得对应真实节点回执前不宣称断流或严格限制真实客户端设备，不宣称可以发布。
