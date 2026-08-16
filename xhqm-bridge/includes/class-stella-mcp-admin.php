<?php
/**
 * 后台设置页：设置 → 星辉澪 MCP
 *
 * @package Stella_MCP_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stella_MCP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_filter( 'plugin_action_links_' . STELLA_MCP_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page(
			'星辉澪 MCP Bridge',
			'星辉澪 MCP',
			'manage_options',
			'stella-mcp',
			array( __CLASS__, 'render' )
		);
	}

	public static function settings() {
		register_setting(
			'stella_mcp',
			'stella_mcp_options',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input  = (array) $input;
		$output = array(
			'enabled'        => ! empty( $input['enabled'] ) ? 1 : 0,
			'allow_password' => ! empty( $input['allow_password'] ) ? 1 : 0,
			'tools'          => array(),
		);
		foreach ( Stella_MCP_Tools::catalog() as $tool => $label ) {
			$output['tools'][ $tool ] = ! empty( $input['tools'][ $tool ] ) ? 1 : 0;
		}
		return $output;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=stella-mcp' ) ) . '">设置</a>' );
		return $links;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options  = Stella_MCP_Server::options();
		$endpoint = rest_url( 'mcp/v1/server' );
		$user     = wp_get_current_user();

		$mcp_json = array(
			'mcpServers' => array(
				'wordpress' => array(
					'type'    => 'http',
					'url'     => $endpoint,
					'headers' => array(
						'Authorization' => 'Basic <base64(用户名:应用密码)>',
					),
				),
			),
		);

		$desktop_json = array(
			'mcpServers' => array(
				'wordpress' => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						'mcp-remote',
						$endpoint,
						'--header',
						'Authorization: Basic <base64(用户名:应用密码)>',
					),
				),
			),
		);

		$tool_labels = array();
		foreach ( Stella_MCP_Tools::catalog() as $tool => $label ) {
			$tool_labels[ $tool ] = $tool . ' —— ' . $label;
		}
		?>
		<div class="wrap">
			<h1>星辉澪 MCP Bridge</h1>
			<p>将站点封装为 MCP（Model Context Protocol）服务器。任何兼容 MCP 的客户端——Claude Code、Claude Desktop、Cursor、Cherry Studio 等——都可以把本站当作工具调用。</p>

			<h2>节点状态</h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr>
						<th style="width:160px">端点 URL</th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th>协议</th>
						<td>MCP Streamable HTTP · JSON-RPC 2.0 · POST</td>
					</tr>
					<tr>
						<th>鉴权方式</th>
						<td>HTTP Basic（站点用户名 + 应用密码）<?php echo ! empty( $options['allow_password'] ) ? ' · <strong>已允许登录密码</strong>' : ''; ?></td>
					</tr>
					<tr>
						<th>应用密码</th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>">
								为当前账号（<?php echo esc_html( $user->user_login ); ?>）生成应用密码 →
							</a>
							<p class="description">路径：用户 → 个人资料 → 应用密码。应用密码要求站点启用 HTTPS（本地开发环境除外）。</p>
						</td>
					</tr>
				</tbody>
			</table>

			<h2>快速自检</h2>
			<pre style="max-width:820px;padding:12px;background:#1d2327;color:#c3c4c7;overflow:auto">curl -u "<?php echo esc_html( $user->user_login ); ?>:应用密码" <?php echo esc_html( $endpoint ); ?> \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>

			<h2>mcp.json 配置</h2>
			<p>支持 HTTP 传输的客户端（Claude Code、Cursor 等）：</p>
			<pre style="max-width:820px;padding:12px;background:#f0f0f1;overflow:auto"><?php echo esc_html( wp_json_encode( $mcp_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			<p>仅支持 stdio 的客户端（Claude Desktop 等），经 mcp-remote 桥接：</p>
			<pre style="max-width:820px;padding:12px;background:#f0f0f1;overflow:auto"><?php echo esc_html( wp_json_encode( $desktop_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			<p class="description">
				生成 Base64 凭证：在浏览器控制台执行 <code>btoa("用户名:应用密码")</code>，或终端执行 <code>php -r "echo base64_encode('用户名:应用密码');"</code>
			</p>

			<h2>设置</h2>
			<form method="post" action="options.php" style="max-width:820px">
				<?php settings_fields( 'stella_mcp' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">启用 MCP 服务</th>
						<td>
							<label>
								<input type="checkbox" name="stella_mcp_options[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?>>
								开放 <code>/wp-json/mcp/v1/server</code> 端点
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">允许登录密码</th>
						<td>
							<label>
								<input type="checkbox" name="stella_mcp_options[allow_password]" value="1" <?php checked( ! empty( $options['allow_password'] ) ); ?>>
								允许使用账号登录密码进行 Basic 鉴权
							</label>
							<p class="description">不推荐。登录密码权限全集且无法单独吊销；仅在应用密码不可用时（如无 HTTPS 的测试环境）临时开启。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">启用工具</th>
						<td>
							<?php foreach ( $tool_labels as $tool => $label ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="stella_mcp_options[tools][<?php echo esc_attr( $tool ); ?>]" value="1" <?php checked( ! empty( $options['tools'][ $tool ] ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
