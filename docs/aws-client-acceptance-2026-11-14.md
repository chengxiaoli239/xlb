# 美国节点与 macOS、Android 实机验收

本次日期为 2026-11-14，表内时间使用北京时间。仅测试用户已授权的独立美国节点；没有修改、重启或释放 pd01/pd02，没有部署生产后台或执行真实支付、退款。

## 环境和结果

| 项目 | 实际观测 |
| --- | --- |
| 临时节点 | us-west-2a，t3.micro，Amazon Linux 2023 x86_64，sing-box 1.14.4 |
| 资源身份 | `xlb-auto-09f9e8f0c920463fbd26e3bbc5f0debb`；实例 `i-0aca8a97128b6059c` |
| 网络边界 | 独立安全组，仅允许测试来源 IPv4 /32 访问 TCP 18443；出站仅 TCP 443；未开放 SSH、UDP，未申请 EIP、NAT 或负载均衡器 |
| 协议 | Shadowsocks 2022 / `2022-blake3-aes-256-gcm`，测试密钥由 Secrets Manager 生成，未写入本记录或 Git |
| macOS | 独立运行 Clash Verge 随附的 Mihomo Meta v1.19.33 darwin arm64；未接管现有 Clash Verge 配置、系统代理或 TUN |
| macOS 实流量 | checkip.amazonaws.com、example.com 均 HTTPS 200；出口与实例公网 IP 相同，正文匹配 |
| 模式和负例 | Rule 走测试节点；Global 选中测试组后走节点；Direct 出口与测试前直连基线一致；错误密钥不能取得响应 |
| Android | Honor ALT-AN00，Android 14；已安装包 `com.github.metacubex.clash.meta`，versionName `2.11.26.Meta`，界面显示名称为 Clash Verge |
| Android 实流量 | 新建独立测试配置；USB 本地通道导入后，手机客户端运行中。两次 HTTPS 经 USB 转发进入手机代理端口，再经手机网络到美国节点；出口与正文匹配 |
| Android 重连 | 停止、重新启动同一测试配置，再次取得正确出口；停止后代理请求失败，不会继续返回成功响应 |
| 手机恢复 | 原配置重新选中，VPN 恢复为测试前的停止状态；只删除本次测试配置，移除全部测试 USB 转发和临时导入服务 |
| 服务状态 | SSM 命令确认 sing-box 服务和主机到期 timer 均 active；服务内存快照约 9 MB，不能据此推断容量 |

Android 的测试对象是手机上已有的这个具体安装包；包名、版本号和显示名不能证明它来自官方发行渠道。本次没有验证 APK 来源或签名，也未取得它的内核精确版本，因此不将它等同于某个官方客户端的完整兼容认证。

## 创建和自动回收的真实时间

| 时间 | AWS 或实机证据 |
| --- | --- |
| 19:06:02 | 提交创建，预置 35 分钟云端到期调度 |
| 19:06:38 | CloudFormation CREATE_COMPLETE，约 36 秒 |
| 19:06:39 | SSM 查询到服务 ActiveEnterTimestamp，约 37 秒 |
| 19:07:16 | 首次轮询观察到公网 TCP 服务 ready，距提交约 74 秒；不代表精确的最早就绪时间 |
| 19:25:09 | 测试结束，将本次云端回收调度提前到该时间；保留原主机兜底时间 |
| 19:25:55 | CloudFormation DELETE_IN_PROGRESS，距调度时间约 46 秒 |
| 19:26:35 | 整栈 DELETE_COMPLETE，删除过程约 41 秒，距调度时间约 87 秒 |
| 19:28:23 | 执行器额外核对通过，返回 released |

上述为单次观测，不是上线或回收 SLA。EventBridge Scheduler 的触发存在分钟粒度延迟，后台不能承诺点击后立即释放全部资源。

回收时没有调用本地 `Manager.release()` 或本地定时任务：由已经部署的 AWS Scheduler 触发整栈删除。CloudTrail 已确认 19:25:55 的 DeleteStack 调用来自 `XlbNodeCleanupRole`，无错误码。CloudFormation 的 `User Initiated` 是通用事件原因，不能单凭该文案判断操作者。精确资源核对包含实例 terminated、卷 `vol-0a3352838bde34ade`、网卡 `eni-0cee648d600c8b83e`、安全组 `sg-0856f8f3d464c1c95`、该栈测试凭证和到期调度；上述附属资源均已不存在。运行状态保存在已忽略的 `ops/.state/`，不保存测试密钥。

## 本次修复和检查

实际核心测试发现：省略显式 GLOBAL 代理组时，Mihomo 的全局默认选项为 DIRECT，即使配置能够通过语法检查也可能不走节点。会员测试订阅和管理员临时节点配置现均显式声明 GLOBAL，并默认引用对应测试代理组；保留用户主动选择 Direct 模式的行为。

修改后，从 `Manager.profile()` 实际生成的配置启动独立核心并切到 Global，无需再手工设置代理选择，即取得美国节点出口。节点名称与 GLOBAL、主代理组或 DIRECT/REJECT 重名时，输出别名避让，防止代理图循环或覆盖内置策略。PHP 实际输出的 Clash/Mihomo、Rule/Global/Direct 六种组合在节点名称为 GLOBAL 的情况下均通过指定核心的语法检查。

- 退款和订阅相关 PHP 测试：15 项、213 个断言通过；使用内存数据库，不涉及真实付款。
- 节点管理 Python 测试：16 项通过，覆盖受保护主机、资源归属和配置等规则。
- PHP Pint 检查通过。

## 验收边界和后续门槛

这次证明隔离节点创建、macOS 独立核心和 Android 已安装客户端的公网 TCP 代理连接、重连及云端整栈回收。Android 请求经代理端口进入核心，不能推导所有手机应用都经过 VPNService/TUN。macOS 测试也不等于 Clash Verge UI、系统代理或 TUN 的完整验收。

未覆盖 Windows、iOS、自有原生客户端、浏览器扩展代理、UDP、IPv6、DNS 泄漏、长时间稳定性、国内各运营商质量和容量。当前会员订阅 API 仍输出标明 local-test 的保留地址，未把本次云节点发布给会员，也没有把客户端目录改成“已验证”。

退款测试只证明订单权益和订阅接口按规则撤销，不能证明已下载的节点凭证或已建立的连接会失效。下一阶段先接入用户级数据面凭证与节点策略版本，撤销时逐节点确认策略生效、拒绝旧凭证并处理既有会话，再做真实退款联动验收；仍有其他有效订单权益的会员应保留相应服务。支付渠道退款、节点回执、失败重试和补偿需分别留证。

本次使用既有节点管理 IAM 栈。云上该栈仍是原三项资源版本，不代表仓库新增的 Manager/Provision workload-role 模板已经部署或验收。上线前需单独审查并验证服务端工作负载身份与队列；本地不启用常驻调度，运行和回收不需要 GPT API Key。

本次重新建立、但实际未使用的 `xlb-lab-access-usw2` 权限栈已删除，并逐项确认 `XlbLabSsmRole`、`XlbLabExpiryRole` 和 `XlbLabSsmProfile` 不存在。既有 `xlb-node-manager-access-usw2` 保留，未修改无关运维用户的权限边界。
