# Android 客户端源码与许可

`apps/android` 下的迅连宝 Android 客户端按 GNU GPL v3.0 发布，许可全文见 [LICENSE](LICENSE)。该客户端嵌入 [MetaCubeX/mihomo](https://github.com/MetaCubeX/mihomo) `v1.19.34`（提交 `0455d0d28c4526937380505153e65a843d8612e8`），其许可证也是 GPL-3.0。Mihomo 的完整对应源码由固定版本 Go 模块下载并通过 `go.sum` 校验；发布 APK 时必须同时提供本 Android 客户端源码、固定依赖版本和可复现构建说明。

此许可声明只覆盖 `apps/android` 的客户端源码，不改变仓库其他应用、服务端或网站的许可状态。当前 APK 是本地 Debug 测试产物，未签名发布、未上架，也未完成真实节点和退款撤权验收。

二维码扫描使用 [ZXing Android Embedded](https://github.com/journeyapps/zxing-android-embedded) `4.3.0`，由 ZXing authors 和 Journey Mobile 开发，按 Apache License 2.0 授权；其传递依赖 ZXing Core 使用 Apache License 2.0。发布包应附带这些第三方许可文本和归属信息。
