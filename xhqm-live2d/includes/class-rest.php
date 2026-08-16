<?php
/**
 * REST 端点：
 *  GET  /config                 前端配置（含签名模型 URL）
 *  GET  /model/{exp}/{token}/…  模型文件防盗代理
 *  POST /chat                   AI 聊天中转（人设卡 + 页面上下文 + 工具调用 + MCP）
 *  POST /tts                    阿里云 TTS 中转
 */
if (!defined('ABSPATH')) exit;

class XHQM_L2D_REST {

    const NS = 'xhqm-l2d/v1';
    const TOKEN_TTL = 600; // 模型链接签名有效期（秒）

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS, '/config', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'config'],
            'permission_callback' => '__return_true',
        ]);
        // 模型文件代理：路径里带签名，SDK 按相对路径加载其余文件时签名自动延续
        register_rest_route(self::NS, '/model/(?P<exp>\d+)/(?P<token>[a-f0-9]{32})/(?P<file>.+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'model_file'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/chat', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'chat'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/tts', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'tts'],
            'permission_callback' => '__return_true',
        ]);
        // MCP 指令队列：前台轮询取指令 + 回执
        register_rest_route(self::NS, '/commands', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'commands'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/command-result', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'command_result'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ================= MCP 指令队列（前台轮询） ================= */

    public static function commands() {
        return ['commands' => XHQM_L2D_MCP::queue_poll()];
    }

    public static function command_result(WP_REST_Request $req) {
        $id     = (string) $req->get_param('id');
        $result = (string) $req->get_param('result');
        if (!$id) return new WP_Error('bad_request', 'id 不能为空', ['status' => 400]);
        return ['ok' => XHQM_L2D_MCP::queue_ack($id, $result)];
    }

    /* ================= 前端配置 ================= */

    public static function config() {
        $model = XHQM_Live2D::opt('model');
        if (!$model) return new WP_Error('no_model', '未配置模型', ['status' => 404]);

        $dir = trailingslashit(XHQM_Live2D::opt('models_dir')) . $model;
        $jsons = glob($dir . '/*.model3.json');
        if (!$jsons) return new WP_Error('no_model', '模型目录中没有 .model3.json', ['status' => 404]);

        $entry = $model . '/' . basename($jsons[0]);
        return [
            'model_url'    => self::signed_url($entry),
            'chat'         => (bool) XHQM_Live2D::opt('chat_enabled'),
            'tts'          => (bool) XHQM_Live2D::opt('tts_enabled'),
            'tools'        => (bool) XHQM_Live2D::opt('mcp_enabled'),
            'bcast_sound'  => (bool) XHQM_Live2D::opt('bcast_sound', 1),
        ];
    }

    /** 生成带签名的模型文件代理 URL（签名针对模型目录，目录内文件通用） */
    private static function signed_url($entry) {
        if (!XHQM_Live2D::opt('protect_models')) {
            // 不防盗时仍走代理，但 token 固定为 '0'，方便调试
        }
        $exp = time() + self::TOKEN_TTL;
        $model_dir = explode('/', $entry)[0];
        $token = hash('md5', hash_hmac('sha256', $model_dir . '|' . $exp, XHQM_Live2D::secret()));
        return esc_url_raw(rest_url(self::NS . '/model/' . $exp . '/' . $token . '/' . $entry));
    }

    /* ================= 模型文件代理 ================= */

    public static function model_file(WP_REST_Request $req) {
        $exp   = (int) $req['exp'];
        $token = $req['token'];
        $file  = urldecode($req['file']); // 中文文件名在 URL 中是百分号编码，先解码

        if ($exp < time()) {
            return new WP_Error('expired', '链接已过期', ['status' => 403]);
        }
        $model_dir = explode('/', $file)[0];
        $expect = hash('md5', hash_hmac('sha256', $model_dir . '|' . $exp, XHQM_Live2D::secret()));
        if (!hash_equals($expect, $token)) {
            return new WP_Error('bad_token', '签名无效', ['status' => 403]);
        }

        // 防路径穿越
        if (strpos($file, '..') !== false) {
            return new WP_Error('bad_path', '非法路径', ['status' => 400]);
        }
        $path = trailingslashit(XHQM_Live2D::opt('models_dir')) . $file;
        $real = realpath($path);
        $base = realpath(XHQM_Live2D::opt('models_dir'));
        if (!$real || !$base || strpos($real, $base) !== 0 || !is_file($real)) {
            return new WP_Error('not_found', '文件不存在', ['status' => 404]);
        }

        $mime = 'application/octet-stream';
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $map = ['json' => 'application/json', 'moc3' => 'application/octet-stream',
                'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
        if (isset($map[$ext])) $mime = $map[$ext];

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($real));
        header('Cache-Control: private, max-age=' . self::TOKEN_TTL);
        readfile($real);
        exit;
    }

    /* ================= 聊天 ================= */

    /** 内置工具定义（OpenAI function calling 格式） */
    private static function builtin_tools() {
        return [
            ['type' => 'function', 'function' => [
                'name' => 'get_model_state',
                'description' => '获取 Live2D 看板娘当前状态（正在播放的动作、当前表情、可见性）',
                'parameters' => ['type' => 'object', 'properties' => new stdClass],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'set_expression',
                'description' => '修改看板娘的表情或部件开关',
                'parameters' => ['type' => 'object', 'properties' => [
                    'expression' => ['type' => 'string', 'description' => '表情名，如 表情-脸红；传空字符串表示恢复默认'],
                ], 'required' => ['expression']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'speak',
                'description' => '把一段文字用语音读出来',
                'parameters' => ['type' => 'object', 'properties' => [
                    'text' => ['type' => 'string', 'description' => '要朗读的文字'],
                ], 'required' => ['text']],
            ]],
        ];
    }

    /** 拉取外部 MCP 服务器的工具列表（缓存 10 分钟） */
    private static function mcp_tools() {
        $servers = json_decode(XHQM_Live2D::opt('mcp_servers', '[]'), true);
        if (!is_array($servers) || !$servers) return [[], []];
        $tools = []; $map = []; // map: 前缀工具名 => [server, 原名]
        foreach ($servers as $srv) {
            if (empty($srv['name']) || empty($srv['url'])) continue;
            $cache_key = 'xhqm_l2d_mcp_' . md5($srv['url']);
            $list = get_transient($cache_key);
            if ($list === false) {
                $list = self::mcp_rpc($srv, 'tools/list', new stdClass);
                if (!is_array($list)) $list = [];
                set_transient($cache_key, $list, 600);
            }
            foreach (($list['tools'] ?? []) as $t) {
                $prefixed = 'mcp__' . preg_replace('/[^a-zA-Z0-9_]/', '_', $srv['name']) . '__' . $t['name'];
                $tools[] = ['type' => 'function', 'function' => [
                    'name' => $prefixed,
                    'description' => $t['description'] ?? '',
                    'parameters' => $t['inputSchema'] ?? ['type' => 'object', 'properties' => new stdClass],
                ]];
                $map[$prefixed] = ['server' => $srv, 'tool' => $t['name']];
            }
        }
        return [$tools, $map];
    }

    /** 调用外部 MCP 服务器（streamable HTTP JSON-RPC） */
    private static function mcp_rpc($srv, $method, $params) {
        $headers = array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'], $srv['headers'] ?? []);
        $resp = wp_remote_post($srv['url'], [
            'timeout' => 20, 'headers' => $headers,
            'body' => json_encode(['jsonrpc' => '2.0', 'id' => wp_rand(1, 99999), 'method' => $method, 'params' => $params]),
        ]);
        if (is_wp_error($resp)) return null;
        $body = wp_remote_retrieve_body($resp);
        // 兼容 SSE 包装
        if (strpos($body, 'data:') === 0 || strpos($body, "event:") !== false) {
            foreach (explode("\n", $body) as $line) {
                if (strpos($line, 'data:') === 0) { $body = trim(substr($line, 5)); break; }
            }
        }
        $data = json_decode($body, true);
        return $data['result'] ?? null;
    }

    public static function chat(WP_REST_Request $req) {
        if (!XHQM_Live2D::opt('chat_enabled')) {
            return new WP_Error('disabled', '聊天未启用', ['status' => 403]);
        }
        // 限流
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $k = 'xhqm_l2d_rl_' . md5($ip);
        $n = (int) get_transient($k);
        if ($n >= (int) XHQM_Live2D::opt('rate_limit', 30)) {
            return new WP_Error('rate_limited', '聊得太勤啦，歇一会儿再来~', ['status' => 429]);
        }
        set_transient($k, $n + 1, HOUR_IN_SECONDS);

        $api_key = XHQM_Live2D::opt('api_key');
        if (!$api_key) return new WP_Error('no_key', '站点未配置 API Key', ['status' => 500]);

        $messages = $req->get_param('messages');
        if (!is_array($messages) || !$messages) {
            return new WP_Error('bad_request', 'messages 不能为空', ['status' => 400]);
        }
        $messages = array_slice($messages, -12);
        $messages = self::sanitize_messages($messages);

        // 注入人设卡 + 页面上下文（服务端无状态，每次请求都注入，PJAX 翻页后上下文也能跟着变）
        $page = $req->get_param('page');
        $system = XHQM_Live2D::opt('persona');
        if (is_array($page) && !empty($page['text'])) {
            $limit = (int) XHQM_Live2D::opt('ctx_limit', 6000);
            $text = mb_substr(wp_strip_all_tags($page['text']), 0, $limit);
            $system .= "\n\n—— 访客当前正在浏览的页面 ——\n标题：" . sanitize_text_field($page['title'] ?? '') . "\n内容：{$text}";
        }
        array_unshift($messages, ['role' => 'system', 'content' => $system]);

        // 工具
        $tools = []; $mcp_map = [];
        if (XHQM_Live2D::opt('mcp_enabled')) {
            $tools = self::builtin_tools();
            list($ext_tools, $mcp_map) = self::mcp_tools();
            $tools = array_merge($tools, $ext_tools);
        }

        // 调用 LLM
        $result = self::call_llm($messages, $tools, $api_key);
        if (is_wp_error($result)) return $result;

        $choice = $result['choices'][0] ?? null;
        if (!$choice) return new WP_Error('upstream', 'API 返回异常：' . wp_json_encode($result), ['status' => 502]);

        $msg = $choice['message'];

        // 工具调用
        if (!empty($msg['tool_calls'])) {
            $client_calls = [];  // 需要浏览器执行的
            $tool_results = [];  // 服务端已执行完的（外部 MCP）
            foreach ($msg['tool_calls'] as $call) {
                $name = $call['function']['name'];
                if (isset($mcp_map[$name])) {
                    $args = json_decode($call['function']['arguments'] ?? '{}', true) ?: new stdClass;
                    $r = self::mcp_rpc($mcp_map[$name]['server'], 'tools/call', [
                        'name' => $mcp_map[$name]['tool'], 'arguments' => $args,
                    ]);
                    $tool_results[] = [
                        'tool_call_id' => $call['id'],
                        'role' => 'tool',
                        'content' => wp_json_encode($r),
                    ];
                } else {
                    $client_calls[] = $call;
                }
            }
            return [
                'type' => 'tool_calls',
                'assistant_message' => $msg,
                'client_calls' => $client_calls,
                'server_results' => $tool_results,
            ];
        }

        return ['type' => 'message', 'reply' => $msg['content'] ?? '(空回复)'];
    }

    /**
     * 服务端直聊（MCP 工具 chat_with_mascot 用）：
     * 注入人设卡与可选页面上下文，不限流。
     * $use_tools=true 时她是一个带工具的实例：多轮工具循环（最多 5 轮），
     * 内置工具在服务端映射为广播指令/状态查询，外部 MCP 工具经 mcp_rpc 取用站点实时数据。
     */
    public static function chat_direct($messages, $page = null, $use_tools = false) {
        $api_key = XHQM_Live2D::opt('api_key');
        if (!$api_key) return new WP_Error('no_key', '站点未配置 API Key');

        if (!is_array($messages) || !$messages) {
            return new WP_Error('bad_request', 'messages 不能为空');
        }
        $messages = array_slice($messages, -12);
        $messages = self::sanitize_messages($messages);

        $system = XHQM_Live2D::opt('persona');
        if ($use_tools) {
            $system .= "\n\n—— 你现在的身份 ——\n你正作为站点实例与外界对话。你可以调用工具获取站点实时数据、查询自己的模型状态、切换表情或开口说话（说话与表情会广播到全站所有打开你的页面）。";
        }
        if (is_array($page) && !empty($page['text'])) {
            $limit = (int) XHQM_Live2D::opt('ctx_limit', 6000);
            $text  = mb_substr(wp_strip_all_tags($page['text']), 0, $limit);
            $system .= "\n\n—— 当前浏览的页面 ——\n标题：" . sanitize_text_field($page['title'] ?? '') . "\n内容：{$text}";
        }
        array_unshift($messages, ['role' => 'system', 'content' => $system]);

        $tools = []; $mcp_map = [];
        if ($use_tools && XHQM_Live2D::opt('mcp_enabled')) {
            $tools = self::builtin_tools();
            list($ext_tools, $mcp_map) = self::mcp_tools();
            $tools = array_merge($tools, $ext_tools);
        }

        $rounds = 0; $used = [];
        while (true) {
            $result = self::call_llm($messages, $tools, $api_key);
            if (is_wp_error($result)) return $result;

            $choice = $result['choices'][0] ?? null;
            if (!$choice) return new WP_Error('upstream', 'API 返回异常：' . wp_json_encode($result));
            $msg = $choice['message'];

            if (empty($msg['tool_calls']) || !$tools) {
                return [
                    'reply'       => $msg['content'] ?? '(空回复)',
                    'model'       => XHQM_Live2D::opt('api_model'),
                    'persona_md5' => md5($system), // 便于核对当前生效人设版本
                    'tool_rounds' => $rounds,
                    'tools_used'  => $used,
                ];
            }

            if (++$rounds > 5) {
                // 轮次超限：摘掉工具让她把话收尾
                $tools = [];
                continue;
            }

            $messages[] = $msg; // 带 tool_calls 的 assistant 消息
            foreach ($msg['tool_calls'] as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true);
                if (!is_array($args)) $args = [];
                if (isset($mcp_map[$name])) {
                    $r = self::mcp_rpc($mcp_map[$name]['server'], 'tools/call', [
                        'name' => $mcp_map[$name]['tool'], 'arguments' => $args,
                    ]);
                } else {
                    $r = self::exec_builtin_server_side($name, $args);
                }
                $used[] = $name;
                $messages[] = [
                    'tool_call_id' => $call['id'],
                    'role'         => 'tool',
                    'content'      => wp_json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
    }

    /**
     * 内置工具的服务端执行（chat_direct 实例用）：
     * 浏览器端那套在这里映射为状态查询与全站广播指令
     */
    private static function exec_builtin_server_side($name, $args) {
        switch ($name) {
            case 'get_model_state':
                $model   = XHQM_Live2D::opt('model');
                $caps    = XHQM_L2D_MCP::capabilities($model);
                $present = XHQM_L2D_MCP::presence_status();
                return [
                    'model'           => $model,
                    'visible'         => (bool) XHQM_Live2D::opt('enabled'),
                    'online_pages'    => !empty($present['online']),
                    'last_expression' => get_option('xhqm_l2d_last_expression', '(默认)'),
                    'expressions'     => is_wp_error($caps) ? [] : $caps['expressions'],
                    'motions'         => is_wp_error($caps) ? [] : $caps['motions'],
                ];

            case 'set_expression':
                $expr = isset($args['expression']) ? (string) $args['expression'] : '';
                if ($expr !== '') {
                    $caps = XHQM_L2D_MCP::capabilities('');
                    if (!is_wp_error($caps) && !in_array($expr, $caps['expressions'], true)) {
                        return ['ok' => false, 'error' => '没有这个表情：' . $expr, 'available' => $caps['expressions']];
                    }
                }
                XHQM_L2D_MCP::broadcast('expression', $expr);
                update_option('xhqm_l2d_last_expression', $expr === '' ? '(默认)' : $expr, false);
                return ['ok' => true, 'expression' => $expr === '' ? '(恢复默认)' : $expr, 'note' => '已广播，全站活着的实例都会切换'];

            case 'speak':
                $text = isset($args['text']) ? trim((string) $args['text']) : '';
                if ($text === '') return ['ok' => false, 'error' => 'text 不能为空'];
                XHQM_L2D_MCP::broadcast('speak_broadcast', $text);
                return ['ok' => true, 'note' => '已广播，全站活着的实例都会播报，文字同步进入对话框'];

            default:
                return ['ok' => false, 'error' => '未知内置工具：' . $name];
        }
    }

    /**
     * 清洗消息序列，保证发给 API 的合法性：
     *  1. 丢弃没有前置 tool_calls 的孤儿 tool 消息（历史截断导致）
     *  2. assistant 的 tool_calls 若缺 tool 回应，补占位，避免另一种序列错误
     */
    private static function sanitize_messages($msgs) {
        $out = [];
        $pending = []; // 等待回应的 tool_call_id（保序）
        $flush_placeholders = function () use (&$out, &$pending) {
            foreach ($pending as $id) {
                $out[] = ['tool_call_id' => $id, 'role' => 'tool', 'content' => '(工具结果已过期)'];
            }
            $pending = [];
        };
        foreach ($msgs as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $flush_placeholders();
                $out[] = $m;
                foreach ($m['tool_calls'] as $tc) {
                    if (isset($tc['id'])) $pending[] = $tc['id'];
                }
                continue;
            }
            if ($role === 'tool') {
                $id = $m['tool_call_id'] ?? '';
                $idx = array_search($id, $pending, true);
                if ($idx !== false) {
                    $out[] = $m;
                    unset($pending[$idx]);
                    $pending = array_values($pending);
                }
                // 否则是孤儿 tool 消息，丢弃
                continue;
            }
            $flush_placeholders();
            $out[] = $m;
        }
        $flush_placeholders();
        return $out;
    }

    private static function call_llm($messages, $tools, $api_key) {
        $body = [
            'model' => XHQM_Live2D::opt('api_model'),
            'messages' => $messages,
            'temperature' => (float) XHQM_Live2D::opt('temperature', 0.8),
        ];
        if ($tools) $body['tools'] = $tools;

        $resp = wp_remote_post(rtrim(XHQM_Live2D::opt('api_base'), '/') . '/chat/completions', [
            'timeout' => 90,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) {
            return new WP_Error('upstream', '请求 AI 接口失败：' . $resp->get_error_message(), ['status' => 502]);
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) {
            return new WP_Error('upstream', 'AI 接口返回非 JSON', ['status' => 502]);
        }
        if (!empty($data['error'])) {
            return new WP_Error('upstream', 'AI 接口错误：' . ($data['error']['message'] ?? wp_json_encode($data['error'])), ['status' => 502]);
        }
        return $data;
    }

    /* ================= TTS ================= */

    public static function tts(WP_REST_Request $req) {
        if (!XHQM_Live2D::opt('tts_enabled')) {
            //return new WP_Error('disabled', 'TTS 未启用', ['status' => 403]);
        }
        $text = trim((string) $req->get_param('text'));
        if (!$text) return new WP_Error('bad_request', 'text 不能为空', ['status' => 400]);
        return self::synthesize($text);
    }

    /** TTS 核心（REST 路由与 MCP 工具共用） */
    public static function synthesize($text) {
        $key = XHQM_Live2D::opt('tts_key');
        if (!$key) return new WP_Error('no_key', '未配置 TTS Key', ['status' => 500]);

        $text = mb_substr(trim((string) $text), 0, 600); // 语音最长 600 字
        $base = rtrim(XHQM_Live2D::opt('tts_base'), '/');
        $provider = XHQM_Live2D::opt('tts_provider', 'aliyun');
        $model = XHQM_Live2D::opt('tts_model', 'qwen3-tts-flash');
        $voice = XHQM_Live2D::opt('tts_voice', 'Cherry');

        if ($provider === 'openai') {
            /* —— OpenAI 兼容 /audio/speech：直接返回音频流 —— */
            $resp = wp_remote_post($base . '/audio/speech', [
                'timeout' => 60,
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
                'body' => wp_json_encode(['model' => $model, 'input' => $text, 'voice' => $voice]),
            ]);
            if (is_wp_error($resp)) {
                return new WP_Error('upstream', 'TTS 请求失败：' . $resp->get_error_message(), ['status' => 502]);
            }
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            if ($code !== 200) {
                return new WP_Error('upstream', 'TTS 接口错误（' . $code . '）：' . mb_substr($body, 0, 300), ['status' => 502]);
            }
            return ['audio' => base64_encode($body), 'format' => 'mp3'];
        }

        /* —— 阿里云百炼 DashScope 原生端点：返回 24h 有效的音频 URL —— */
        $url = $base . '/services/aigc/multimodal-generation/generation';

        $resp = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
            'body' => wp_json_encode([
                'model' => $model,
                'input' => ['text' => $text, 'voice' => $voice],
            ]),
        ]);
        if (is_wp_error($resp)) {
            return new WP_Error('upstream', 'TTS 请求失败：' . $resp->get_error_message(), ['status' => 502]);
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data['output']['audio']['url'])) {
            $msg = $data['message'] ?? mb_substr($body, 0, 300);
            return new WP_Error('upstream', 'TTS 接口错误（' . $code . '）：' . $msg, ['status' => 502]);
        }

        // 音频以 24h 有效 URL 返回，服务端拉下来转 base64 给浏览器
        $audio_url = $data['output']['audio']['url'];
        $audio = wp_remote_get($audio_url, ['timeout' => 60]);
        if (is_wp_error($audio) || wp_remote_retrieve_response_code($audio) !== 200) {
            return new WP_Error('upstream', '音频下载失败', ['status' => 502]);
        }
        return ['audio' => base64_encode(wp_remote_retrieve_body($audio)), 'format' => 'mp3'];
    }
}
