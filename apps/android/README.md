# 迅连宝 Android 客户端

这是 Android 真机联调客户端，使用原生 Java 账户壳、`VpnService` 和固定版本 Mihomo 内核。当前仍是测试版本，正式会员节点的逐设备鉴权、流量计量和退款撤权尚未接入。

## 当前能力

- 会员登录：复用 PHP API 的 session cookie 和 CSRF 令牌。
- 安全存储：session cookie 与安装 UUID 使用 Android Keystore 的 AES-GCM 加密保存；密码不落盘。
- 设备登记：登录成功后幂等调用 `POST /api/v1/me/devices`。
- 客户端回报：后台静默接收版本 0.2.7 与最近出现时间，客户端不显示回报状态。
- 真机界面：连接状态优先，随后显示设备额度与已加入设备清单；“本机”标识清楚。登记、版本和心跳继续由后台采集，不在客户界面展示。
- 设备管理：会员可从清单移除旧设备并释放套餐名额；服务端确认移除本机后，客户端会断开隧道、轮换安装标识并退出登录。若节点凭证尚未收到撤权确认，服务端会拒绝移除。
- Debug 测试入口：输入 HTTPS 订阅 URL，下载至内存并请求系统 VPN 授权；不保存 URL 或配置，不跟随重定向，配置上限 128 KB。Release 版不显示该入口。
- Debug 扫码入口：手机相机扫描 HTTPS 订阅二维码或常见 `clash://install-config?url=...` 导入二维码；内容只用于本次导入，不显示或保存订阅凭证。
- 原生隧道：Mihomo `v1.19.34` 由 Go 源码编译为 arm64 Android 共享库，`VpnService` 建立 TUN，前台通知显示连接状态；启动失败不会显示已连接。

Android 客户端采用 GPL-3.0，来源和发布源码要求见 [NOTICE](NOTICE.md)。本地测试订阅只验证客户端隧道；不能证明迅连宝正式节点、服务端鉴权或流量计量可用。

## 本地真机调试

```sh
cd apps/android
JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" \
ANDROID_HOME="$HOME/Library/Android/sdk" \
ANDROID_SDK_ROOT="$HOME/Library/Android/sdk" \
GOPROXY=https://goproxy.cn GOSUMDB=sum.golang.org \
  ./gradlew assembleDebug

adb -s <设备序列号> reverse tcp:4175 tcp:4175
adb -s <设备序列号> install --no-streaming -r -d app/build/outputs/apk/debug/app-debug.apk
adb -s <设备序列号> shell monkey -p com.xlb.vpn.debug 1
```

调试构建的 API 地址是 `http://127.0.0.1:4175`，只在执行 ADB reverse 的测试设备上有效。默认本地会员是 `member@xlb.local`，密码读取 `apps/php-api/.env` 的 `LOCAL_MEMBER_PASSWORD`；没有覆盖时使用项目默认值。不要把这个账号或密码用于生产。

Release 构建的默认 API 地址是保留的 `https://api.xlb.invalid`，在正式 API 域名、证书和移动端认证联调通过之前不会连接旧站。

构建要求 Go 1.24、Android NDK `29.0.14206865`、JDK 21 和 Android SDK 35。Mihomo 版本固定在 `core-native/go.mod`，下载内容按 `go.sum` 校验。当前只构建 arm64-v8a，其他 CPU 架构尚未配置。

## 验收边界

账户回报成功不代表 VPN 已连接。即使 Debug 模式的外部订阅连通，也不代表迅连宝节点握手、DNS/UDP 全覆盖、流量计量或退款撤权已完成。服务端逐设备授权与撤权回执仍是正式接入前置条件。

## 真机验收（2026-11-16）

- 设备：Honor ALT-AN00；Debug 包 `com.xlb.vpn.debug`，应用版本 `0.1.0-debug`，API 回报版本 `0.1.0`。
- 密码输入框经 UIAutomator 验证 `password=true`，无障碍文本为掩码；荣耀密码管理器的保存提示选择了取消。
- 本地演示会员登录成功，设备名额显示 `1 / 5`，权益有效；本地 API 数据库记录平台 `android`、客户端版本 `0.1.0` 和 `last_seen_at`。
- 强制停止并重新打开 App 后，会话仍有效，应用自动刷新设备心跳。
- 当次 0.1.0 仅证明账户、设备登记和心跳链路可用；当时尚未接入 VpnService 与 Mihomo。

## 隧道集成进展（2026-11-16）

- `0.2.1` Debug/Release 本地构建、二维码解析单测与 Android Lint 通过；arm64 APK 含固定版本 Mihomo JNI 库与 `VpnService`。
- 当次 0.2.1-debug 曾安装于 Honor ALT-AN00；相机权限与扫码器已能进入 Android VPN 授权流程。
- 当次 0.2.1 检查尚未发现活动 VPN 网络；后续 0.2.6 的连接验收见下文。
- 0.2.2 将订阅格式协商改为 Clash.Meta，并区分网页、通用节点列表和非 Clash JSON；当时仍待再次扫码确认。

## 配置校验诊断（0.2.6 Debug）

- 0.2.6-debug 已在 Honor ALT-AN00 安装并复测：从本机 Clash Verge 已保存的第三方订阅导入后，界面显示“VPN 隧道已建立”；Android connectivity 确认 tun0 上的 VPN 网络为 CONNECTED 且 VALIDATED；手机 Chrome 通过 VPN 查询到公网 IPv4。点击“断开连接”后系统 VPN 网络消失，同一站点直连访问失败。测试后保持断开状态。
- 调试连接只取订阅首个内联节点，使用全局模式重建隔离配置；原订阅中的远程节点/规则 provider、规则、监听器、DNS、外部 UI 和 TUN 设置均不应用。第三方订阅的 URL 和节点凭证未写入源码或验收文档。
- Go 单测、Android 单测、Debug 构建与 Lint 通过。仍未验收 UDP、断线重连、首节点不可用时的切换，以及正式会员节点的鉴权、流量计量和退款撤权；第三方订阅连通不能代表这些功能完成。

### 历史诊断

- Mihomo 配置校验失败时只返回固定分类提示，不向界面回传可能包含节点名称、规则和地址的原始错误。规则失败会进一步区分规则集缺失、策略引用缺失、规则格式、循环引用和提供者类型。
- 0.2.3 真机复测确认订阅可下载并通过 YAML 解析，但规则校验失败。0.2.6 在解析订阅后，只提取首个内联节点，再使用全局模式重建隔离配置；连接初始化超时为 20 秒。
- 配置通过校验仍不等于正式上线；已确认本次第三方订阅的 TUN、系统 VPN 网络和 Chrome 出口，后续还需验证 DNS 与 UDP 边界、断线恢复及迅连宝会员授权。

## 设备管理进展（0.2.7）

- 已完成移除确认、设备 API 对接和本机移除后的本地状态清理；服务端设备 API 的 9 个测试通过，Android 单测、Lint、Debug 与 Release 构建通过。
- 本轮未连接到测试手机，尚未真机确认移除弹框与断开流程；正式节点的撤权回执仍须单独实测。
