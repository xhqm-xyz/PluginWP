# PluginWP

WordPress 插件集（xhqm.xyz 站点自用）。

## 插件清单

| 目录 | 说明 |
|---|---|
| [xhqm-live2d](xhqm-live2d/) | 全站 Live2D 看板娘：任意模型加载、模型防盗、OpenAI 兼容聊天、TTS 语音、MCP 工具服务 |
| [xhqm-bridge](xhqm-bridge/) | WordPress MCP Bridge：将站点封装为 MCP 服务器（文章/媒体/评论/用户/分类/诊断，14 工具） |

## 自动更新

插件内置 GitHub 更新器（`includes/class-updater.php`），以本仓库为更新源：每 12 小时比对新版本号，经 WordPress 原生更新通道提示与安装。分发包为仓库 zipball。

## 资产边界

**模型数据（.moc3 / .model3.json / 贴图 / 表情 / 动作等）为私有资产，永不入库**。插件侧的 `.gitignore` 已按文件格式强制拦截。线上站点的模型存放于插件目录之外（如 `/volume1/web/live2d/model`），更新过程不触碰。
