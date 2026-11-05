# 迅连宝 PHP API

此目录是独立的 Laravel 13 后端。React 页面位于 ../web；本地运行方式见仓库根目录 README.md。

API 沿用 /api/v1 的账户、目录、会员、订阅、工单和管理员接口。所有有状态写操作使用 Laravel 会话与 CSRF 令牌。React 从 /api/v1/auth/csrf 获取令牌；订阅 URL 的原始凭证仅在创建或轮换时返回一次，数据库只保存摘要。

套餐按 family_id 和 version 保存。编辑套餐创建新版本，订单与权益继续引用旧 plan_id；上下架只改变当前版本的目录可见性。

开发库使用 database/database.sqlite，与旧 Node 演示库完全隔离。种子数据仅在 local/testing 环境写入，测试节点 198.51.100.10 不能真实连接。
