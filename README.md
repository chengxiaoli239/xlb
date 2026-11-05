# 迅连宝本地平台

本项目是从零开始的本地开发版，和旧站静态原型、任何线上后台及支付系统隔离。
业务、研发与运维的分阶段实施方案见 [VPN 业务与运维路线图](docs/vpn-business-operations-roadmap.md)。

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
- 用户订阅凭证创建、轮换、撤销；数据库只存 SHA-256 摘要，原始 URL 只在创建/轮换响应中返回一次。
- Mihomo YAML 测试配置。
- 管理员的用户、测试节点状态和只读佣金页面。
- 节点状态变化写入本地审计记录。
- 会员帮助工单：创建、回复、查看处理记录；管理员可回复并在待处理、处理中、已解决之间变更状态。会员回复已解决工单会重新打开，状态变化和回复均写入审计。
- PHP 使用独立的 SQLite 迁移与种子数据；旧 Node API 代码与原演示库仍保留，但不再是 pnpm dev 的运行后端。

默认节点地址 198.51.100.10 属于文档保留测试网段，不是迅连宝服务器，不可连接。订阅配置顶部写明仅用于格式验证。运行此 MVP 不会访问真实节点、支付渠道、线上数据库或正式结算系统。

## 检查

运行命令：pnpm test；pnpm build。旧 Node API 回归测试可单独运行 pnpm test:legacy。

## 尚未实现

- 真实支付、退款和财务/代理结算操作。
- 真实节点部署、密钥管理、健康探测、流量采集或连接服务。
- 自有 Android、iOS、Windows、macOS App。
- Chrome/Firefox 可安装扩展。
- Clash Verge Rev 或 Clash Mi 真实客户端导入验证。
- 生产部署和商用合规审查。

下一批工作：迁移或归档旧 Node 演示数据，完善订单/权益状态机、支付沙箱和管理员 MFA，再将单节点演示模型扩展为测试环境的多节点生命周期与健康探测，并以实测的 Mihomo 内核和目标客户端建立兼容测试矩阵。确认产品经营和目标地区要求后，再评估进入真实环境。
