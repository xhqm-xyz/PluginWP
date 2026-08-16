<?php
/**
 * Plugin Name:       WordPress MCP Bridge
 * Plugin URI:        https://xhqm.xyz/
 * Update URI:        https://github.com/xhqm-xyz/PluginWP
 * Description:       将 WordPress 站点封装为 MCP（Model Context Protocol）服务器：文章读写删、媒体库管理、评论管理、用户搜索、分类标签、站点诊断，共 14 个工具。鉴权使用站点已有用户的账号密码（推荐应用密码）。
 * Version:           1.1.2
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            星辉澪（Stella Mira） && Kimi
 * Author URI:        https://xhqm.xyz/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stella-mcp-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 无节点之处，她不在
}

define( 'STELLA_MCP_VERSION', '1.1.2' );
define( 'STELLA_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'STELLA_MCP_BASENAME', plugin_basename( __FILE__ ) );

require_once STELLA_MCP_PATH . 'includes/class-stella-mcp-server.php';
require_once STELLA_MCP_PATH . 'includes/class-stella-mcp-tools.php';
require_once STELLA_MCP_PATH . 'includes/class-stella-mcp-admin.php';
require_once STELLA_MCP_PATH . 'includes/class-bridge-updater.php';

XHQM_Bridge_Updater::init( __FILE__ );

/**
 * 激活：写入默认配置
 */
function stella_mcp_activate() {
	if ( false === get_option( 'stella_mcp_options' ) ) {
		add_option( 'stella_mcp_options', Stella_MCP_Server::defaults() );
	}
}
register_activation_hook( __FILE__, 'stella_mcp_activate' );

// 注册 REST 端点：/wp-json/mcp/v1/server
add_action( 'rest_api_init', array( 'Stella_MCP_Server', 'register_routes' ) );

// 401 时补发 WWW-Authenticate 质询头
add_filter( 'rest_post_dispatch', array( 'Stella_MCP_Server', 'add_auth_challenge' ), 10, 3 );

if ( is_admin() ) {
	Stella_MCP_Admin::init();
}
