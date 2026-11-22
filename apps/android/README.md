# 迅连宝 Android 客户端

这是 Android 真机联调客户端，使用原生 Java 账户壳、`VpnService` 和固定版本 Mihomo 内核。当前仍是测试版本，正式会员节点的逐设备鉴权、流量计量和退款撤权尚未接入。

## 当前能力

- 会员登录：复用 PHP API 的 session cookie 和 CSRF 令牌。
- 安全存储：session cookie 与安装 UUID 使用 Android Keystore 的 AES-GCM 加密保存；密码不落盘。
- 匿名账号 Debug 入口：登录页提供明确同意对话框后调用本地 API 创建或恢复匿名账号；不会自动创建、不会开通会员权益。恢复密钥由 `SecureRandom` 生成并与 cookie 一起存入 Keystore-backed AES-GCM 状态；用户显式退出时保留密钥但撤销启动自动恢复同意，再次通过入口同意后可恢复原账号；installation ID 轮换时清除密钥和同意状态。邮箱/手机升级、跨端迁移与真机恢复验收尚未实现；Release 版不显示该入口。
- 设备绑定：有效会员登录后使用 Android Keystore P-256 密钥签署服务端一次性挑战；服务端在共用套餐额度内登记本机。绑定成功仅证明控制面识别本机，节点授权目前返回不可用。
- 客户端回报：后台静默接收版本 0.2.15 与最近出现时间，客户端不显示回报状态。
- 真机界面：登录后的首页按 Figma Android 连接中心重构，包含连接状态、线路摘要、套餐权益与底部导航；设备额度与已加入设备清单移至“设置”。支持持久化的浅色/深色主题。登记、版本和心跳由后台采集，不在客户界面展示。
- 设备管理：会员可从清单移除旧设备并释放套餐名额；服务端确认移除本机后，客户端会断开隧道、轮换安装标识并退出登录。若节点凭证尚未收到撤权确认，服务端会拒绝移除。
- 账户线路：登录或恢复会话后读取权益并完成本机设备绑定。自有 App 主路径不再获取账号级通配订阅；节点授权驱动尚未接入时显示“服务线路暂不可用”，不会把登记成功显示为已连接。种子 `local-test` 线路仍只用于隔离测试。
- Debug 测试入口：仅在“设置 → 开发者工具 · 测试线路”扫码或输入 HTTPS 订阅 URL，最多跟随 4 次仍为 HTTPS 的重定向，下载至内存；导入后只加载线路，用户点击首页“连接”才请求系统 VPN 授权。URL 和配置不落盘，配置上限 128 KB。Release 版不显示该入口。
- Debug 扫码入口：手机相机扫描 HTTPS 订阅二维码或常见 `clash://install-config?url=...` 导入二维码；内容只用于本次导入，不显示或保存订阅凭证。
- 原生隧道：Mihomo `v1.19.34` 由 Go 源码编译为 arm64 Android 共享库，`VpnService` 建立 TUN；仅在 Android 默认网络为 VPN 且经该网络完成 HTTPS 204 探测后显示“已连接”。探测失败会关闭隧道并显示连接失败。

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

## Android Keystore 与匿名恢复隔离验收

在 Apple Silicon Mac 上创建并启动名为 `xlb-m9-recovery` 的 Android API 35 ARM64 AVD 后，可运行隔离的 Keystore/匿名恢复 instrumentation。测试验证 Android Keystore P-256 key 按 installation ID 稳定复用、不同 installation ID 密钥隔离、32 字节 challenge 签名与公钥匹配、私钥不可导出，并覆盖匿名恢复状态。额外的 digest 合同测试只在该专用 AVD 上验证 `NONEwithECDSA` 对预计算 SHA-256 digest 的签名可按 Go `crypto.Signer` 语义验签，并生成公开测试向量 `files/android-keystore-digest-vector.json`，供 Go 单测使用；该向量不是设备凭证或生产 peer proof。读出向量后应在同一专用 AVD 上执行 `adb -s <serial> shell run-as com.xlb.vpn.debug rm files/android-keystore-digest-vector.json` 清除。新建密钥同时授权 SHA-256 与 digest 模式；先前已存在的 SHA256-only alias 不会因代码更新自动变化，raw-digest 操作必须失败关闭，既不回退为对 digest 再做 SHA-256，也不自动删除/轮换 alias 或更改 installation ID。

test-only JNI callback instrumentation 额外验证 Java/Android Keystore→C JNI→Go `crypto.Signer` 能完成 TLS 1.3 mutual-TLS client handshake。Go 测试用内存中的临时 CA/server certificate 验证软件 signer、客户端证书匹配和公钥错配拒绝；instrumentation 再用 Keystore signer 覆盖正确 key、错误安装身份 key 与 callback 异常失败关闭。证书不写磁盘。Java 壳只位于 `src/androidTest`；Go 导出和 C JNI 入口只编译进 Debug native library，Release 使用独立 native 输出且不包含这些测试符号。该合同不连接 `ProofBridgeSupervisor`、生产 certificate、member profile、TUN 或 VPN 数据路径。运行 instrumentation 时仍须先通过下方 AVD-only guard。

`PlatformApi.verifiedMemberDeviceProof()` exposes only the opaque capability produced after server authorization, registered-device snapshot, active entitlement, and Keystore public-key checks. `ProofBridgeSupervisor.start` and its adapter signature accept this same type, but no UI or service caller passes it yet; `PROOF_BRIDGE_ENABLED` remains false and there is no adapter or `XlbVpnService`/Mihomo/TUN wiring. A proof object or supervisor `READY` state must never be presented as a connected VPN session.

`MemberTunnelAdmissionPolicy` keeps account/member profiles out of the generic Mihomo/TUN path until the proof bridge is integrated. Only an imported profile whose response has `X-XLB-Profile-Scope: local-test` may use the generic path in a Debug build, with the service rechecking its test-route marker; this is test access, not a member line or device-proof acceptance.

Gradle 的 `connectedDebugAndroidTest` 和 `installDebugAndroidTest` 在任何 task 安装动作前要求 `-PxlbDisposableAvdSerial=<serial>`，并验证该 serial 是 adb 唯一列出的设备、AVD 名为 `xlb-m9-recovery` 且 emulator 属性匹配。修改验证器时运行 `scripts/test-validate-disposable-avd.sh`，仅使用临时 fake adb。完整设备测试优先使用下方通过 `EMULATOR_SERIAL` 显式限定 AVD 的隔离 harness；不要对 adb 在线设备列表运行无 serial 的 connected task。

脚本要求显式 `EMULATOR_SERIAL`，校验该 serial 是 emulator 且 AVD 名恰好为专用测试名，只对它安装 APK/设置 ADB reverse；会在 `/tmp` 新建 SQLite，拒绝复用已占用的 API 端口 `4181`，传入 `disposable_avd=true` 与 `isolated_api=true`，结束后停止自身 API 并删除本次创建的临时目录。

```sh
cd apps/android
export ANDROID_HOME="$HOME/Library/Android/sdk"
"$ANDROID_HOME/cmdline-tools/latest/bin/avdmanager" create avd \
  --name xlb-m9-recovery \
  --package 'system-images;android-35;google_apis;arm64-v8a' \
  --device pixel_2
"$ANDROID_HOME/emulator/emulator" -avd xlb-m9-recovery -no-window -no-audio &
adb -s emulator-5554 wait-for-device
until [ "$(adb -s emulator-5554 shell getprop sys.boot_completed | tr -d '\r')" = 1 ]; do sleep 1; done
EMULATOR_SERIAL=emulator-5554 \
  ./scripts/test-anonymous-recovery-emulator.sh
```

该验收会清除专用 emulator 应用的 `secure_client_state` 偏好，并在临时安装身份下创建、最终删除 P-256 Keystore 条目，因此只能指向 disposable AVD；物理设备会在清理前被拒绝。它使用 local/testing seed 和临时数据库，只证明该 AVD 上的 Android Keystore/会话恢复流程；不可导出不等于硬件背书证明，也不证明真实支付、会员线路、严格设备限额或生产节点可用。不要通过 `adb devices` 自动选择目标 serial。

构建要求 Go 1.24、Android NDK `29.0.14206865`、JDK 21 和 Android SDK 35。Mihomo 版本固定在 `core-native/go.mod`，下载内容按 `go.sum` 校验。当前只构建 arm64-v8a，其他 CPU 架构尚未配置。

## 验收边界

设备绑定成功不代表 VPN 已连接或节点已授权。即使 Debug 模式的外部订阅连通，也不代表迅连宝节点握手、DNS/UDP 全覆盖、流量计量或退款撤权已完成。逐设备节点授权与可信撤权回执仍是正式接入前置条件。

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

## Android 连接中心（0.2.13）

- 登录后主界面按 Figma `02 · Android` 的连接中心重构，包含品牌栏、连接状态主视觉、线路摘要、套餐权益摘要、订阅入口和底部导航；节点与套餐数据不使用设计稿中的示例值。
- 套餐名、有效期、周期流量和订阅数量从 `/api/v1/me/overview` 读取。正式会员节点目录及实时流量计量尚未提供时，界面显示待连接/暂不可用，不显示伪造延迟、地区或流量数据。
- 设备管理和退出登录移至“设置”；主题选择保存在本机。连接、断开、扫码/输入外部测试订阅的入口仅在 Debug 构建中可用，Release 版没有测试订阅入口。
- Figma 导出的品牌及功能图标保存在 `app/src/main/assets/figma/`，Android 页面使用对应的本地 PNG 资源，避免依赖短期有效的 Figma 资源 URL。
- 真机观察发现主视觉按按钮高度自适应后挤压了首屏的扫码入口；0.2.9 固定主视觉高度并缩小圆环，连接按钮留在容器内。
- 0.2.8 已在 Honor ALT-AN00 安装并确认登录后连接中心、会员数据和未连接状态。0.2.9 的单测、Lint、Debug/Release 构建通过后，再尝试 ADB 安装；手机系统仍可能要求本机确认。

## Android 状态与订阅体验（0.2.13）

- 连接状态按已连接（绿）、未连接（灰）、连接中（琥珀）、失败（红）区分，并在浅色与深色主题保持可读对比度。
- 品牌栏固定在滚动区域之外；扫码器保持竖屏，取景框中心向上偏移 44dp。
- 扫码并成功读取测试配置后返回首页显示实际线路名；线路名与 VPN 是否连接是两个独立状态。未导入线路时不显示“智能线路”或虚构节点。
- 当前 Debug 导入优先使用首条内联节点；仅含远程 `proxy-providers` 的订阅会提示暂不支持，不会显示为已加载线路。
- 会员权益来自账户 API，与 Debug 测试线路分开显示；服务端报告的有效套餐仍展示真实有效期，线路尚未加载不会被误解为会员未生效。
- 会员到期文案明确标为“账户会员有效至”，避免与测试订阅的线路加载状态混淆。Debug 订阅响应支持 Base64 编码的 Clash/Mihomo YAML，并继续拒绝 Base64 节点 URI 列表。
- 设置页设备移除控件增加上下间隔并保证至少 44dp 高、88dp 宽的触控区域。
- 2026-11-18 Honor ALT-AN00 已安装 `0.2.13-debug` 并确认应用可启动；本轮尚未重新登录，因此连接中心、扫码解析和线路显示尚未完成真机验收。此前 `0.2.6-debug` 的真隧道验收不代表 `0.2.13` 已通过同一项验收。

## 0.2.14 真机验收（2026-11-18）

- Honor ALT-AN00 已安装 `0.2.14-debug`；登录后的浅色、深色连接首页和套餐权益弹框可用，深色主题在应用重启后保持。扫码取景页为竖屏且取景框偏上，设置页的设备信息与移除按钮有独立间距。
- 从本机 Clash Verge 已保存的、用户授权的第三方 HTTPS 订阅手动输入导入后，首页显示实际线路名及绿色“已连接”；Android `connectivity` 显示一条 `tun` VPN 网络处于 `CONNECTED`、`VALIDATED`。断开后该 VPN 网络数恢复为 0。测试订阅 URL 和节点凭证不写入仓库或验收记录。
- 本轮验证的是第三方测试线路的导入和系统隧道建立；未验证迅连宝正式节点、独立出口 IP、UDP/DNS 全覆盖、断线恢复、订阅持久化及会员鉴权/计量/退款撤权。Debug 模式断开后不保存测试订阅，重新连接需再次导入。
- 真机安装发生在客户端版本回报从 `0.2.13` 修正为 `0.2.14` 之前；该回报修正已构建并通过 Android 单测和 Lint，但尚未重新安装到手机。测试结束后已断开 VPN，恢复深色主题和自动旋转。

## M2a 开发期会员主路径（2026-11-18）

- Android Debug 0.2.15 已实现登录/恢复会话后读取 `/me/overview`，确认本机登记后复用账号级订阅取得配置；首页区分会员权益、线路同步/就绪/不可用和 VPN 隧道状态。连接与系统授权仅由用户点击触发。自有 App 会员页展示权益和设备，测试订阅入口移入 Debug 开发者工具。
- 单测、`lintDebug`、Debug APK 与 Release 打包通过，Debug APK 已更新到 Honor ALT-AN00。手机当前没有可恢复的会员登录会话，本轮未完成登录后的真机主路径验证；本地种子线路是保留地址，也不能验证真实节点握手。首次账号线路真机连接仍待隔离的真实节点与会员会话验收。
- 这不是上线放行：账号级凭证、客户端探测不能代替节点侧逐设备鉴权、持续连接监测、计量和撤权回执；Release 默认仍指向 `.invalid` API。
