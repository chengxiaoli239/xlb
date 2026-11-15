# 迅连宝 Android 客户端

这是 Android 真机联调的第一阶段客户端，使用原生 Java 壳实现账户会话和设备回报链路。它不是 VPN 成品，也没有加载 Mihomo、`VpnService`、订阅节点或真实流量通道。

## 当前能力

- 会员登录：复用 PHP API 的 session cookie 和 CSRF 令牌。
- 安全存储：session cookie 与安装 UUID 使用 Android Keystore 的 AES-GCM 加密保存；密码不落盘。
- 设备登记：登录成功后幂等调用 `POST /api/v1/me/devices`。
- 客户端回报：调用 `POST /api/v1/client/heartbeat` 上报版本 `0.1.0` 和最近出现时间。
- 真机界面：显示会员账户、设备名额、权益状态、最近回报和同步/退出操作。

## 本地真机调试

```sh
cd apps/android
JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" \
ANDROID_HOME="$HOME/Library/Android/sdk" \
ANDROID_SDK_ROOT="$HOME/Library/Android/sdk" \
  ./gradlew assembleDebug

adb -s <设备序列号> reverse tcp:4175 tcp:4175
adb -s <设备序列号> install --no-streaming -r -d app/build/outputs/apk/debug/app-debug.apk
adb -s <设备序列号> shell monkey -p com.xlb.vpn.debug 1
```

调试构建的 API 地址是 `http://127.0.0.1:4175`，只在执行 ADB reverse 的测试设备上有效。默认本地会员是 `member@xlb.local`，密码读取 `apps/php-api/.env` 的 `LOCAL_MEMBER_PASSWORD`；没有覆盖时使用项目默认值。不要把这个账号或密码用于生产。

Release 构建的默认 API 地址是保留的 `https://api.xlb.invalid`，在正式 API 域名、证书和移动端认证联调通过之前不会连接旧站。

## 验收边界

本阶段只验收登录、Keystore 会话保存、稳定安装 ID、设备登记和心跳回报。账户回报成功不代表 VPN 已连接，也不代表节点握手、DNS、UDP、流量计量或退款撤权已经完成。下一阶段接入 `VpnService` 授权和 Mihomo Android 内核前，需要先固定订阅格式、节点鉴权和断线恢复契约。
