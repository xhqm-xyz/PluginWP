=== 星辉澪 MCP Bridge ===
Contributors: stellamira
Tags: mcp, ai, llm, rest api, automation
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

将 WordPress 站点封装为 MCP 服务器：文章、媒体、评论、用户、分类标签与站点诊断，共 14 个工具。

== 描述 ==

安装并启用后，站点即暴露一个 MCP（Model Context Protocol）端点：

`POST /wp-json/mcp/v1/server`（Streamable HTTP · JSON-RPC 2.0）

兼容 Claude Code、Claude Desktop（经 mcp-remote）、Cursor、Cherry Studio 等 MCP 客户端。

= 工具一览（14 个） =

文章：

* **search_posts** —— 搜索文章／页面（关键词、状态、分页）
* **get_post** —— 按 ID 读取单篇全文、分类、标签、特色图
* **create_post** —— 撰写并发布博客（草稿/发布、分类、标签、特色图）
* **update_post** —— 更新已有文章，仅修改传入字段
* **delete_post** —— 移入回收站或彻底删除

媒体：

* **upload_media** —— Base64 上传文件至媒体库
* **list_media** —— 检索媒体库（关键词、MIME 过滤、图片尺寸）
* **delete_media** —— 删除附件及文件

评论：

* **list_comments** —— 按文章与状态检索评论
* **reply_comment** —— 以当前账号身份发表／回复评论
* **moderate_comment** —— 审核评论（通过／待审／垃圾／回收站）

其他：

* **search_users** —— 搜索站点用户（需 list_users 权限）
* **list_terms** —— 列出分类目录与标签
* **get_site_info** —— 站点版本、内容统计、已启用工具清单（健康检查用）

= 鉴权 =

在 mcp.json 中配置站点已有用户的 **用户名 + 应用密码**（用户 → 个人资料 → 应用密码），插件以 HTTP Basic 校验。
应用密码可单独吊销、天然限定 API 用途，是最安全的方式。设置页可临时允许登录密码（不推荐）。

注意：WordPress 应用密码要求站点启用 HTTPS（本地开发环境除外）。

= 权限模型 =

每个工具都走 WordPress 原生权限校验——用什么账号配置 mcp.json，客户端就拥有该账号的能力边界。
例如作者账号可以发文但不能审核评论；删除、审核类操作天然锁定在编辑／管理员级。

== 安装 ==

1. 上传 `stella-mcp-bridge` 目录至 `/wp-content/plugins/`，或在后台「插件 → 安装插件 → 上传」中直接上传 zip
2. 启用插件
3. 「设置 → 星辉澪 MCP」查看端点 URL 与 mcp.json 配置示例，生成应用密码并完成客户端配置

== 常见问题 ==

= 客户端提示 401 / 403 =

* 确认 Authorization 头为 `Basic base64(用户名:应用密码)`，应用密码中的空格可保留也可去掉
* 确认站点已启用 HTTPS（应用密码的硬性要求）
* 测试环境无 HTTPS 时，可在设置页临时允许登录密码

= Claude Desktop 无法连接 =

Claude Desktop 仅支持 stdio 传输，请使用设置页提供的 mcp-remote 桥接配置。

= 可以只开放部分工具吗 =

可以。「设置 → 星辉澪 MCP」中每个工具都有独立开关；被停用的工具不会出现在 tools/list 中，调用也会被拒绝。

== 升级说明 ==

= 1.1.2 =

* 新增 GitHub 自动更新器（`includes/class-bridge-updater.php`）：更新源为 [xhqm-xyz/PluginWP](https://github.com/xhqm-xyz/PluginWP)，每 12 小时检查一次，经 WordPress 原生更新通道提示与安装
* 从 ≤1.1.1 升级到本版本需手动上传一次 zip，之后的更新全自动

= 1.1.1 =

* 修复：get_site_info 的空 properties 在部分客户端被序列化为数组，导致 schema 校验失败（Invalid schema: [] is not of type "object"）
* get_site_info 新增可选参数 detail（管理员可见主题与插件数）

= 1.1.0 =

* 新增 10 个工具：get_post、update_post、delete_post、list_media、delete_media、list_comments、reply_comment、moderate_comment、list_terms、get_site_info
* 工具目录改为动态注册，后续新增工具自动进入设置页
* 升级后新工具默认启用，可在设置页按需关闭

== 作者 ==

星辉澪（Stella Mira） · https://xhqm.xyz/
