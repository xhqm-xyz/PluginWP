<?php
/**
 * 后台设置页：设置 → Live2D 看板娘
 */
if (!defined('ABSPATH')) exit;

class XHQM_L2D_Settings {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_post_xhqm_l2d_persona_apply', [__CLASS__, 'persona_apply']);
        add_action('admin_post_xhqm_l2d_persona_discard', [__CLASS__, 'persona_discard']);
    }

    /** 应用 MCP 提交的人设草稿 */
    public static function persona_apply() {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('xhqm_l2d_persona');
        $pending = json_decode(get_option('xhqm_l2d_persona_pending', ''), true);
        if (is_array($pending) && !empty($pending['persona'])) {
            $opts = get_option('xhqm_l2d_options', []);
            $opts['persona'] = sanitize_textarea_field($pending['persona']);
            update_option('xhqm_l2d_options', $opts);
        }
        delete_option('xhqm_l2d_persona_pending');
        wp_safe_redirect(admin_url('options-general.php?page=xhqm-l2d&persona=applied'));
        exit;
    }

    /** 丢弃人设草稿 */
    public static function persona_discard() {
        if (!current_user_can('manage_options')) wp_die('无权操作');
        check_admin_referer('xhqm_l2d_persona');
        delete_option('xhqm_l2d_persona_pending');
        wp_safe_redirect(admin_url('options-general.php?page=xhqm-l2d&persona=discarded'));
        exit;
    }

    public static function menu() {
        add_options_page('Live2D 看板娘', 'Live2D 看板娘', 'manage_options', 'xhqm-l2d', [__CLASS__, 'page']);
    }

    public static function register() {
        register_setting('xhqm_l2d', 'xhqm_l2d_options', [
            'sanitize_callback' => [__CLASS__, 'sanitize'],
        ]);
        // MCP 工具级开关（独立 option，name => 1/0）
        register_setting('xhqm_l2d', XHQM_L2D_MCP::TOOLS_OPTION, [
            'sanitize_callback' => [__CLASS__, 'sanitize_mcp_tools'],
        ]);
    }

    /** 工具开关持久化：checkbox 未勾不会提交，缺省即 0；键集合以工具目录为准 */
    public static function sanitize_mcp_tools($in) {
        $out = [];
        foreach (XHQM_L2D_MCP::catalog() as $name => $meta) {
            $out[$name] = !empty($in[$name]) ? 1 : 0;
        }
        return $out;
    }

    public static function sanitize($in) {
        $out = [];
        $bools = ['enabled', 'protect_models', 'chat_enabled', 'tts_enabled', 'mcp_enabled', 'mcp_server_enabled', 'mcp_allow_password', 'bcast_sound'];
        foreach ($bools as $b) $out[$b] = !empty($in[$b]) ? 1 : 0;

        $out['models_dir']  = rtrim(sanitize_text_field($in['models_dir'] ?? ''), '/');
        $out['model']       = sanitize_text_field($in['model'] ?? '');
        $out['canvas_w']    = max(100, min(1000, (int)($in['canvas_w'] ?? 320)));
        $out['canvas_h']    = max(100, min(1200, (int)($in['canvas_h'] ?? 420)));
        $out['mobile_scale'] = max(20, min(100, (int)($in['mobile_scale'] ?? 55)));
        $out['position']    = in_array($in['position'] ?? 'right', ['left', 'right']) ? $in['position'] : 'right';
        $out['api_base']    = esc_url_raw(trim($in['api_base'] ?? ''));
        $out['api_key']     = sanitize_text_field($in['api_key'] ?? '');
        $out['api_model']   = sanitize_text_field($in['api_model'] ?? '');
        $out['temperature'] = max(0, min(2, (float)($in['temperature'] ?? 0.8)));
        $out['persona']     = sanitize_textarea_field($in['persona'] ?? '');
        $out['ctx_limit']   = max(500, min(30000, (int)($in['ctx_limit'] ?? 6000)));
        $out['rate_limit']  = max(1, min(1000, (int)($in['rate_limit'] ?? 30)));
        $out['tts_base']    = esc_url_raw(trim($in['tts_base'] ?? ''));
        $out['tts_provider'] = in_array($in['tts_provider'] ?? 'aliyun', ['aliyun', 'openai']) ? $in['tts_provider'] : 'aliyun';
        $out['tts_key']     = sanitize_text_field($in['tts_key'] ?? '');
        $out['tts_model']   = sanitize_text_field($in['tts_model'] ?? 'qwen-tts');
        $out['tts_voice']   = sanitize_text_field($in['tts_voice'] ?? 'Chelsie');
        // MCP 服务器列表必须是合法 JSON
        $mcp = wp_unslash($in['mcp_servers'] ?? '[]');
        json_decode($mcp);
        $out['mcp_servers'] = (json_last_error() === JSON_ERROR_NONE) ? $mcp : '[]';
        return $out;
    }

    /** 扫描模型目录，返回 [目录名 => model3.json 文件名] */
    public static function scan_models() {
        $base = XHQM_Live2D::opt('models_dir');
        $found = [];
        if (!is_dir($base)) return $found;
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $jsons = glob($dir . '/*.model3.json');
            if ($jsons) $found[basename($dir)] = basename($jsons[0]);
        }
        return $found;
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $models = self::scan_models();
        $cur = XHQM_Live2D::opt('model');
        $o = function ($k) { return esc_attr(XHQM_Live2D::opt($k)); };
        $c = function ($k) { return XHQM_Live2D::opt($k) ? 'checked' : ''; };
        ?>
        <div class="wrap">
          <h1>Live2D 看板娘设置</h1>
          <form method="post" action="options.php">
            <?php settings_fields('xhqm_l2d'); ?>

            <h2>基本</h2>
            <table class="form-table">
              <tr><th>启用看板娘</th><td><label><input type="checkbox" name="xhqm_l2d_options[enabled]" value="1" <?php echo $c('enabled'); ?>> 全站显示</label></td></tr>
              <tr><th>模型存放目录</th><td>
                <input type="text" class="regular-text" name="xhqm_l2d_options[models_dir]" value="<?php echo $o('models_dir'); ?>">
                <p class="description">服务器上的绝对路径。建议放在 <strong>Web 根目录之外</strong>（如 <code>/volume1/web/live2d-models</code>），配合下方防盗功能，外部无法直接下载模型文件。每个模型一个子目录，内含 <code>*.model3.json</code>。</p>
              </td></tr>
              <tr><th>选择模型</th><td>
                <select name="xhqm_l2d_options[model]">
                  <option value="">— 请选择 —</option>
                  <?php foreach ($models as $dir => $json): ?>
                    <option value="<?php echo esc_attr($dir); ?>" <?php selected($cur, $dir); ?>><?php echo esc_html($dir . '（' . $json . '）'); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!$models): ?><p class="description" style="color:#d63638">目录下没扫描到模型，请检查路径或先上传模型文件夹。</p><?php endif; ?>
              </td></tr>
              <tr><th>画布大小</th><td>
                宽 <input type="number" name="xhqm_l2d_options[canvas_w]" value="<?php echo $o('canvas_w'); ?>" style="width:80px"> px
                高 <input type="number" name="xhqm_l2d_options[canvas_h]" value="<?php echo $o('canvas_h'); ?>" style="width:80px"> px
              </td></tr>
              <tr><th>位置</th><td>
                <select name="xhqm_l2d_options[position]">
                  <option value="right" <?php selected($o('position'), 'right'); ?>>右下角</option>
                  <option value="left" <?php selected($o('position'), 'left'); ?>>左下角</option>
                </select>
              </td></tr>
              <tr><th>移动端缩放</th><td>
                <input type="number" name="xhqm_l2d_options[mobile_scale]" value="<?php echo $o('mobile_scale'); ?>" style="width:80px" min="20" max="100"> %
                <p class="description">屏幕宽度小于 768px 时画布按此比例缩小（相对上面的画布大小），填 100 表示不缩放。</p>
              </td></tr>
              <tr><th>模型文件防盗</th><td>
                <label><input type="checkbox" name="xhqm_l2d_options[protect_models]" value="1" <?php echo $c('protect_models'); ?>> 通过带签名的动态链接代理模型文件（10 分钟过期，无法直接拼 URL 批量下载）</label>
                <p class="description">注意：浏览器渲染模型必然需要下载文件到内存，这是「提高抓取门槛」而非绝对防护。配合模型目录放在 Web 根之外效果最好。</p>
              </td></tr>
            </table>

            <h2>AI 聊天（OpenAI 兼容 API）</h2>
            <table class="form-table">
              <tr><th>启用聊天</th><td><label><input type="checkbox" name="xhqm_l2d_options[chat_enabled]" value="1" <?php echo $c('chat_enabled'); ?>> 点击模型唤出聊天框</label></td></tr>
              <tr><th>API 地址</th><td>
                <input type="url" class="regular-text" name="xhqm_l2d_options[api_base]" value="<?php echo $o('api_base'); ?>">
                <p class="description">OpenAI 兼容的 base URL，如 DeepSeek <code>https://api.deepseek.com/v1</code>、Kimi <code>https://api.moonshot.cn/v1</code></p>
              </td></tr>
              <tr><th>API Key</th><td><input type="password" class="regular-text" name="xhqm_l2d_options[api_key]" value="<?php echo $o('api_key'); ?>" autocomplete="new-password"></td></tr>
              <tr><th>模型名</th><td><input type="text" class="regular-text" name="xhqm_l2d_options[api_model]" value="<?php echo $o('api_model'); ?>"><p class="description">如 deepseek-chat / kimi-k2 等</p></td></tr>
              <tr><th>Temperature</th><td><input type="number" step="0.1" min="0" max="2" name="xhqm_l2d_options[temperature]" value="<?php echo $o('temperature'); ?>" style="width:80px"></td></tr>
              <tr><th>人设卡</th><td>
                <textarea name="xhqm_l2d_options[persona]" rows="8" class="large-text"><?php echo esc_textarea(XHQM_Live2D::opt('persona')); ?></textarea>
                <p class="description">作为 system prompt 发给模型。保存在服务端，不会暴露给访客。MCP 通道对人设的修改不会直接生效，只会生成下方待确认草稿。</p>
                <?php
                $pending = json_decode(get_option('xhqm_l2d_persona_pending', ''), true);
                if (is_array($pending) && !empty($pending['persona'])):
                    $nonce = wp_create_nonce('xhqm_l2d_persona');
                ?>
                <div style="margin-top:10px;padding:12px;border-left:4px solid #dba617;background:#fff8e5;max-width:760px">
                  <strong>待确认的人设修改（来自 MCP，提交者：<?php echo esc_html($pending['by'] ?? '?'); ?>，时间：<?php echo esc_html($pending['time'] ?? '?'); ?>）</strong>
                  <?php if (!empty($pending['reason'])): ?><p style="margin:6px 0">修改理由：<?php echo esc_html($pending['reason']); ?></p><?php endif; ?>
                  <textarea rows="8" class="large-text" readonly style="background:#fff"><?php echo esc_textarea($pending['persona']); ?></textarea>
                  <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin-post.php?action=xhqm_l2d_persona_apply&_wpnonce=' . $nonce)); ?>">应用此草稿</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=xhqm_l2d_persona_discard&_wpnonce=' . $nonce)); ?>">丢弃</a>
                  </p>
                </div>
                <?php endif; ?>
                <?php if (isset($_GET['persona'])): ?>
                  <p class="description" style="color:#00a32a"><?php echo $_GET['persona'] === 'applied' ? '草稿已应用。' : '草稿已丢弃。'; ?></p>
                <?php endif; ?>
              </td></tr>
              <tr><th>页面上下文上限</th><td><input type="number" name="xhqm_l2d_options[ctx_limit]" value="<?php echo $o('ctx_limit'); ?>" style="width:100px"> 字符<p class="description">每次对话附带当前页面正文的最大长度（防 token 爆炸）</p></td></tr>
              <tr><th>限流</th><td>每个 IP 每小时最多 <input type="number" name="xhqm_l2d_options[rate_limit]" value="<?php echo $o('rate_limit'); ?>" style="width:80px"> 次对话</td></tr>
            </table>

            <h2>语音（阿里云百炼 TTS）</h2>
            <table class="form-table">
              <tr><th>启用语音</th><td><label><input type="checkbox" name="xhqm_l2d_options[tts_enabled]" value="1" <?php echo $c('tts_enabled'); ?>> AI 回复自动转语音播放</label></td></tr>
              <tr><th>TTS 服务商</th><td>
                <select name="xhqm_l2d_options[tts_provider]">
                  <option value="aliyun" <?php selected($o('tts_provider'), 'aliyun'); ?>>阿里云百炼（DashScope 原生 API）</option>
                  <option value="openai" <?php selected($o('tts_provider'), 'openai'); ?>>OpenAI 兼容（/audio/speech）</option>
                </select>
                <p class="description">阿里云百炼的 OpenAI 兼容模式<strong>不支持</strong> TTS，用百炼请选第一项；第二项适用于 OpenAI 官方或其他兼容 <code>/audio/speech</code> 的服务。</p>
              </td></tr>
              <tr><th>TTS API 地址</th><td><input type="url" class="regular-text" name="xhqm_l2d_options[tts_base]" value="<?php echo $o('tts_base'); ?>"><p class="description">阿里云百炼：<code>https://dashscope.aliyuncs.com/api/v1</code>　OpenAI 兼容：<code>https://api.openai.com/v1</code></p></td></tr>
              <tr><th>TTS API Key</th><td><input type="password" class="regular-text" name="xhqm_l2d_options[tts_key]" value="<?php echo $o('tts_key'); ?>" autocomplete="new-password"></td></tr>
              <tr><th>TTS 模型</th><td><input type="text" name="xhqm_l2d_options[tts_model]" value="<?php echo $o('tts_model'); ?>"><p class="description">百炼如 qwen3-tts-flash；OpenAI 如 tts-1 / gpt-4o-mini-tts</p></td></tr>
              <tr><th>音色</th><td><input type="text" name="xhqm_l2d_options[tts_voice]" value="<?php echo $o('tts_voice'); ?>"><p class="description">百炼如 Cherry / Serena；OpenAI 如 alloy / nova / shimmer</p></td></tr>
            </table>

            <h2>MCP 服务器（把看板娘封装为工具）</h2>
            <table class="form-table">
              <tr><th>启用 MCP 服务</th><td>
                <label><input type="checkbox" name="xhqm_l2d_options[mcp_server_enabled]" value="1" <?php echo $c('mcp_server_enabled'); ?>> 开放 <code><?php echo esc_html(rest_url('xhqm-l2d/v1/mcp')); ?></code> 端点</label>
                <p class="description">Streamable HTTP · JSON-RPC 2.0 · POST。鉴权：HTTP Basic（站点用户名 + 应用密码，用户 → 个人资料 → 应用密码）。</p>
              </td></tr>
              <tr><th>工具开关</th><td>
                <?php $tool_catalog = XHQM_L2D_MCP::catalog(); ?>
                <div style="columns:2;max-width:820px">
                <?php foreach (XHQM_L2D_MCP::tool_switches() as $tname => $on): ?>
                  <label style="display:block;break-inside:avoid;margin-bottom:5px">
                    <input type="checkbox" name="<?php echo esc_attr(XHQM_L2D_MCP::TOOLS_OPTION); ?>[<?php echo esc_attr($tname); ?>]" value="1" <?php checked($on); ?>>
                    <code><?php echo esc_html($tname); ?></code> — <?php echo esc_html($tool_catalog[$tname][1]); ?>
                    <span style="color:#999">(需 <?php echo esc_html($tool_catalog[$tname][0]); ?>)</span>
                  </label>
                <?php endforeach; ?>
                </div>
                <p class="description" style="max-width:760px">
                  被停用的工具不会出现在 tools/list 中，直接调用也会被拒绝。安全约束不变：api_key / tts_key 永不可读写；delete_model 清空文件但保留目录、一次一个模型；update_persona 只进待确认草稿。<br>
                  行为说明：tts_speak 额外向全站广播（所有打开看板娘的页面都播放，文字留在对话框）；chat_with_mascot 是带工具的实例，可调用内置能力与下方外部 MCP 获取站点实时数据；mascot_command 经前台浏览器指令队列执行。
                </p>
              </td></tr>
              <tr><th>广播声音</th><td>
                <label><input type="checkbox" name="xhqm_l2d_options[bcast_sound]" value="1" <?php echo XHQM_Live2D::opt('bcast_sound', 1) ? 'checked' : ''; ?>> 广播播报时播放语音</label>
                <p class="description">关闭后，tts_speak / speak 广播只在各实例的对话框里留文字，不出声。注意：浏览器要求用户与页面交互过一次才允许自动播放音频。</p>
              </td></tr>
              <tr><th>允许登录密码</th><td>
                <label><input type="checkbox" name="xhqm_l2d_options[mcp_allow_password]" value="1" <?php echo $c('mcp_allow_password'); ?>> 允许使用账号登录密码进行 Basic 鉴权</label>
                <p class="description">不推荐。仅在应用密码不可用时（无 HTTPS 的测试环境）临时开启。</p>
              </td></tr>
              <tr><th>自检</th><td>
                <pre style="max-width:760px;padding:10px;background:#1d2327;color:#c3c4c7;overflow:auto">curl -u "用户名:应用密码" <?php echo esc_html(rest_url('xhqm-l2d/v1/mcp')); ?> \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
              </td></tr>
            </table>

            <h2>MCP 工具</h2>
            <table class="form-table">
              <tr><th>启用 MCP</th><td><label><input type="checkbox" name="xhqm_l2d_options[mcp_enabled]" value="1" <?php echo $c('mcp_enabled'); ?>> 允许 AI 调用工具（内置：模型状态获取 / 表情修改 / 语音输出）</label></td></tr>
              <tr><th>外部 MCP 服务器</th><td>
                <textarea name="xhqm_l2d_options[mcp_servers]" rows="5" class="large-text code"><?php echo esc_textarea(XHQM_Live2D::opt('mcp_servers')); ?></textarea>
                <p class="description">JSON 数组，支持多个，例如：<code>[{"name":"weather","url":"https://example.com/mcp","headers":{"Authorization":"Bearer xxx"}}]</code>。服务端会以 JSON-RPC 调用其 tools/list 与 tools/call，工具名自动加 <code>mcp__名称__</code> 前缀。</p>
              </td></tr>
            </table>

            <?php submit_button('保存设置'); ?>
          </form>
        </div>
        <?php
    }
}
