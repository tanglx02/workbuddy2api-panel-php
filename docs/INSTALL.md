# 安装部署手册

## 一、环境要求

| 项 | 要求 | 缺失时的影响 |
|---|---|---|
| PHP | ≥ 8.1（推荐 8.2/8.3） | 必须，无法安装 |
| PDO + pdo_mysql | 有数据库的主机 | 缺失时可选文件存储模式 |
| cURL | 必须（上游 HTTPS 请求） | 缺失时网关核心功能不可用，安装向导会阻止 |
| OpenSSL | 推荐 | 缺失时凭证改为明文存储（向导黄色警示） |
| JSON | 必须 | 缺失时无法安装 |
| data/ 目录可写 | 必须 | 通过主机文件管理器将权限设为 755/775 |
| 出站 HTTPS | 推荐 | 无法连接 copilot.tencent.com 时网关不可用 |

不要求：SSH、Composer、npm、Redis、独立端口、常驻进程。

## 二、部署步骤（从上传到打开面板）

1. 将 `workbuddy2api-panel-php.zip` 上传到主机空间（网站根目录或任意子目录）。
2. 用主机文件管理器解压。解压后根目录应有 `index.php`、`.htaccess`、`install/`、`panel/`、`app/`、`data/`。
3. 浏览器访问 `https://你的域名/`（或子目录路径）。未安装时自动跳转 `/install/`。
4. 安装向导第一步：**环境检测**。红色必须项需先解决；黄色为可降级项（不影响安装但运行受限，如实提示，不伪造通过）。
5. 第二步：**存储与管理员**
   - 存储模式：优先选 **MySQL**（填主机面板提供的库名/用户/密码，端口默认 3306），安装器会先真实连接测试再写入；没有数据库可选 **文件存储**（高并发下性能受限，面板会提示）。
   - api_key：留空则自动生成 `sk-` 强随机密钥。**公网部署务必妥善保存**。
6. 点击「完成安装」：自动生成 `data/secret.key`（AES-256-GCM 密钥）、写入 `data/config.json`（0600）、创建数据库表（MySQL 模式）、锁定安装向导。
7. 记下页面上显示的 **api_key** 与 **Cron 令牌**，然后打开 `https://你的域名/panel/`。

## 三、添加账号

- 面板右上角「添加账号」→ 选择区服（CN / Global）→ 打开授权链接完成 CodeBuddy 登录 → 面板每 3 秒自动轮询，成功后凭证加密落盘并热加载进池（免重启）。
- 也支持导入 cockpit tools 导出的 JSON（批量导入，字段含 uid / access_token / refresh_token）。

## 四、定时任务（Cron）

安装完成后，在主机控制面板添加定时任务（URL 类型或 cron 类型均可），建议每 10 分钟执行一次：

```
curl -s "https://你的域名/cron?token=你的Cron令牌"
```

`/cron` 会按 config 中 `schedule.*_hours` 时点自动判断当天该跑的任务（签到 9/21 点、活跃上报 10 点、旅行 9/21 点、保活 22 点、夜猫子 23 点），并按 `balance_refresh_minutes` 周期刷新余额。也可单独强制执行某类：`/cron/checkin?token=...`（kind 可为 checkin / travel / activity / keepalive / blackcat / balance）。

**可靠性说明**：任务由 cron 触发同步执行，未跑时点的任务在下一次 cron 调用时按当日去重补跑一次。没有 cron 的主机可手动在浏览器访问上述 URL（带令牌），不要依赖刷新网页自动执行。

## 五、客户端接入

```bash
curl -s https://你的域名/v1/chat/completions \
  -H "Authorization: Bearer 你的api_key" \
  -H "Content-Type: application/json" \
  -d '{"model":"cn:deepseek-v4-flash","messages":[{"role":"user","content":"hi"}],"stream":true}'
```

- 模型名可带域前缀：`cn:模型名` / `global:模型名`；不带前缀默认 CN 域。
- `/v1/models` 返回带前缀的完整模型列表及上下文长度、思考档位等字段。

## 六、升级与备份

- 备份 = `data/` 目录（config、secret.key、数据库/文件存储）+ MySQL 库（如使用）。
- 升级：解压新版覆盖（不要覆盖 `data/`），访问首页即可；表结构如需变更会在启动时自动补建。
