<?php
/**
 * Plugin Name: XHQM Live2D 看板娘
 * Plugin URI:  https://xhqm.xyz
 * Update URI:  https://github.com/xhqm-xyz/PluginWP
 * Description: 全站 Live2D 看板娘：任意模型加载、模型文件防盗、OpenAI 兼容 API 聊天（DeepSeek / Kimi 等）、人设卡 + 页面上下文、阿里云 TTS 语音、MCP 工具（模型状态 / 表情 / 语音）。
 * Version:     1.3.3
 * Author:      星辉澪（Stella Mira） && Kimi
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('XHQM_L2D_VERSION', '1.3.3');
define('XHQM_L2D_DIR', plugin_dir_path(__FILE__));
define('XHQM_L2D_URL', plugin_dir_url(__FILE__));

require_once XHQM_L2D_DIR . 'includes/class-settings.php';
require_once XHQM_L2D_DIR . 'includes/class-rest.php';
require_once XHQM_L2D_DIR . 'includes/class-mcp-server.php';
require_once XHQM_L2D_DIR . 'includes/class-updater.php';

final class XHQM_Live2D {

    public static function init() {
        XHQM_L2D_Settings::init();
        XHQM_L2D_REST::init();
        XHQM_L2D_MCP::init();
        XHQM_L2D_Updater::init(__FILE__);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_footer', [__CLASS__, 'render_widget'], 5);
    }

    /** 读取选项（带默认值） */
    public static function opt($key, $default = '') {
        $opts = get_option('xhqm_l2d_options', []);
        $defaults = [
            'enabled'          => 0,
            'models_dir'       => WP_CONTENT_DIR . '/xhqm-live2d-models',
            'model'            => '',
            'canvas_w'         => 320,
            'canvas_h'         => 420,
            'position'         => 'right',
            'protect_models'   => 1,
            'chat_enabled'     => 1,
            'api_base'         => 'https://api.deepseek.com/v1',
            'api_key'          => '',
            'api_model'        => 'deepseek-chat',
            'temperature'      => 0.8,
            'persona'          => "你是住在这个博客里的看板娘，性格温柔，说话简短自然，用中文回复。",
            'ctx_limit'        => 6000,
            'rate_limit'       => 30,
            'tts_enabled'      => 0,
            'tts_base'         => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'tts_key'          => '',
            'tts_model'        => 'qwen-tts',
            'tts_voice'        => 'Chelsie',
            'mcp_enabled'      => 0,
            'mcp_servers'      => "[]",
            'mcp_server_enabled'   => 1,
            'mcp_allow_password'   => 0,
            'bcast_sound'          => 1,
        ];
        $opts = wp_parse_args($opts, $defaults);
        return array_key_exists($key, $opts) ? $opts[$key] : $default;
    }

    /** 签名密钥（激活时生成） */
    public static function secret() {
        $s = get_option('xhqm_l2d_secret');
        if (!$s) {
            $s = wp_generate_password(48, true, true);
            update_option('xhqm_l2d_secret', $s, false);
        }
        return $s;
    }

    public static function activate() {
        self::secret();
    }

    public static function enqueue() {
        if (!self::opt('enabled')) return;
        $model = self::opt('model');
        if (!$model) return;

        wp_enqueue_script('xhqm-l2d-core', XHQM_L2D_URL . 'assets/lib/live2dcubismcore.min.js', [], XHQM_L2D_VERSION, true);
        wp_enqueue_script('xhqm-l2d-pixi', XHQM_L2D_URL . 'assets/lib/pixi.min.js', ['xhqm-l2d-core'], XHQM_L2D_VERSION, true);
        wp_enqueue_script('xhqm-l2d-display', XHQM_L2D_URL . 'assets/lib/cubism4.min.js', ['xhqm-l2d-pixi'], XHQM_L2D_VERSION, true);
        wp_enqueue_script('xhqm-l2d-widget', XHQM_L2D_URL . 'assets/js/widget.js', ['xhqm-l2d-display'], XHQM_L2D_VERSION, true);
        wp_enqueue_style('xhqm-l2d-widget', XHQM_L2D_URL . 'assets/css/widget.css', [], XHQM_L2D_VERSION);

        wp_localize_script('xhqm-l2d-widget', 'XHQM_L2D', [
            'rest'     => esc_url_raw(rest_url('xhqm-l2d/v1')),
            'chat'     => (bool) self::opt('chat_enabled'),
            'tts'      => (bool) self::opt('tts_enabled'),
            'width'    => (int) self::opt('canvas_w', 320),
            'height'   => (int) self::opt('canvas_h', 420),
            'position' => self::opt('position', 'right'),
        ]);
    }

    /** 前端 DOM：画布 + 聊天面板 */
    public static function render_widget() {
        if (!self::opt('enabled') || !self::opt('model')) return;
        $pos = self::opt('position', 'right');
        ?>
        <canvas id="xhqm-l2d-canvas"></canvas>
        <div id="xhqm-l2d-chat" data-pos="<?php echo esc_attr($pos); ?>">
          <div id="xhqm-l2d-chat-head">
            <span id="xhqm-l2d-chat-title">看板娘</span>
            <span id="xhqm-l2d-chat-close">&times;</span>
          </div>
          <div id="xhqm-l2d-chat-body"></div>
          <div id="xhqm-l2d-chat-input">
            <input id="xhqm-l2d-chat-text" type="text" placeholder="和她说点什么…" autocomplete="off">
            <button id="xhqm-l2d-chat-send" type="button">发送</button>
          </div>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, ['XHQM_Live2D', 'activate']);
XHQM_Live2D::init();
