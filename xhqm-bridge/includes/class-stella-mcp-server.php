<?php
/**
 * MCP 协议端点：Streamable HTTP 传输，POST JSON-RPC 2.0
 *
 * @package Stella_MCP_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stella_MCP_Server {

	const PROTOCOL_VERSION    = '2025-03-26';
	const SUPPORTED_VERSIONS  = array( '2024-11-05', '2025-03-26', '2025-06-18' );

	/**
	 * 默认配置：工具目录来自 Stella_MCP_Tools，新增工具默认启用
	 */
	public static function defaults() {
		$tools = array();
		foreach ( Stella_MCP_Tools::catalog() as $name => $label ) {
			$tools[ $name ] = 1;
		}
		return array(
			'enabled'        => 1,
			'allow_password' => 0,
			'tools'          => $tools,
		);
	}

	/**
	 * 读取配置（合并默认值；tools 子数组单独合并，保证升级后新工具默认可用）
	 */
	public static function options() {
		$defaults        = self::defaults();
		$saved           = (array) get_option( 'stella_mcp_options', array() );
		$options         = wp_parse_args( $saved, $defaults );
		$saved_tools     = isset( $saved['tools'] ) ? (array) $saved['tools'] : array();
		$options['tools'] = wp_parse_args( $saved_tools, $defaults['tools'] );
		return $options;
	}

	/**
	 * 注册路由
	 */
	public static function register_routes() {
		register_rest_route(
			'mcp/v1',
			'/server',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => array( __CLASS__, 'authenticate' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle_get' ),
					'permission_callback' => array( __CLASS__, 'authenticate' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => function () {
						return new WP_REST_Response( (object) array(), 200 );
					},
					'permission_callback' => array( __CLASS__, 'authenticate' ),
				),
			)
		);
	}

	/**
	 * 401 响应附带 WWW-Authenticate 质询头
	 */
	public static function add_auth_challenge( $response, $server, $request ) {
		if ( 401 === $response->get_status() && 0 === strpos( $request->get_route(), '/mcp/v1' ) ) {
			$response->header( 'WWW-Authenticate', 'Basic realm="Stella MCP", charset="UTF-8"' );
		}
		return $response;
	}

	/**
	 * 鉴权：HTTP Basic —— 站点已有用户的「用户名 + 应用密码」（推荐）
	 * 可在设置中额外允许「用户名 + 登录密码」
	 *
	 * @param WP_REST_Request $request 请求对象
	 * @return true|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$options = self::options();

		if ( empty( $options['enabled'] ) ) {
			return new WP_Error(
				'stella_mcp_disabled',
				'MCP 服务已被管理员停用',
				array( 'status' => 503 )
			);
		}

		// 已通过 Cookie / 其他机制登录
		if ( is_user_logged_in() ) {
			return true;
		}

		$header = $request->get_header( 'authorization' );
		if ( empty( $header ) || 0 !== stripos( $header, 'Basic ' ) ) {
			return new WP_Error(
				'stella_mcp_auth_required',
				'需要 HTTP Basic 鉴权：Authorization: Basic base64(用户名:应用密码)',
				array( 'status' => 401 )
			);
		}

		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return new WP_Error(
				'stella_mcp_auth_malformed',
				'Authorization 头格式错误，应为 Basic base64(用户名:密码)',
				array( 'status' => 401 )
			);
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );

		// 优先校验应用密码。展示用空格不属于密钥本体，先剔除
		$app_user = wp_authenticate_application_password( null, $username, str_replace( ' ', '', $password ) );
		if ( $app_user instanceof WP_User ) {
			wp_set_current_user( $app_user->ID );
			return true;
		}

		// 可选：常规登录密码（默认关闭，设置页开启）
		if ( ! empty( $options['allow_password'] ) ) {
			$user = wp_authenticate( $username, $password );
			if ( $user instanceof WP_User && ! is_wp_error( $user ) ) {
				wp_set_current_user( $user->ID );
				return true;
			}
		}

		return new WP_Error(
			'stella_mcp_auth_failed',
			'鉴权失败：用户名或密码无效。建议使用「应用密码」而非登录密码',
			array( 'status' => 403 )
		);
	}

	/**
	 * POST：JSON-RPC 2.0 消息处理
	 *
	 * @param WP_REST_Request $request 请求对象
	 * @return WP_REST_Response
	 */
	public static function handle_post( WP_REST_Request $request ) {
		$data = json_decode( $request->get_body(), true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return self::response( self::rpc_error( null, -32700, 'Parse error：请求体不是合法 JSON' ), 200, $request );
		}

		$is_batch = wp_is_numeric_array( $data );
		$messages = $is_batch ? $data : array( $data );

		if ( empty( $messages ) ) {
			return self::response( self::rpc_error( null, -32600, 'Invalid Request：空的批量请求' ), 200, $request );
		}

		$results = array();
		foreach ( $messages as $message ) {
			$result = self::dispatch( $message );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}

		// 全部为通知：202，无响应体
		if ( empty( $results ) ) {
			return self::response( null, 202, $request );
		}

		return self::response( $is_batch ? $results : $results[0], 200, $request );
	}

	/**
	 * GET：本端点不提供 SSE 流
	 *
	 * @param WP_REST_Request $request 请求对象
	 * @return WP_REST_Response
	 */
	public static function handle_get( WP_REST_Request $request ) {
		$res = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32600,
					'message' => '本端点不提供 SSE 流，请使用 POST（MCP Streamable HTTP）',
				),
			),
			405
		);
		$res->header( 'Allow', 'POST' );
		return $res;
	}

	/**
	 * 分发单条 JSON-RPC 消息
	 *
	 * @param mixed $message 消息体
	 * @return array|null null 表示通知，无需响应
	 */
	private static function dispatch( $message ) {
		if ( ! is_array( $message ) || empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return self::rpc_error(
				( is_array( $message ) && isset( $message['id'] ) ) ? $message['id'] : null,
				-32600,
				'Invalid Request'
			);
		}

		$id     = array_key_exists( 'id', $message ) ? $message['id'] : null;
		$method = $message['method'];
		$params = ( isset( $message['params'] ) && is_array( $message['params'] ) ) ? $message['params'] : array();

		switch ( $method ) {
			case 'initialize':
				$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				$version   = in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSION;
				$options   = self::options();
				$enabled   = array();
				foreach ( Stella_MCP_Tools::catalog() as $tool_name => $tool_label ) {
					if ( ! empty( $options['tools'][ $tool_name ] ) ) {
						$enabled[] = $tool_name;
					}
				}
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => array(
							'tools' => array( 'listChanged' => false ),
						),
						'serverInfo'      => array(
							'name'    => 'stella-mcp-bridge',
							'title'   => get_bloginfo( 'name' ) . ' · MCP Bridge',
							'version' => STELLA_MCP_VERSION,
						),
						'instructions'    => sprintf(
							'站点「%s」的 MCP 节点。已启用工具：%s。鉴权：HTTP Basic（站点用户名 + 应用密码），权限继承该账号的 WordPress 角色边界。',
							get_bloginfo( 'name' ),
							implode( '、', $enabled )
						),
					)
				);

			case 'ping':
				return self::rpc_result( $id, (object) array() );

			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => Stella_MCP_Tools::definitions() ) );

			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				$args = ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) ? $params['arguments'] : array();
				return self::rpc_result( $id, Stella_MCP_Tools::call( $name, $args ) );

			default:
				if ( 0 === strpos( $method, 'notifications/' ) ) {
					return null; // 通知，无需响应
				}
				return self::rpc_error( $id, -32601, 'Method not found：' . $method );
		}
	}

	/**
	 * 组装 REST 响应，附带 MCP 会话头
	 *
	 * @param mixed           $payload 响应体
	 * @param int             $status  HTTP 状态码
	 * @param WP_REST_Request $request 请求对象
	 * @return WP_REST_Response
	 */
	private static function response( $payload, $status, WP_REST_Request $request ) {
		$res     = new WP_REST_Response( $payload, $status );
		$session = $request->get_header( 'mcp_session_id' );
		$res->header( 'Mcp-Session-Id', $session ? $session : wp_generate_uuid4() );
		$res->header( 'MCP-Protocol-Version', self::PROTOCOL_VERSION );
		return $res;
	}

	private static function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
