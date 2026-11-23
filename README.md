# 迅连宝本地平台

本项目是从零开始的本地开发版，和旧站静态原型、任何线上后台及支付系统隔离。
业务、研发与运维的分阶段实施方案见 [VPN 业务与运维路线图](docs/vpn-business-operations-roadmap.md)；上线工程总规划（批量部署/快速上下线/压测负载均衡/四端研发/多云长期演进）见 [上线工程总体规划](docs/launch-engineering-master-plan.md)。

## 本地运行

需要 PHP 8.3+、Composer 2、Node.js 22+ 和 pnpm 11+。

首次运行：

~~~sh
cd apps/php-api
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
cd ../..
pnpm install
pnpm dev
~~~

打开 http://127.0.0.1:5174。Vite 将 /api 和 /subscribe 代理到本机 Laravel 服务 127.0.0.1:4175。PHP 演示数据库独立存放于 apps/php-api/database/database.sqlite；旧 Node 演示库 apps/api/data/ 不会被覆盖或自动迁移。

默认演示登录：

- 管理员：admin@xlb.local / admin-local-2026
- 会员：member@xlb.local / member-local-2026

可以在 apps/php-api/.env 设置 LOCAL_ADMIN_PASSWORD、LOCAL_MEMBER_PASSWORD，修改首次写入演示账户的密码。已有账户不会在再次运行种子时被覆盖。请勿在该目录放置生产凭证。

## 当前纵向切片

- Laravel 13 + SQLite 的 PHP API；React 控制台保留。登录/退出使用服务端会话和 CSRF 校验，管理员、会员角色隔离。
- 管理员可创建、编辑、上架和下架套餐。编辑会生成新版本，已有订单与会员权益仍关联购买时的版本。
- 本地套餐、会员权益、演示订单和代理佣金流水。
- 会员可创建测试订单、在弹框查看套餐快照和状态记录、二次确认取消；管理员可分页查看全部订单并按状态筛选。金额由服务端计算，同一请求标识重复提交只产生一笔订单；套餐变更后不能用旧版本新下单。
- 测试订单有效期为 30 分钟，到期立即按已超时展示；到期任务以条件更新持久化状态和系统事件。仅本地会员可模拟沙箱付款：付款事件、订单状态和权益发放流水在一个事务中落账，幂等重试不重复生成；新权益期限接在当前权益之后。此续期规则仅为本地演示假设，待正式业务政策确认。
- 超时测试订单的模拟付款只记待核验事件，不改变订单状态或发放权益；取消订单、预置已支付样例不能再次模拟付款。沙箱模拟不请求支付渠道、不扣款、不生成代理佣金，生产环境始终拒绝该入口。
- 独立于“模拟付款”按钮，PHP payment layer 已有 provider-neutral 创建会话、回调验签、主动查单、分页对账契约和签名假渠道；规范化事件账本、幂等权益发放 outbox 与退款补偿 worker 有 Feature/Unit 覆盖。它验证的是注入式假渠道逻辑，不代表已连接真实商户，也不证明真实签名、扣款或退款。
- 用户订阅凭证创建、轮换、撤销；数据库只存 SHA-256 摘要，原始 URL 只在创建/轮换响应中返回一次。
- Mihomo YAML 测试配置。
- 管理员的用户、测试节点台账和只读佣金页面。
- 节点台账支持多条本地记录、新建/详情弹框、候选/标记可用/排空/维护/隔离/退役状态，以及有向状态转换与历史记录。新增记录只能使用系统分配的 .invalid 保留域名，不接受真实服务器地址。
- 健康状态与目录状态分开显示；健康探测始终标注未接入，不把样例会话或延迟当作实时监控。配置版本目前仅保留初始值，尚无配置发布功能。
- 套餐新建、编辑和详情，以及工单新建/详情、订阅生成/轮换均使用弹框。
- 会员帮助工单：创建、回复、查看处理记录；管理员可回复并在待处理、处理中、已解决之间变更状态。会员回复已解决工单会重新打开，状态变化和回复均写入审计。
- PHP 使用独立的 SQLite 迁移与种子数据；旧 Node API 代码与原演示库仍保留，但不再是 pnpm dev 的运行后端。

默认节点地址 198.51.100.10 属于文档保留测试网段，不是迅连宝服务器，不可连接。订阅配置顶部写明仅用于格式验证。运行此 MVP 不会访问真实节点、支付渠道、线上数据库或正式结算系统。

## 测试订单验收

以会员账户登录，进入“套餐权益”→“创建测试订单”→确认；在详情弹框检查金额、套餐版本和权益快照。取消订单需要再次确认。管理员的“订单管理”可查看会员订单与状态记录，会员只能查看自己的订单。深浅主题均可使用。

到期状态在读取时即时计算，不依赖定时器。若需写入到期事件，可在 `apps/php-api` 运行 `php artisan orders:expire-test`；持续本地开发时另开终端运行 `php artisan schedule:work`，按分钟执行任务。`pnpm dev` 不自动启动调度器。重复运行不会重复写入事件。

会员可在订单详情中选择“模拟沙箱付款”。付款确认仅写入本地测试事件，并在同一事务内将订单标记为 `paid_test`、创建权益和发放流水；重复请求返回原记录。付款事件与发放流水使用订单唯一约束防重。另有的 provider-neutral 假渠道测试已覆盖签名时间窗、订单/交易/金额/币种校验、重复/乱序事件、查单、分页对账、发放重试及退款补偿。真实支付仍需商户沙箱资料和回调契约，并按真实渠道规范单独验收；续费期限、流量重置、跨套餐和部分退款规则待业务确认。

该功能仅在 local/testing 环境允许会员创建、取消测试订单，数据库为本地 SQLite。唯一约束和事务覆盖本地重复提交；正式数据库的并发、锁等待及支付回调竞态仍需专项验证。预置的 `paid_test` 订单是展示样例，没有真实渠道付款。

## 检查

运行命令：pnpm test；pnpm build。旧 Node API 回归测试可单独运行 pnpm test:legacy。

## 当前实现范围与剩余项

- 真实支付、退款和财务/代理结算操作。
- 正式会员节点的用户级鉴权、到期断开、流量采集和会话迁移。管理员独立 AWS 测试节点的自动部署、真实协议验证及到期整栈回收已接通，见 [节点运维](docs/node-retirement-and-reclamation.md)。
- Android 已有原生 Debug 原型和 server-verified member grant→VPN service 的默认关闭接线；proof bridge、设备 challenge、Mihomo profile 与服务生命周期有离线/隔离 AVD 测试。会员准入 gate 仍关闭，未验收实体会员真机上的真实节点线路、bridge 握手或匹配撤权断流；Release 不是已签名发布制品或可发布成品。
- Web 会员控制台和 Chrome 扩展原型已在本地 API 上使用会话；匿名身份展示/扩展订阅展示有 mock 测试。扩展未完成浏览器商店发布/真实会员线路验收。
- Windows 已有 Tauri/React 账户壳（登录、恢复、权益/设备摘要），路径限定 Windows hosted run `44945777899` 的 MSVC build 与临时 Credential Manager 测试通过；Mihomo/TUN/proof bridge 数据面、安装签名和运行验收尚未实现。macOS/iOS 自有客户端仍未实现；Android/Windows 先行，macOS/iOS 后续。
- Clash Verge Rev / Clash Mi 的真实迅连宝会员逐设备线路导入、复用拒绝与撤权仍未验收；现有 YAML/保留地址和第三方测试订阅不算会员节点支持。
- 生产部署和商用合规审查。

当前全景、M1–M11/E0–E7 状态、并行工作包和发布阻塞门见 [PRD 开发进度](docs/member-connection-prd.md) 与 [上线工程总体规划 §0.1](docs/launch-engineering-master-plan.md)。Android 匿名账号恢复已在隔离 API 35 arm64 emulator 完成 instrumentation；M10 有持久健康状态、默认关闭 fake-driver worker 和默认关闭路由过滤，但没有真实 probe driver 或生产调度；M11 已有 80 请求/并发 1 与 4 的本机临时 SQLite smoke 报告，不是生产容量基线。Windows 账户壳 MSVC build 和 Credential Manager 临时往返已由 hosted run 验证，但 Mihomo/TUN/proof bridge 尚缺。首发范围已定为 Android + Web/API 单服务商受控试点，真实会员隔离线路/节点回执、生产 mTLS、商户沙箱、合规书面结论与书面发布放行仍属于门槛。M6 两台现有手机各自独立 P-256 身份已有记录，不需要第三台手机；会员线路下复制拒绝与撤权回执仍待验收。当前 AWS `xlb-ops` 资源盘点请求返回 session expired，恢复盘点需用户本人重新 `aws login`。
