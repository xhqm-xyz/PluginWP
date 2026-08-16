# XHQM Live2D 看板娘 · 安装说明

## 安装

1. 把 `xhqm-live2d.zip` 上传到 WordPress：后台 → 插件 → 安装插件 → 上传插件 → 启用。
   （也可以解压后把整个 `xhqm-live2d` 文件夹放到 `/volume1/web/wordpress/wp-content/plugins/` 再在后台启用。）
2. 启用后，后台左侧 **设置 → Live2D 看板娘** 进入配置页。

## 模型上传

1. 在 NAS 上建模型目录（**建议在 Web 根目录之外**，配合防盗）：
   ```
   /volume1/web/live2d-models/
   └── StellaMira/
       ├── StellaMira.model3.json
       ├── StellaMira.moc3
       ├── 动作-待机.motion3.json
       └── …（整个模型文件夹原样放入）
   ```
2. 设置页「模型存放目录」填 `/volume1/web/live2d-models`，「选择模型」下拉会自动扫描出含 `.model3.json` 的子目录。
3. 勾选「启用看板娘」，保存，前台右下角即出现模型。

## 模型防盗说明

- 开启后，模型文件全部经 PHP 代理输出，URL 带 HMAC 签名，10 分钟过期，外部无法拼 URL 批量下载；
- 配合模型目录放在 Web 根之外，直接访问路径彻底不可达；
- 这是「提高门槛」不是绝对防护——浏览器渲染必然要把文件读进内存，抓包高手仍能还原，但普通嗅探和爬虫已经挡掉了。

## AI 聊天

| 设置项 | DeepSeek | Kimi |
|---|---|---|
| API 地址 | `https://api.deepseek.com/v1` | `https://api.moonshot.cn/v1` |
| 模型名 | `deepseek-chat` | `kimi-k2-0905-preview` 等 |
| API Key | platform.deepseek.com | platform.moonshot.cn |

人设卡在设置页填写，保存在服务端不暴露。页面上下文每次对话自动附带当前文章正文（长度可调）。

## 语音（阿里云百炼）

- TTS API 地址保持默认 `https://dashscope.aliyuncs.com/compatible-mode/v1`；
- Key 在阿里云百炼控制台创建；
- 模型如 `qwen-tts`，音色如 `Chelsie`（以百炼文档为准）；
- 勾选启用后，AI 回复自动朗读。

## MCP 工具

- 勾选启用后，AI 自动获得三个内置工具：`get_model_state`（模型状态）、`set_expression`（改表情/部件）、`speak`（语音输出）；
- 外部 MCP 服务器按 JSON 数组配置，支持多个，工具名自动加 `mcp__名称__` 前缀，由服务端代为调用；
- 需要模型本身支持 function calling（DeepSeek V3、Kimi K2 都支持）。

## v1.3.3 自动更新

- 新增 GitHub 更新器（`includes/class-updater.php`）：更新源为 [xhqm-xyz/PluginWP](https://github.com/xhqm-xyz/PluginWP)，每 12 小时检查一次主文件版本号，有新版本时经 WordPress 原生更新通道提示与安装；
- 分发包为仓库 zipball，**不包含模型数据**（模型存放在插件目录之外，更新过程不触碰）；
- 从 ≤1.3.2 升级到本版本需手动上传一次 zip，之后的更新全自动。

## 第三方组件

`assets/lib/` 捆绑了 PixiJS（MIT）、pixi-live2d-display（MIT）与 Live2D Cubism Core（专有，Live2D Software License Agreement）。各组件的版权与许可证全文见 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)。

## 常见问题

- **模型不出来**：F12 看控制台。404 = 模型目录/文件名不对；403 = 签名问题（正常不会发生）；Unknown error = moc3 版本问题（本插件内置 Cubism 5 运行时，Cubism 2/3 老模型不支持）；
- **聊天报余额不足**：API 账户该充值了（DeepSeek 赠送额度会过期）；
- **翻页后模型消失**：本插件 canvas 挂在 PJAX 刷新容器之外，理论上不会；如出现，反馈给我；
- **主题页脚里之前手动加的 Live2D 代码记得删掉**，否则会加载两次。

## MCP 服务器（v1.2.0 新增）

把看板娘本身封装为 MCP 工具提供方，端点：`POST /wp-json/xhqm-l2d/v1/mcp`（Streamable HTTP · JSON-RPC 2.0）。
鉴权：HTTP Basic（站点用户名 + 应用密码），权限继承该账号的 WordPress 角色。

工具清单（14 个）：

| 分组 | 工具 | 说明 |
|---|---|---|
| 模型 | `list_models` / `get_model_capabilities` | 扫描模型目录 / 解析表情与动作清单 |
| 模型 | `switch_model` | 切换展出模型 |
| 模型 | `upload_model` / `delete_model` | zip 部署 / 清空文件（**保留目录**） |
| 配置 | `get_settings` / `update_settings` | **api_key 与 tts_key 被设计为不可读写** |
| 人设 | `get_persona` / `update_persona` | 修改进入**待确认草稿**，后台手动应用才生效 |
| 对话 | `chat_with_mascot` / `tts_speak` | 带工具的对话实例（可取站点实时数据）/ TTS 生成语音 + 全站广播播报 |
| 活体 | `mascot_presence` / `mascot_command` | 在线心跳 / 推送表情·动作·朗读·显隐指令（前台浏览器轮询执行） |

mcp.json 示例：

```json
{
  "mcpServers": {
    "xhqm-live2d": {
      "type": "http",
      "url": "https://xhqm.xyz/wp-json/xhqm-l2d/v1/mcp",
      "headers": { "Authorization": "Basic <base64(用户名:应用密码)>" }
    }
  }
}
```

## v1.3.2 回退表情方案

- 撤回 v1.3.1 的自管理 exp3 持久叠加：表情切换恢复 SDK 原生 `model.expression()` 路径（`mascot_command` 与聊天内置 `set_expression` 同），该方向不再追查；
- 广播声音开关保留，不受影响。

## v1.3.1 表情持久化与广播声音开关（表情部分已于 v1.3.2 撤回）

- **广播声音开关**：设置页「MCP 服务器 → 广播声音」，关闭后 tts_speak / speak 广播只在对话框留文字不出声（默认开）。也可经 `update_settings` 的 `bcast_sound` 字段读写。注意浏览器自动播放策略：访客须与页面交互过一次，音频才能出声。

## v1.3.0 工具开关与广播

- **工具级开关**：设置页「MCP 服务器」区块可逐条启停 14 个工具；被停用的工具不出现在 `tools/list`，直接调用也会被拒绝（默认全开，老站点升级无感）；
- **`tts_speak` 广播化**：合成语音的同时向指令队列推一条广播，全站所有打开看板娘的页面都会播放，文字同步留在对话框里（广播窗口 45 秒，窗口内新打开的页面会补播）；
- **`chat_with_mascot` 实例化**：她现在是带工具的实例——服务端跑多轮工具循环（最多 5 轮），内置工具（查模型状态 / 切表情 / 说话）映射为状态查询与全站广播，外部 MCP 工具（设置页配置）照常可用，可获取站点实时数据；新增 `use_tools` 参数可退回纯对话；
- 前端指令按 id 去重，广播指令免回执。

## v1.2.1 安全加固

- `delete_model`：模型名必填单值、一次仅删除一个模型、必须在 list_models 白名单内，并拒绝 `..`/路径分隔符等越界名称；
- `get_model_capabilities` 同步加模型名越界校验。

## v1.2.0 前端变更

- 聊天窗口支持**拖动边缘缩放**（四边+四角，最小 240×260，尺寸记忆）；
- 新增指令轮询：前台每 8 秒拉取服务端指令队列并执行回执，`mascot_command` 由此驱动。
