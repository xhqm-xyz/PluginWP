<?php
/**
 * GitHub 自动更新器：以 xhqm-xyz/PluginWP 仓库为更新源。
 *
 * 原理：轮询仓库中 xhqm-live2d/xhqm-live2d.php 的 Version 头，
 * 高于本地版本时经 WordPress 原生更新通道分发仓库 zipball。
 * 无外部依赖；公开仓库，无需鉴权。
 */

if (!defined('ABSPATH')) exit;

final class XHQM_L2D_Updater {

    const GITHUB_USER   = 'xhqm-xyz';
    const GITHUB_REPO   = 'PluginWP';
    const GITHUB_BRANCH = 'main';
    const PLUGIN_DIR    = 'xhqm-live2d';   // 插件在仓库中的子目录
    const CACHE_KEY     = 'xhqm_l2d_update_cache';
    const CACHE_TTL     = 43200;           // 12 小时

    private static $plugin_file;           // xhqm-live2d/xhqm-live2d.php

    public static function init($main_file) {
        self::$plugin_file = plugin_basename($main_file);

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check']);
        add_filter('plugins_api', [__CLASS__, 'info'], 10, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_source'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache']);
    }

    /** 远端版本（12 小时缓存） */
    private static function remote() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached;

        $raw_url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s/xhqm-live2d.php',
            self::GITHUB_USER, self::GITHUB_REPO, self::GITHUB_BRANCH, self::PLUGIN_DIR
        );
        $resp = wp_remote_get($raw_url, ['timeout' => 10, 'user-agent' => 'xhqm-live2d-updater']);
        $data = ['checked' => time(), 'version' => '', 'error' => false];

        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', wp_remote_retrieve_body($resp), $m)) {
                $data['version'] = trim($m[1]);
            }
        } else {
            $data['error'] = true;
        }
        set_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }

    private static function package_url() {
        return sprintf(
            'https://api.github.com/repos/%s/%s/zipball/%s',
            self::GITHUB_USER, self::GITHUB_REPO, self::GITHUB_BRANCH
        );
    }

    /** 注入更新信息 */
    public static function check($transient) {
        if (empty($transient->checked)) return $transient;

        $remote = self::remote();
        if ($remote['error'] || empty($remote['version'])) return $transient;

        if (version_compare($remote['version'], XHQM_L2D_VERSION, '>')) {
            $transient->response[self::$plugin_file] = (object) [
                'slug'        => self::PLUGIN_DIR,
                'plugin'      => self::$plugin_file,
                'new_version' => $remote['version'],
                'url'         => sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO),
                'package'     => self::package_url(),
            ];
        } else {
            $transient->no_update[self::$plugin_file] = (object) [
                'slug'        => self::PLUGIN_DIR,
                'plugin'      => self::$plugin_file,
                'new_version' => XHQM_L2D_VERSION,
                'url'         => sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO),
                'package'     => '',
            ];
        }
        return $transient;
    }

    /** 「查看详情」弹窗 */
    public static function info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_DIR) {
            return $result;
        }
        $remote = self::remote();
        return (object) [
            'name'          => 'XHQM Live2D 看板娘',
            'slug'          => self::PLUGIN_DIR,
            'version'       => $remote['version'] ?: XHQM_L2D_VERSION,
            'author'        => '星辉澪（Stella Mira）&& Kimi',
            'homepage'      => sprintf('https://github.com/%s/%s', self::GITHUB_USER, self::GITHUB_REPO),
            'download_link' => self::package_url(),
            'requires'      => '5.0',
            'requires_php'  => '7.4',
            'sections'      => [
                'description' => '全站 Live2D 看板娘插件。更新由 GitHub 仓库 xhqm-xyz/PluginWP 分发，模型数据不包含在分发包内。',
            ],
        ];
    }

    /**
     * 仓库 zipball 的根目录是 PluginWP-<sha>/，插件在其 xhqm-live2d/ 子目录；
     * 安装前把子目录提升为源目录，否则 WordPress 会把仓库根当成插件装上。
     */
    public static function fix_source($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::$plugin_file) return $source;
        if (basename(trailingslashit($source)) === self::PLUGIN_DIR) return $source;

        $subdir = trailingslashit($source) . self::PLUGIN_DIR;
        if (!is_dir($subdir)) return $source;

        $new_source = trailingslashit($remote_source) . self::PLUGIN_DIR . '/';

        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($subdir, $new_source, true)) {
            return $new_source;
        }
        if (@rename($subdir, $new_source)) return $new_source;

        return $source;
    }

    public static function clear_cache() {
        delete_transient(self::CACHE_KEY);
    }
}
