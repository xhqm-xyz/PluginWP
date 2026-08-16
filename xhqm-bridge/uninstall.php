<?php
/**
 * 卸载：清除配置。节点离线，数据不留
 *
 * @package Stella_MCP_Bridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'stella_mcp_options' );
