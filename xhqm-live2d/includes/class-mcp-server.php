<?php
/**
 * MCP 服务器：把看板娘封装为 MCP 工具提供方
 *
 * 端点：POST /wp-json/xhqm-l2d/v1/mcp（Streamable HTTP · JSON-RPC 2.0）
 * 鉴权：HTTP Basic（站点用户名 + 应用密码；可选允许登录密码）
 *
 * 工具分两层：
 *  - 服务端直接执行：模型管理、配置读写、人设卡、聊天、TTS
 *  - 借道浏览器执行：mascot_command 经指令队列由前台 widget 轮询执行
 *
 * 安全约定：
 *  - api_key / tts_key 永远不在读取面出现，也不接受写入
 *  - delete_model 只清空目录内容，保留目录本身
 *  - update_persona 写入待确认草稿，需管理员在后台手动应用
 */
if (!defined('ABSPATH')) exit;

class XHQM_L2D_MCP {

    const PROTOCOL_VERSION   = '2025-03-26';
    const SUPPORTED_VERSIONS = ['2024-11-05', '2025-03-26', '2025-06-18'];
    const QUEUE_OPTION       = 'xhqm_l2d_cmds';
    const PRESENCE_TRANSIENT = 'xhqm_l2d_presence';
    const PRESENCE_TTL       = 120;   // 秒，前台轮询心跳续期
    const PRESENCE_ONLINE    = 90;    // 秒内视为在线
    const CMD_TTL            = 3600;  // 指令记录保留时长
    const BCAST_WINDOW       = 45;    // 广播指令可见窗口（秒）：窗口期内所有在线实例都能取到
    const UPLOAD_MAX         = 268435456; // 模型 zip 上限 256MB
    const TOOLS_OPTION       = 'xhqm_l2d_mcp_tools'; // 工具级开关：name => 1/0

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'auth_challenge'], 10, 3);
    }

    public static function routes() {
        register_rest_route(XHQM_L2D_REST::NS, '/mcp', [
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle_post'],
                'permission_callback' => [__CLASS__, 'authenticate'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'handle_get'],
                'permission_callback' => [__CLASS__, 'authenticate'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => function () { return new WP_REST_Response((object) [], 200); },
                'permission_callback' => [__CLASS__, 'authenticate'],
            ],
        ]);
    }

    public static function auth_challenge($response, $server, $request) {
        if ($response->get_status() === 401 && strpos($request->get_route(), '/' . XHQM_L2D_REST::NS . '/mcp') === 0) {
            $response->header('WWW-Authenticate', 'Basic realm="XHQM Live2D MCP", charset="UTF-8"');
        }
        return $response;
    }

    /* ================= 鉴权 ================= */

    public static function authenticate(WP_REST_Request $request) {
        if (!XHQM_Live2D::opt('mcp_server_enabled', 1)) {
            return new WP_Error('mcp_disabled', 'MCP 服务已被管理员停用', ['status' => 503]);
        }
        if (is_user_logged_in()) return true;

        $header = $request->get_header('authorization');
        if (empty($header) || stripos($header, 'Basic ') !== 0) {
            return new WP_Error('auth_required', '需要 HTTP Basic 鉴权：Authorization: Basic base64(用户名:应用密码)', ['status' => 401]);
        }
        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return new WP_Error('auth_malformed', 'Authorization 头格式错误', ['status' => 401]);
        }
        list($username, $password) = explode(':', $decoded, 2);

        // 应用密码：展示空格不属于密钥本体
        $user = wp_authenticate_application_password(null, $username, str_replace(' ', '', $password));
        if ($user instanceof WP_User) {
            wp_set_current_user($user->ID);
            return true;
        }
        if (XHQM_Live2D::opt('mcp_allow_password', 0)) {
            $user = wp_authenticate($username, $password);
            if ($user instanceof WP_User && !is_wp_error($user)) {
                wp_set_current_user($user->ID);
                return true;
            }
        }
        return new WP_Error('auth_failed', '鉴权失败：用户名或密码无效（建议使用应用密码）', ['status' => 403]);
    }

    /* ================= JSON-RPC 分发 ================= */

    public static function handle_post(WP_REST_Request $request) {
        $data = json_decode($request->get_body(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return self::respond(self::rpc_error(null, -32700, 'Parse error：请求体不是合法 JSON'), 200, $request);
        }
        $is_batch = wp_is_numeric_array($data);
        $messages = $is_batch ? $data : [$data];
        if (!$messages) {
            return self::respond(self::rpc_error(null, -32600, 'Invalid Request：空的批量请求'), 200, $request);
        }
        $results = [];
        foreach ($messages as $msg) {
            $r = self::dispatch($msg);
            if ($r !== null) $results[] = $r;
        }
        if (!$results) return self::respond(null, 202, $request);
        return self::respond($is_batch ? $results : $results[0], 200, $request);
    }

    public static function handle_get(WP_REST_Request $request) {
        $res = new WP_REST_Response([
            'jsonrpc' => '2.0', 'id' => null,
            'error' => ['code' => -32600, 'message' => '本端点不提供 SSE 流，请使用 POST（MCP Streamable HTTP）'],
        ], 405);
        $res->header('Allow', 'POST');
        return $res;
    }

    private static function dispatch($message) {
        if (!is_array($message) || empty($message['method']) || !is_string($message['method'])) {
            return self::rpc_error(is_array($message) && isset($message['id']) ? $message['id'] : null, -32600, 'Invalid Request');
        }
        $id     = array_key_exists('id', $message) ? $message['id'] : null;
        $method = $message['method'];
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        switch ($method) {
            case 'initialize':
                $requested = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '';
                $version   = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::PROTOCOL_VERSION;
                return self::rpc_result($id, [
                    'protocolVersion' => $version,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => [
                        'name'    => 'xhqm-live2d-mcp',
                        'title'   => get_bloginfo('name') . ' · Live2D 看板娘 MCP',
                        'version' => XHQM_L2D_VERSION,
                    ],
                    'instructions'    => sprintf(
                        '站点「%s」的 Live2D 看板娘节点。能力：模型管理（list_models / get_model_capabilities / switch_model / upload_model / delete_model）、配置读写（get_settings / update_settings，密钥字段不可读写）、人设卡（get_persona / update_persona，修改进入待确认草稿）、对话（chat_with_mascot）、语音（tts_speak）、活体控制（mascot_presence / mascot_command，经前台浏览器执行）。鉴权：HTTP Basic（用户名 + 应用密码）。',
                        get_bloginfo('name')
                    ),
                ]);

            case 'ping':
                return self::rpc_result($id, (object) []);

            case 'tools/list':
                return self::rpc_result($id, ['tools' => self::tool_definitions()]);

            case 'tools/call':
                $name = isset($params['name']) ? (string) $params['name'] : '';
                $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
                return self::rpc_result($id, self::call_tool($name, $args));

            default:
                if (strpos($method, 'notifications/') === 0) return null;
                return self::rpc_error($id, -32601, 'Method not found：' . $method);
        }
    }

    private static function respond($payload, $status, WP_REST_Request $request) {
        $res     = new WP_REST_Response($payload, $status);
        $session = $request->get_header('mcp_session_id');
        $res->header('Mcp-Session-Id', $session ? $session : wp_generate_uuid4());
        $res->header('MCP-Protocol-Version', self::PROTOCOL_VERSION);
        return $res;
    }

    private static function rpc_result($id, $result) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function rpc_error($id, $code, $message) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /* ================= 工具目录 ================= */

    /** 工具名 => [所需权限, 中文说明]（公开：设置页据此渲染开关列表） */
    public static function catalog() {
        return [
            'get_widget_status'      => ['read',           '看板娘运行状态总览'],
            'list_models'            => ['read',           '扫描模型目录'],
            'get_model_capabilities' => ['read',           '解析模型的表情与动作清单'],
            'switch_model'           => ['manage_options', '切换当前展出模型'],
            'upload_model'           => ['manage_options', '上传模型 zip 并部署'],
            'delete_model'           => ['manage_options', '清空模型文件（保留目录）'],
            'get_settings'           => ['manage_options', '读取配置（密钥字段除外）'],
            'update_settings'        => ['manage_options', '修改配置（密钥与人设除外）'],
            'get_persona'            => ['manage_options', '读取人设卡与待确认草稿'],
            'update_persona'         => ['manage_options', '提交人设卡修改（进入待确认草稿）'],
            'chat_with_mascot'       => ['edit_posts',     '以人设卡与看板娘对话'],
            'tts_speak'              => ['edit_posts',     '调用 TTS 生成语音（base64）'],
            'mascot_presence'        => ['read',           '前台看板娘在线状态'],
            'mascot_command'         => ['edit_posts',     '向在线看板娘推送活体指令'],
        ];
    }

    /** 工具级开关表（name => bool）。选项不存在时视为全部启用（升级兼容） */
    public static function tool_switches() {
        $saved = get_option(self::TOOLS_OPTION, null);
        $out   = [];
        foreach (self::catalog() as $name => $meta) {
            $out[$name] = !is_array($saved) || !array_key_exists($name, $saved) || (bool) $saved[$name];
        }
        return $out;
    }

    /** 单个工具是否启用 */
    public static function tool_enabled($name) {
        $sw = self::tool_switches();
        return isset($sw[$name]) ? $sw[$name] : true;
    }

    private static function tool_definitions() {
        $defs = [
            'get_widget_status' => [
                'description' => '返回看板娘启用状态、当前模型、画布与位置、各功能开关、密钥是否已配置（不含密钥本体）',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'verbose' => ['type' => 'boolean', 'default' => false, 'description' => '预留参数，当前忽略'],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'list_models' => [
                'description' => '扫描模型目录，返回每个模型的目录名、入口 json、文件数与体积',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'verbose' => ['type' => 'boolean', 'default' => false],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'get_model_capabilities' => [
                'description' => '解析指定模型（默认当前模型）的 model3.json：表情清单、动作分组与数量、贴图数',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'model' => ['type' => 'string', 'description' => '模型目录名，留空为当前展出模型'],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'switch_model' => [
                'description' => '切换当前展出的模型；传空字符串则下线看板娘模型',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'model' => ['type' => 'string', 'description' => '模型目录名（须存在于 list_models 结果中），空字符串下线'],
                ], 'required' => ['model']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => true],
            ],
            'upload_model' => [
                'description' => '上传 Live2D 模型 zip（含 model3.json 的目录打包），自动解压部署到模型目录',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'filename'    => ['type' => 'string', 'description' => 'zip 文件名，如 hiyori.zip'],
                    'data_base64' => ['type' => 'string', 'description' => 'zip 内容的 Base64 编码，解码后不超过 256MB'],
                ], 'required' => ['filename', 'data_base64']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => false],
            ],
            'delete_model' => [
                'description' => '清空指定模型的全部文件，但保留目录本身；若为当前展出模型则同时下线。必填单个模型名，一次只能删除一个模型',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'model' => ['type' => 'string', 'description' => '模型目录名（单值，须在 list_models 结果中）'],
                ], 'required' => ['model']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
            ],
            'get_settings' => [
                'description' => '读取看板娘配置。api_key 与 tts_key 被设计为不可读，返回中不包含',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'verbose' => ['type' => 'boolean', 'default' => false],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'update_settings' => [
                'description' => '修改看板娘配置（画布、位置、开关、限流、temperature 等）。密钥、人设卡、外部 MCP 列表不在可写范围内',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'enabled'        => ['type' => 'boolean', 'description' => '全站显示看板娘'],
                    'canvas_w'       => ['type' => 'integer', 'minimum' => 100, 'maximum' => 1000],
                    'canvas_h'       => ['type' => 'integer', 'minimum' => 100, 'maximum' => 1200],
                    'mobile_scale'   => ['type' => 'integer', 'minimum' => 20, 'maximum' => 100, 'description' => '移动端缩放百分比'],
                    'position'       => ['type' => 'string', 'enum' => ['left', 'right']],
                    'protect_models' => ['type' => 'boolean'],
                    'chat_enabled'   => ['type' => 'boolean'],
                    'tts_enabled'    => ['type' => 'boolean'],
                    'mcp_enabled'    => ['type' => 'boolean', 'description' => '聊天工具调用开关'],
                    'bcast_sound'    => ['type' => 'boolean', 'description' => '广播播报时是否播放语音（关闭则只留文字）'],
                    'temperature'    => ['type' => 'number', 'minimum' => 0, 'maximum' => 2],
                    'ctx_limit'      => ['type' => 'integer', 'minimum' => 500, 'maximum' => 30000],
                    'rate_limit'     => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                ]],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => true],
            ],
            'get_persona' => [
                'description' => '读取当前人设卡，以及由 MCP 提交、等待管理员确认的草稿（如有）',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'verbose' => ['type' => 'boolean', 'default' => false],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'update_persona' => [
                'description' => '提交人设卡修改。不会直接生效：写入待确认草稿，由管理员在后台「设置 → Live2D 看板娘」应用或丢弃',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'persona' => ['type' => 'string', 'description' => '新人设卡全文'],
                    'reason'  => ['type' => 'string', 'description' => '修改理由，展示给管理员参考'],
                ], 'required' => ['persona']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => true],
            ],
            'chat_with_mascot' => [
                'description' => '以站内人设卡与看板娘对话，返回她的回复。她是一个带工具的实例：可调用内置能力（查模型状态/切表情/说话）与站点配置的外部 MCP 工具获取实时数据',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'messages' => ['type' => 'array', 'description' => 'OpenAI 格式的消息数组（role/content），最多取末 12 条',
                        'items' => ['type' => 'object']],
                    'page_text' => ['type' => 'string', 'description' => '可选，模拟页面上下文注入'],
                    'use_tools' => ['type' => 'boolean', 'default' => true, 'description' => '是否允许她调用工具（内置能力 + 外部 MCP），false 为纯对话'],
                ], 'required' => ['messages']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => true],
            ],
            'tts_speak' => [
                'description' => '调用站点已配置的 TTS，把文字转为语音，返回 base64 音频（最长 600 字）；同时向全站广播：所有打开看板娘的页面都会播放并把文字留在对话框里',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'text' => ['type' => 'string', 'description' => '要朗读的文字'],
                ], 'required' => ['text']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => false],
            ],
            'mascot_presence' => [
                'description' => '检查当前是否有浏览器页面正开着看板娘（前台轮询心跳），推送活体指令前应先查询',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'verbose' => ['type' => 'boolean', 'default' => false],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            'mascot_command' => [
                'description' => '向在线看板娘推送活体指令：expression 表情 / motion 动作组 / speak 朗读 / show / hide。经前台浏览器执行，存在数秒轮询延迟',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'action'  => ['type' => 'string', 'enum' => ['expression', 'motion', 'speak', 'show', 'hide']],
                    'value'   => ['type' => 'string', 'description' => 'expression 为表情名（空串恢复默认）；motion 为动作组名；speak 为朗读文本'],
                    'wait'    => ['type' => 'boolean', 'default' => true, 'description' => '是否等待浏览器执行回执'],
                    'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 12],
                ], 'required' => ['action']],
                'annotations' => ['readOnlyHint' => false, 'idempotentHint' => false],
            ],
        ];

        $out = [];
        foreach (self::catalog() as $name => $meta) {
            if (!self::tool_enabled($name)) continue; // 设置里被关掉的工具不出现在清单中
            $def = $defs[$name];
            $def['name'] = $name;
            $out[] = $def;
        }
        return $out;
    }

    /* ================= 工具调度 ================= */

    private static function call_tool($name, array $args) {
        $catalog = self::catalog();
        if (!isset($catalog[$name])) {
            return self::err('未知工具：' . $name . '。可用：' . implode('、', array_keys($catalog)));
        }
        if (!self::tool_enabled($name)) {
            return self::err('工具「' . $name . '」已被站点设置停用。可在「设置 → Live2D 看板娘 → MCP 服务」重新开启');
        }
        list($cap) = $catalog[$name];
        if (!current_user_can($cap)) {
            return self::err('当前账号无权使用工具「' . $name . '」（需要 ' . $cap . ' 权限）');
        }

        try {
            $data = call_user_func([__CLASS__, 'tool_' . $name], $args);
        } catch (Throwable $e) {
            return self::err('执行异常：' . $e->getMessage());
        }
        if (is_wp_error($data)) return self::err($data->get_error_message());

        return [
            'content' => [['type' => 'text', 'text' => wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];
    }

    private static function err($message) {
        return ['content' => [['type' => 'text', 'text' => '错误。' . $message]], 'isError' => true];
    }

    /* ================= 工具实现：状态与模型 ================= */

    private static function tool_get_widget_status(array $args) {
        return [
            'enabled'            => (bool) XHQM_Live2D::opt('enabled'),
            'model'              => XHQM_Live2D::opt('model'),
            'models_dir'         => XHQM_Live2D::opt('models_dir'),
            'canvas'             => ['w' => (int) XHQM_Live2D::opt('canvas_w', 320), 'h' => (int) XHQM_Live2D::opt('canvas_h', 420)],
            'mobile_scale'       => (int) XHQM_Live2D::opt('mobile_scale', 55),
            'position'           => XHQM_Live2D::opt('position', 'right'),
            'protect_models'     => (bool) XHQM_Live2D::opt('protect_models'),
            'chat_enabled'       => (bool) XHQM_Live2D::opt('chat_enabled'),
            'tts_enabled'        => (bool) XHQM_Live2D::opt('tts_enabled'),
            'mcp_tools_enabled'  => (bool) XHQM_Live2D::opt('mcp_enabled'),
            'mcp_server_enabled' => (bool) XHQM_Live2D::opt('mcp_server_enabled', 1),
            'api_key_set'        => XHQM_Live2D::opt('api_key') !== '',
            'tts_key_set'        => XHQM_Live2D::opt('tts_key') !== '',
            'version'            => XHQM_L2D_VERSION,
        ];
    }

    private static function tool_list_models(array $args) {
        $base  = XHQM_Live2D::opt('models_dir');
        $items = [];
        foreach (XHQM_L2D_Settings::scan_models() as $dir => $json) {
            $path = trailingslashit($base) . $dir;
            list($size, $files) = self::dir_stats($path);
            $items[] = [
                'model'     => $dir,
                'entry'     => $json,
                'files'     => $files,
                'size'      => $size,
                'size_h'    => size_format($size),
                'is_active' => XHQM_Live2D::opt('model') === $dir,
            ];
        }
        return ['models_dir' => $base, 'items' => $items];
    }

    private static function tool_get_model_capabilities(array $args) {
        $model = isset($args['model']) ? sanitize_file_name((string) $args['model']) : XHQM_Live2D::opt('model');
        if (!$model) return new WP_Error('no_model', '未指定模型，且当前没有展出中的模型');
        if ($model === '.' || $model === '..' || strpos($model, '/') !== false || strpos($model, '\\') !== false) {
            return new WP_Error('invalid', '非法模型名');
        }

        $jsons = glob(trailingslashit(XHQM_Live2D::opt('models_dir')) . $model . '/*.model3.json');
        if (!$jsons) return new WP_Error('not_found', '模型不存在或缺少 model3.json：' . $model);

        $data = json_decode(file_get_contents($jsons[0]), true);
        if (!is_array($data)) return new WP_Error('bad_json', 'model3.json 解析失败');

        $refs        = $data['FileReferences'] ?? [];
        $motions     = [];
        foreach (($refs['Motions'] ?? []) as $group => $list) {
            $motions[$group] = is_array($list) ? count($list) : 0;
        }
        $expressions = [];
        foreach (($refs['Expressions'] ?? []) as $exp) {
            if (isset($exp['Name'])) $expressions[] = $exp['Name'];
        }
        return [
            'model'       => $model,
            'moc'         => $refs['Moc'] ?? null,
            'textures'    => isset($refs['Textures']) && is_array($refs['Textures']) ? count($refs['Textures']) : 0,
            'expressions' => $expressions,
            'motions'     => $motions,
        ];
    }

    /** 公开包装：模型能力清单（REST 聊天实例的服务端工具复用）；空串 = 当前展出模型 */
    public static function capabilities($model = '') {
        $args = ($model === '') ? [] : ['model' => $model];
        return self::tool_get_model_capabilities($args);
    }

    private static function tool_switch_model(array $args) {
        $model = isset($args['model']) ? sanitize_file_name($args['model']) : '';
        if ($model !== '') {
            $known = array_keys(XHQM_L2D_Settings::scan_models());
            if (!in_array($model, $known, true)) {
                return new WP_Error('not_found', '模型不存在：' . $model . '。可用：' . implode('、', $known));
            }
        }
        $opts = get_option('xhqm_l2d_options', []);
        $opts['model'] = $model;
        update_option('xhqm_l2d_options', $opts);
        return ['model' => $model, 'note' => $model === '' ? '模型已下线' : '已切换，前台刷新后生效'];
    }

    /* ================= 工具实现：上传与删除 ================= */

    private static function tool_upload_model(array $args) {
        if (empty($args['filename']) || empty($args['data_base64'])) {
            return new WP_Error('invalid', '缺少必填参数：filename 与 data_base64');
        }
        $filename = sanitize_file_name($args['filename']);
        if (strtolower(substr($filename, -4)) !== '.zip') {
            return new WP_Error('invalid', '仅支持 zip 打包的模型');
        }
        $binary = base64_decode($args['data_base64'], true);
        if ($binary === false) return new WP_Error('invalid', 'data_base64 不是合法的 Base64 数据');
        if (strlen($binary) > self::UPLOAD_MAX) {
            return new WP_Error('too_large', 'zip 超过 256MB 上限');
        }

        $base   = trailingslashit(XHQM_Live2D::opt('models_dir'));
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return new WP_Error('io', '模型目录不存在且无法创建：' . $base);
        }
        $target_name = preg_replace('/\.zip$/i', '', $filename);
        if (is_dir($base . $target_name)) {
            return new WP_Error('exists', '同名模型目录已存在：' . $target_name . '。如需覆盖请先 delete_model');
        }

        $tmp_zip = wp_tempnam($filename);
        file_put_contents($tmp_zip, $binary);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        $tmp_dest = $base . '.upload-' . wp_generate_uuid4();
        wp_mkdir_p($tmp_dest);
        $unzipped = unzip_file($tmp_zip, $tmp_dest);
        @unlink($tmp_zip);
        if (is_wp_error($unzipped)) {
            self::clear_dir($tmp_dest);
            @rmdir($tmp_dest);
            return $unzipped;
        }

        // 定位模型根：优先解压顶层，其次唯一的子目录
        $root = $tmp_dest;
        if (!glob($tmp_dest . '/*.model3.json')) {
            $subs = glob($tmp_dest . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($subs as $sub) {
                if (glob($sub . '/*.model3.json')) { $root = $sub; break; }
            }
        }
        if (!glob($root . '/*.model3.json')) {
            self::clear_dir($tmp_dest);
            @rmdir($tmp_dest);
            return new WP_Error('no_model', 'zip 中未找到 model3.json（顶层或单层子目录均可）');
        }

        $final_name = $root === $tmp_dest ? $target_name : basename($root);
        $final      = $base . sanitize_file_name($final_name);
        if (is_dir($final)) {
            self::clear_dir($tmp_dest);
            @rmdir($tmp_dest);
            return new WP_Error('exists', '模型目录已存在：' . basename($final));
        }
        if (!rename($root, $final)) {
            self::clear_dir($tmp_dest);
            @rmdir($tmp_dest);
            return new WP_Error('io', '部署失败：无法移动到 ' . $final);
        }
        self::clear_dir($tmp_dest);
        @rmdir($tmp_dest);

        list($size, $files) = self::dir_stats($final);
        return [
            'model'  => basename($final),
            'files'  => $files,
            'size_h' => size_format($size),
            'note'   => '部署完成，可用 switch_model 上线',
        ];
    }

    private static function tool_delete_model(array $args) {
        // 规则：model 必填，单值，一次只能删除一个模型
        if (empty($args['model']) || is_array($args['model'])) {
            return new WP_Error('invalid', '参数 model 必填且为单个模型名；本工具一次只删除一个模型，不接受批量');
        }
        $model = sanitize_file_name((string) $args['model']);
        if ($model === '' || $model === '.' || $model === '..' || strpos($model, '/') !== false || strpos($model, '\\') !== false) {
            return new WP_Error('invalid', '非法模型名');
        }
        // 白名单：只允许删除扫描得到的模型（含 model3.json 的目录）
        $known = array_keys(XHQM_L2D_Settings::scan_models());
        if (!in_array($model, $known, true)) {
            return new WP_Error('not_found', '模型不存在：' . $model . '。可用：' . ( $known ? implode('、', $known) : '（空）' ));
        }
        $dir = trailingslashit(XHQM_Live2D::opt('models_dir')) . $model;

        // 约定：清空全部文件与子目录，但保留目录本身
        $deleted = self::clear_dir($dir);

        $result = ['model' => $model, 'deleted_files' => $deleted, 'directory' => '保留'];
        if (XHQM_Live2D::opt('model') === $model) {
            $opts = get_option('xhqm_l2d_options', []);
            $opts['model'] = '';
            update_option('xhqm_l2d_options', $opts);
            $result['note'] = '该模型原本正在展出，已同步下线';
        }
        return $result;
    }

    /* ================= 工具实现：配置与人设 ================= */

    /** 可读配置白名单（密钥永不在列） */
    private static function readable_keys() {
        return ['enabled', 'models_dir', 'model', 'canvas_w', 'canvas_h', 'mobile_scale', 'position',
                'protect_models', 'chat_enabled', 'api_base', 'api_model', 'temperature',
                'ctx_limit', 'rate_limit', 'tts_enabled', 'tts_provider', 'tts_base', 'tts_model',
                'tts_voice', 'mcp_enabled', 'mcp_server_enabled', 'bcast_sound'];
    }

    /** 可写配置白名单与校验规则 */
    private static function writable_rules() {
        return [
            'enabled'        => 'bool',
            'canvas_w'       => ['int', 100, 1000],
            'canvas_h'       => ['int', 100, 1200],
            'mobile_scale'   => ['int', 20, 100],
            'position'       => ['enum', ['left', 'right']],
            'protect_models' => 'bool',
            'chat_enabled'   => 'bool',
            'tts_enabled'    => 'bool',
            'mcp_enabled'    => 'bool',
            'bcast_sound'    => 'bool',
            'temperature'    => ['float', 0, 2],
            'ctx_limit'      => ['int', 500, 30000],
            'rate_limit'     => ['int', 1, 1000],
        ];
    }

    private static function tool_get_settings(array $args) {
        $out = [];
        foreach (self::readable_keys() as $k) {
            $out[$k] = XHQM_Live2D::opt($k);
        }
        $out['_note'] = 'api_key 与 tts_key 被设计为不可读，不在返回范围内';
        return $out;
    }

    private static function tool_update_settings(array $args) {
        $forbidden = array_intersect(array_keys($args), ['api_key', 'tts_key', 'persona', 'mcp_servers', 'models_dir']);
        if ($forbidden) {
            return new WP_Error('forbidden', '以下字段不接受 MCP 写入：' . implode('、', $forbidden) . '（密钥永不可写；人设卡请用 update_persona；其余请在后台修改）');
        }

        $opts    = get_option('xhqm_l2d_options', []);
        $rules   = self::writable_rules();
        $updated = [];
        foreach ($args as $key => $value) {
            if (!isset($rules[$key])) continue;
            $rule = $rules[$key];
            if ($rule === 'bool') {
                $opts[$key] = $value ? 1 : 0;
            } elseif ($rule[0] === 'int') {
                $opts[$key] = max($rule[1], min($rule[2], (int) $value));
            } elseif ($rule[0] === 'float') {
                $opts[$key] = max($rule[1], min($rule[2], (float) $value));
            } elseif ($rule[0] === 'enum') {
                if (!in_array($value, $rule[1], true)) {
                    return new WP_Error('invalid', $key . ' 仅支持：' . implode(' / ', $rule[1]));
                }
                $opts[$key] = $value;
            }
            $updated[] = $key;
        }
        if (!$updated) return new WP_Error('invalid', '未提供任何可写字段。可写：' . implode('、', array_keys($rules)));

        update_option('xhqm_l2d_options', $opts);
        return ['updated' => $updated, 'note' => '已生效，前台刷新后应用'];
    }

    private static function tool_get_persona(array $args) {
        $pending = get_option('xhqm_l2d_persona_pending', '');
        return [
            'persona'        => XHQM_Live2D::opt('persona'),
            'pending_draft'  => $pending !== '' ? json_decode($pending, true) : null,
            '_rule'          => 'MCP 对人设卡的修改一律进入待确认草稿，由管理员在后台应用后生效',
        ];
    }

    private static function tool_update_persona(array $args) {
        if (empty($args['persona'])) return new WP_Error('invalid', '缺少必填参数：persona');

        $draft = [
            'persona' => sanitize_textarea_field($args['persona']),
            'reason'  => isset($args['reason']) ? sanitize_text_field($args['reason']) : '',
            'by'      => wp_get_current_user()->user_login,
            'time'    => current_time('mysql'),
        ];
        update_option('xhqm_l2d_persona_pending', wp_json_encode($draft, JSON_UNESCAPED_UNICODE), false);

        return [
            'status' => 'pending',
            'note'   => '已写入待确认草稿，管理员在「设置 → Live2D 看板娘」应用后生效。当前生效人设不会被直接改动',
        ];
    }

    /* ================= 工具实现：对话与语音 ================= */

    private static function tool_chat_with_mascot(array $args) {
        if (empty($args['messages']) || !is_array($args['messages'])) {
            return new WP_Error('invalid', '缺少必填参数：messages（OpenAI 格式数组）');
        }
        $page = null;
        if (!empty($args['page_text'])) {
            $page = ['title' => isset($args['page_title']) ? sanitize_text_field($args['page_title']) : '', 'text' => $args['page_text']];
        }
        $use_tools = !isset($args['use_tools']) || (bool) $args['use_tools'];
        return XHQM_L2D_REST::chat_direct($args['messages'], $page, $use_tools);
    }

    private static function tool_tts_speak(array $args) {
        if (empty($args['text'])) return new WP_Error('invalid', '缺少必填参数：text');
        $result = XHQM_L2D_REST::synthesize($args['text']);
        if (is_wp_error($result)) return $result;
        $result['bytes'] = (int) (strlen($result['audio']) * 3 / 4);

        // 广播：全站活着的实例都播放，文字留在对话框里
        $bc_id    = self::broadcast('speak_broadcast', (string) $args['text']);
        $presence = self::presence_status();
        $result['broadcast'] = [
            'pushed' => true,
            'cmd_id' => $bc_id,
            'online' => !empty($presence['online']),
            'note'   => !empty($presence['online'])
                ? '指令已入列，所有打开看板娘的页面将在数秒内播报，文字同步进入对话框'
                : '当前没有在线实例，指令已入列；' . self::BCAST_WINDOW . ' 秒内有页面打开仍会补播',
        ];
        return $result;
    }

    /* ================= 工具实现：活体控制 ================= */

    private static function tool_mascot_presence(array $args) {
        return self::presence_status();
    }

    private static function tool_mascot_command(array $args) {
        $action = isset($args['action']) ? sanitize_key($args['action']) : '';
        if (!in_array($action, ['expression', 'motion', 'speak', 'show', 'hide'], true)) {
            return new WP_Error('invalid', 'action 仅支持 expression / motion / speak / show / hide');
        }
        $value = isset($args['value']) ? (string) $args['value'] : '';

        // 指令内容校验：表情与动作必须真实存在，speak 必须有文本
        if ($action === 'speak' && trim($value) === '') {
            return new WP_Error('invalid', 'speak 指令需要 value（朗读文本）');
        }
        if (in_array($action, ['expression', 'motion'], true) && $value !== '') {
            $caps = self::tool_get_model_capabilities([]);
            if (!is_wp_error($caps)) {
                if ($action === 'expression' && !in_array($value, $caps['expressions'], true)) {
                    return new WP_Error('invalid', '没有这个表情：' . $value . '。可用：' . implode('、', $caps['expressions']));
                }
                if ($action === 'motion' && !isset($caps['motions'][$value])) {
                    return new WP_Error('invalid', '没有这个动作组：' . $value . '。可用：' . implode('、', array_keys($caps['motions'])));
                }
            }
        }

        $presence = self::presence_status();
        if (empty($presence['online'])) {
            return new WP_Error('offline', '当前没有打开看板娘的页面。无节点之处，她不在');
        }

        $cmd = [
            'id'     => wp_generate_uuid4(),
            'action' => $action,
            'value'  => $value,
            'status' => 'queued',
            'result' => null,
            'ts'     => time(),
        ];
        self::queue_save($cmd);

        $wait    = !isset($args['wait']) || $args['wait'];
        $timeout = max(1, min(25, isset($args['timeout']) ? (int) $args['timeout'] : 12));
        if (!$wait) {
            return ['id' => $cmd['id'], 'status' => 'queued', 'note' => '指令已入队，前台将在数秒内轮询执行'];
        }

        $deadline = time() + $timeout;
        while (time() < $deadline) {
            usleep(500000);
            wp_cache_delete(self::QUEUE_OPTION, 'options'); // 强制读库，拿最新回执
            $queue = get_option(self::QUEUE_OPTION, []);
            if (isset($queue[$cmd['id']]) && $queue[$cmd['id']]['status'] === 'done') {
                return [
                    'id'     => $cmd['id'],
                    'status' => 'done',
                    'result' => $queue[$cmd['id']]['result'],
                ];
            }
        }
        return ['id' => $cmd['id'], 'status' => 'timeout', 'note' => '等待回执超时，指令可能仍在队列中'];
    }

    /* ================= 指令队列（前台轮询配套） ================= */

    public static function queue_save(array $cmd) {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) $queue = [];
        // 清理过期记录
        foreach ($queue as $id => $item) {
            if (empty($item['ts']) || $item['ts'] < time() - self::CMD_TTL) unset($queue[$id]);
        }
        $queue[$cmd['id']] = $cmd;
        update_option(self::QUEUE_OPTION, $queue, false);
    }

    /**
     * 广播一条指令：窗口期内所有活着的实例都会收到（不等待回执）
     * 返回指令 id
     */
    public static function broadcast($action, $value) {
        $cmd = [
            'id'     => wp_generate_uuid4(),
            'action' => (string) $action,
            'value'  => $value,
            'status' => 'bcast',
            'result' => null,
            'bcast'  => true,
            'ts'     => time(),
        ];
        self::queue_save($cmd);
        return $cmd['id'];
    }

    /** 前台轮询：登记心跳 + 返回待执行指令（普通指令标记 sent；广播在窗口期内对所有实例可见） */
    public static function queue_poll() {
        set_transient(self::PRESENCE_TRANSIENT, ['ts' => time()], self::PRESENCE_TTL);

        $queue   = get_option(self::QUEUE_OPTION, []);
        $pending = [];
        $changed = false;
        $now     = time();
        foreach ($queue as $id => &$item) {
            if (!empty($item['bcast'])) {
                // 广播：状态不推进，窗口期内每个实例都能取到；前端按 id 去重
                if (!empty($item['ts']) && $now - (int) $item['ts'] <= self::BCAST_WINDOW) {
                    $pending[] = ['id' => $id, 'action' => $item['action'], 'value' => $item['value'], 'bcast' => true];
                }
                continue;
            }
            if (in_array($item['status'], ['queued', 'sent'], true)) {
                $pending[] = ['id' => $id, 'action' => $item['action'], 'value' => $item['value']];
                if ($item['status'] === 'queued') { $item['status'] = 'sent'; $changed = true; }
            }
        }
        unset($item);
        if ($changed) update_option(self::QUEUE_OPTION, $queue, false);
        return $pending;
    }

    /** 前台回执 */
    public static function queue_ack($id, $result) {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!isset($queue[$id])) return false;
        $queue[$id]['status'] = 'done';
        $queue[$id]['result'] = mb_substr((string) $result, 0, 500);
        update_option(self::QUEUE_OPTION, $queue, false);
        return true;
    }

    public static function presence_status() {
        $p = get_transient(self::PRESENCE_TRANSIENT);
        if (!is_array($p) || empty($p['ts'])) return ['online' => false];
        $age = time() - (int) $p['ts'];
        return [
            'online'          => $age <= self::PRESENCE_ONLINE,
            'last_seen_ago_s' => $age,
        ];
    }

    /* ================= 文件系统辅助 ================= */

    /** 清空目录内容（保留目录本身），返回删除的文件数 */
    private static function clear_dir($dir) {
        $count = 0;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += self::clear_dir($path);
                @rmdir($path);
            } else {
                if (@unlink($path)) $count++;
            }
        }
        return $count;
    }

    /** 目录体积与文件数 */
    private static function dir_stats($dir) {
        $size = 0; $files = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) { $size += $f->getSize(); $files++; }
        }
        return [$size, $files];
    }
}
