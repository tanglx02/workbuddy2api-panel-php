# 故障排查手册

## 安装阶段

| 现象 | 原因与解决 |
|---|---|
| 访问首页白屏或 500 | 检查 PHP 版本 ≥ 8.1；临时将 `index.php` 首行后加 `ini_set('display_errors','1');` 排查后务必删除 |
| 安装页提示 data/ 不可写 | 主机文件管理器将 `data/` 目录权限改为 755 或 775 |
| MySQL 连接失败 | 核对主机面板的数据库主机（很多主机是 `localhost` 而非远程地址）、库名、用户、密码；确认用户已被授权该库 |
| 出站 HTTPS 检测失败 | 主机禁用了出站网络，网关无法工作；联系主机商或更换主机 |
| 重新安装按钮不见了 | 安装完成即锁定（防重放利用）。确需重装：删除 `data/config.json` 与 `data/secret.key`（会清空配置） |

## 运行阶段

| 现象 | 原因与解决 |
|---|---|
| 401 invalid_api_key | 客户端密钥与 `data/config.json` 的 `api_key` 不一致；面板改过密钥后客户端需同步 |
| 503 no_healthy_account | 全部账号冷却/禁用/在途占满。看面板「账号池」状态与原因列；签到回血后自动解冻 |
| 502 upstream_error / upstream timeout | 上游网络不通或超时；面板「运行日志」看具体账号与错误分类；确认主机出站 HTTPS 正常 |
| 流式响应一次性输出（无打字机效果） | 主机开启了输出缓冲。尝试在 `.htaccess` 加 `php_flag output_buffering Off`（若主机允许）；否则流式仍可用但非逐字 |
| 长对话被截断 | 主机 `max_execution_time` 限制（安装页有检测值）；在主机面板调大，或缩短输入 |
| 定时任务没跑 | 确认 cron URL 带 `?token=` 令牌且返回 `{"ok":true}`；未到 `schedule.*_hours` 时点不会执行（可手动访问 `/cron/checkin?token=...` 强制） |
| 账号被禁用（12153） | 连续 3 次 session 失效自动禁用；面板点「解冻」或重新「添加账号」登录覆盖凭证 |
| 余额不更新 | 点面板「刷新」（立即全量刷余额）；或确认 cron 余额刷新开启 |
| 面板打不开某些视图报错 | 浏览器 F12 → Network 看具体 `/panel/api/*` 响应；响应体 error 字段即为后端原因 |

## 数据与文件

- 敏感数据全部在 `data/`（config.json、secret.key、store.json 或数据库），Web 访问已被路由层与 `.htaccess` 双重拦截（返回 404）。
- 凭证accessToken/refreshToken 以 AES-256-GCM 加密存储（OpenSSL 缺失时明文，安装页会警告）。
- MySQL 模式下表前缀 `wb2a_`：kv / accounts / sticky / reqlog / usage 五张表。
