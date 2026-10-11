# 安全配置说明

安全要求已直接落实到代码，逐项对应如下：

| 要求 | 实现位置 |
|---|---|
| 管理员密码哈希 | 面板采用 api_key 常量时间比较鉴权（`Core/Util::verifyBearer`，sha256 + hash_equals），无明文口令落盘 |
| 安全随机数 | `random_bytes` / `random_hex` 生成 api_key、cron 令牌、AES 密钥 |
| 身份认证 | `/v1/*`、`/status`、`/panel/api/*` 统一 Bearer 鉴权；`/healthz` 恒开放（仅计数，无敏感信息）；`/cron` 独立令牌 |
| CSRF | 面板鉴权走 `Authorization` 头而非 Cookie，浏览器不会自动附带，天然免疫经典 CSRF；面板静态页无表单提交 |
| SQL 注入 | 全部查询使用 PDO 预处理参数绑定（`Core/Store.php`） |
| XSS | 面板沿用原版 CSP（`default-src 'none'; script-src 'self'; frame-ancestors 'none'` 等）+ nosniff + DENY 框架 |
| 路径穿越 | uid 白名单 `[A-Za-z0-9_-]` ≤64（`Util::validUID`），导入/登录/删除全部走库而非拼文件路径 |
| 登录频率限制 | OAuth state 会话 15 分钟 TTL 且一次性；轮询失败不扩散；api_key 常量时间比较防时序侧信道 |
| 凭证脱敏 | 日志输出前对 access_token / refresh_token / Bearer 串统一打码（`Core/RingLog::clean`），面板响应不回传凭证明文 |
| 凭证加密 | AES-256-GCM（`Core/Crypto`），密钥独立于配置存放 `data/secret.key`（0600） |
| 敏感文件防下载 | `data/ app/ tests/ docs/` 前缀请求一律 404（front controller 强制）；`.htaccess` 再加一层 RedirectMatch + 文件类型拒绝。**已通过真实 HTTP 请求测试**（tests/run_tests.php §11：config.json / store.json / secret.key 均不可访问） |
| 安装页防重放 | 安装完成写入锁定标志，重复访问只读提示，不再接受配置提交 |
| 无认证任务调用 | `/cron` 必须携带安装生成的 cron_token（hash_equals 校验），缺省/错误均 401 |
| 导入文件校验 | cockpit 导入限 multipart ≤32MB、JSON 格式校验、逐账号字段校验（uid 白名单 + token 必填），不合格计入 skipped |
| 不泄露服务器信息 | `display_errors=0`，生产响应不输出绝对路径与堆栈；404/500 均为固定 JSON |

## 公网部署建议

1. **必须设置 api_key**（安装向导默认生成强随机值）。
2. 站点务必走 HTTPS（虚拟主机通常自带）。
3. 定期备份 `data/` 与数据库。
4. 泄露疑似发生时：面板改 api_key（热生效）+ 删除 `data/secret.key` 前先备份，随后重新安装会使全部旧密文不可解密（凭证需重新登录）。
5. `cron_token` 只在安装页显示一次；可从 `data/config.json` 重新查看。
