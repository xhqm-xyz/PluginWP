<?php
/**
 * MCP 工具定义与实现（14 个工具）
 *
 * 文章：search_posts / get_post / create_post / update_post / delete_post
 * 媒体：upload_media / list_media / delete_media
 * 用户：search_users
 * 评论：list_comments / reply_comment / moderate_comment
 * 分类：list_terms
 * 站点：get_site_info
 *
 * @package Stella_MCP_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stella_MCP_Tools {

	/**
	 * 工具目录：名称 → 中文说明（设置页与默认配置共用）
	 */
	public static function catalog() {
		return array(
			'search_posts'     => '搜索文章',
			'get_post'         => '读取单篇文章全文',
			'create_post'      => '撰写并发布博客',
			'update_post'      => '更新已有文章',
			'delete_post'      => '删除文章（回收站／彻底）',
			'upload_media'     => '上传多媒体资源',
			'list_media'       => '检索媒体库',
			'delete_media'     => '删除媒体文件',
			'search_users'     => '搜索用户',
			'list_comments'    => '检索评论',
			'reply_comment'    => '发表／回复评论',
			'moderate_comment' => '审核评论（通过／待审／垃圾／回收站）',
			'list_terms'       => '列出分类与标签',
			'get_site_info'    => '站点信息与运行状态',
		);
	}

	/**
	 * tools/list：按后台启用状态输出工具定义
	 */
	public static function definitions() {
		$options = Stella_MCP_Server::options();
		$paging  = array(
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 50,
				'default' => 10,
			),
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);

		$all = array(
			'search_posts'     => array(
				'name'        => 'search_posts',
				'title'       => '搜索文章',
				'description' => '搜索站点中的文章／页面，返回标题、摘要、链接、日期、作者、分类与标签',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => '关键词，匹配标题、正文、摘要；留空则按时间倒序列出',
						),
						'post_type' => array(
							'type'    => 'string',
							'default' => 'post',
						),
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
							'description' => '默认 publish；非公开状态需要 edit_posts 权限',
							'default'     => 'publish',
						),
					) + $paging,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'get_post'         => array(
				'name'        => 'get_post',
				'title'       => '读取文章',
				'description' => '按 ID 读取单篇文章的完整正文、摘要、分类、标签、特色图与评论信息',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => '文章 ID',
						),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'create_post'      => array(
				'name'        => 'create_post',
				'title'       => '撰写并发布博客',
				'description' => '新建文章／页面，支持草稿或直接发布、分类、标签、特色图（需要 edit_posts；发布需要 publish_posts）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'title'             => array( 'type' => 'string' ),
						'content'           => array(
							'type'        => 'string',
							'description' => '正文，支持 HTML',
						),
						'status'            => array(
							'type'    => 'string',
							'enum'    => array( 'draft', 'publish', 'pending', 'private' ),
							'default' => 'draft',
						),
						'excerpt'           => array( 'type' => 'string' ),
						'slug'              => array( 'type' => 'string' ),
						'post_type'         => array(
							'type'    => 'string',
							'default' => 'post',
						),
						'categories'        => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => '分类 ID 或名称；名称不存在且有权限时自动创建',
						),
						'tags'              => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => '标签名称，自动创建',
						),
						'featured_media_id' => array(
							'type'        => 'integer',
							'description' => '特色图附件 ID（可由 upload_media 获得）',
						),
					),
					'required'   => array( 'title', 'content' ),
				),
				'annotations' => array(
					'readOnlyHint'   => false,
					'idempotentHint' => false,
				),
			),
			'update_post'      => array(
				'name'        => 'update_post',
				'title'       => '更新文章',
				'description' => '修改已有文章的标题、正文、状态、摘要、分类、标签、特色图；仅更新传入的字段（需要 edit_post 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array(
							'type'        => 'integer',
							'description' => '文章 ID',
						),
						'title'             => array( 'type' => 'string' ),
						'content'           => array( 'type' => 'string' ),
						'status'            => array(
							'type' => 'string',
							'enum' => array( 'draft', 'publish', 'pending', 'private' ),
						),
						'excerpt'           => array( 'type' => 'string' ),
						'slug'              => array( 'type' => 'string' ),
						'categories'        => array(
							'type'  => 'array',
							'items' => array( 'type' => array( 'string', 'integer' ) ),
						),
						'tags'              => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'featured_media_id' => array(
							'type'        => 'integer',
							'description' => '特色图附件 ID；传 0 移除特色图',
						),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array(
					'readOnlyHint'   => false,
					'idempotentHint' => true,
				),
			),
			'delete_post'      => array(
				'name'        => 'delete_post',
				'title'       => '删除文章',
				'description' => '将文章移入回收站，或彻底删除（需要 delete_post 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'true 彻底删除，false（默认）移入回收站',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),
			'upload_media'     => array(
				'name'        => 'upload_media',
				'title'       => '上传媒体',
				'description' => '将 Base64 编码的文件上传至站点媒体库，返回附件 ID 与 URL（需要 upload_files 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'filename'    => array(
							'type'        => 'string',
							'description' => '文件名（含扩展名），如 cover.png',
						),
						'data_base64' => array(
							'type'        => 'string',
							'description' => '文件内容的 Base64 编码',
						),
						'mime_type'   => array(
							'type'        => 'string',
							'description' => '可选；仅当扩展名无法识别时参考，且必须在站点允许列表内',
						),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'alt_text'    => array(
							'type'        => 'string',
							'description' => '图片替代文本（alt）',
						),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'filename', 'data_base64' ),
				),
				'annotations' => array(
					'readOnlyHint'   => false,
					'idempotentHint' => false,
				),
			),
			'list_media'       => array(
				'name'        => 'list_media',
				'title'       => '检索媒体库',
				'description' => '按关键词与类型检索媒体库附件，返回 URL、MIME、尺寸与 alt（需要 upload_files 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array( 'type' => 'string' ),
						'type'   => array(
							'type'        => 'string',
							'description' => 'MIME 过滤，如 image / audio / video / application，或精确值 image/png',
						),
					) + $paging,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'delete_media'     => array(
				'name'        => 'delete_media',
				'title'       => '删除媒体',
				'description' => '删除媒体库附件及其文件（需要 delete_post 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer' ),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'true 跳过回收站彻底删除，默认 false',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),
			'search_users'     => array(
				'name'        => 'search_users',
				'title'       => '搜索用户',
				'description' => '搜索站点用户，返回用户名、显示名、邮箱、角色、注册时间与文章数（需要 list_users 权限，通常为管理员）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => '关键词，匹配登录名、邮箱、昵称、显示名',
						),
						'role'   => array(
							'type'        => 'string',
							'description' => '按角色过滤，如 administrator / editor / author',
						),
					) + $paging,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'list_comments'    => array(
				'name'        => 'list_comments',
				'title'       => '检索评论',
				'description' => '按文章与状态列出评论；查看非公开状态需要 moderate_comments 权限',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => '限定某篇文章的评论',
						),
						'status'  => array(
							'type'    => 'string',
							'enum'    => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
							'default' => 'approve',
						),
					) + $paging,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'reply_comment'    => array(
				'name'        => 'reply_comment',
				'title'       => '发表／回复评论',
				'description' => '以当前鉴权账号身份在文章下发表评论，或回复指定评论；是否立即公开由站点审核设置决定',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array(
							'type'        => 'integer',
							'description' => '文章 ID（与 comment_id 二选一）',
						),
						'comment_id' => array(
							'type'        => 'integer',
							'description' => '要回复的评论 ID',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => '评论内容',
						),
					),
					'required'   => array( 'content' ),
				),
				'annotations' => array(
					'readOnlyHint'   => false,
					'idempotentHint' => false,
				),
			),
			'moderate_comment' => array(
				'name'        => 'moderate_comment',
				'title'       => '审核评论',
				'description' => '变更评论状态：通过／待审／垃圾／回收站（需要 moderate_comments 权限）',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'action' => array(
							'type' => 'string',
							'enum' => array( 'approve', 'hold', 'spam', 'trash' ),
						),
					),
					'required'   => array( 'id', 'action' ),
				),
				'annotations' => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
				),
			),
			'list_terms'       => array(
				'name'        => 'list_terms',
				'title'       => '列出分类与标签',
				'description' => '列出分类目录或标签，返回名称、slug、文章数与层级',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'   => array(
							'type'    => 'string',
							'enum'    => array( 'category', 'post_tag' ),
							'default' => 'category',
						),
						'search'     => array( 'type' => 'string' ),
						'hide_empty' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					) + $paging,
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
			'get_site_info'    => array(
				'name'        => 'get_site_info',
				'title'       => '站点信息',
				'description' => '返回站点名称、版本、内容统计与已启用工具清单，用于连接后的健康检查',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'detail' => array(
							'type'        => 'boolean',
							'description' => '为 true 且账号为管理员时，额外返回当前主题与已启用插件数',
							'default'     => false,
						),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
			),
		);

		$enabled = array();
		foreach ( $all as $name => $definition ) {
			if ( ! empty( $options['tools'][ $name ] ) ) {
				$enabled[] = $definition;
			}
		}
		return $enabled;
	}

	/**
	 * tools/call 调度
	 *
	 * @param string $name 工具名
	 * @param array  $args 参数
	 * @return array MCP content 结构
	 */
	public static function call( $name, array $args ) {
		$options = Stella_MCP_Server::options();
		$map     = array_keys( self::catalog() );

		if ( ! in_array( $name, $map, true ) ) {
			return self::error_content( '未知工具：' . $name . '。可用：' . implode( '、', $map ) );
		}
		if ( empty( $options['tools'][ $name ] ) ) {
			return self::error_content( '工具「' . $name . '」已被管理员停用' );
		}

		try {
			$data = call_user_func( array( __CLASS__, 'tool_' . $name ), $args );
		} catch ( Throwable $e ) {
			return self::error_content( '执行异常：' . $e->getMessage() );
		}

		if ( is_wp_error( $data ) ) {
			return self::error_content( $data->get_error_message() );
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
				),
			),
			'isError' => false,
		);
	}

	private static function error_content( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => '错误。' . $message,
				),
			),
			'isError' => true,
		);
	}

	/* --------------------------------------------------------------------
	 * 文章
	 * ------------------------------------------------------------------ */

	/**
	 * 搜索文章
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_search_posts( array $args ) {
		$status    = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish';
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';

		if ( 'publish' !== $status && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', '当前账号无权检索非公开内容（需要 edit_posts 权限）' );
		}

		$query = new WP_Query(
			array(
				's'              => isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => min( 50, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 10 ) ),
				'paged'          => max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::format_post( $post, false );
		}

		return array(
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'items' => $items,
		);
	}

	/**
	 * 读取单篇文章
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_get_post( array $args ) {
		if ( empty( $args['id'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：id' );
		}

		$post = get_post( (int) $args['id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'not_found', '文章不存在：ID ' . (int) $args['id'] );
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', '当前账号无权读取该文章' );
		}

		$data              = self::format_post( $post, true );
		$data['comment_count'] = (int) $post->comment_count;
		$data['comment_status'] = $post->comment_status;
		return $data;
	}

	/**
	 * 撰写并发布文章
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_create_post( array $args ) {
		if ( empty( $args['title'] ) || ! isset( $args['content'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：title 与 content' );
		}

		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		$pto       = get_post_type_object( $post_type );
		if ( ! $pto ) {
			return new WP_Error( 'invalid', '未知的文章类型：' . $post_type );
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			return new WP_Error( 'invalid', 'status 仅支持 draft / publish / pending / private' );
		}
		if ( ! current_user_can( $pto->cap->edit_posts ) ) {
			return new WP_Error( 'forbidden', '当前账号无权撰写文章' );
		}
		if ( 'publish' === $status && ! current_user_can( $pto->cap->publish_posts ) ) {
			return new WP_Error( 'forbidden', '当前账号无权直接发布，请改用 status=draft' );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_title'   => $args['title'],
					'post_content' => $args['content'],
					'post_excerpt' => isset( $args['excerpt'] ) ? $args['excerpt'] : '',
					'post_status'  => $status,
					'post_type'    => $post_type,
					'post_name'    => isset( $args['slug'] ) ? sanitize_title( $args['slug'] ) : '',
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::apply_terms( $post_id, $args );
		self::apply_thumbnail( $post_id, $args );

		return array(
			'id'        => $post_id,
			'status'    => get_post_status( $post_id ),
			'link'      => get_permalink( $post_id ),
			'edit_link' => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * 更新文章
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_update_post( array $args ) {
		if ( empty( $args['id'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：id' );
		}

		$post = get_post( (int) $args['id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'not_found', '文章不存在：ID ' . (int) $args['id'] );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', '当前账号无权编辑该文章' );
		}

		$update = array( 'ID' => $post->ID );
		if ( isset( $args['title'] ) ) {
			$update['post_title'] = $args['title'];
		}
		if ( isset( $args['content'] ) ) {
			$update['post_content'] = $args['content'];
		}
		if ( isset( $args['excerpt'] ) ) {
			$update['post_excerpt'] = $args['excerpt'];
		}
		if ( isset( $args['slug'] ) ) {
			$update['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['status'] ) ) {
			$status = sanitize_key( $args['status'] );
			if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
				return new WP_Error( 'invalid', 'status 仅支持 draft / publish / pending / private' );
			}
			if ( 'publish' === $status && ! current_user_can( 'publish_post', $post->ID ) ) {
				return new WP_Error( 'forbidden', '当前账号无权发布该文章' );
			}
			$update['post_status'] = $status;
		}

		if ( 1 === count( $update ) && empty( $args['categories'] ) && empty( $args['tags'] ) && ! isset( $args['featured_media_id'] ) ) {
			return new WP_Error( 'invalid', '未提供任何待更新字段' );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		self::apply_terms( $post->ID, $args );
		self::apply_thumbnail( $post->ID, $args );

		return array(
			'id'        => $post->ID,
			'status'    => get_post_status( $post->ID ),
			'link'      => get_permalink( $post->ID ),
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/**
	 * 删除文章
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_delete_post( array $args ) {
		if ( empty( $args['id'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：id' );
		}

		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			return new WP_Error( 'not_found', '文章不存在：ID ' . (int) $args['id'] );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', '当前账号无权删除该文章' );
		}

		$force  = ! empty( $args['force'] );
		$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );

		if ( ! $result ) {
			return new WP_Error( 'failed', '删除失败，文章可能已在回收站中' );
		}

		return array(
			'id'     => $post->ID,
			'action' => $force ? 'deleted' : 'trashed',
		);
	}

	/* --------------------------------------------------------------------
	 * 媒体
	 * ------------------------------------------------------------------ */

	/**
	 * 上传媒体（Base64）
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_upload_media( array $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', '当前账号无权上传媒体（需要 upload_files 权限）' );
		}
		if ( empty( $args['filename'] ) || empty( $args['data_base64'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：filename 与 data_base64' );
		}

		$filename = sanitize_file_name( $args['filename'] );
		$binary   = base64_decode( $args['data_base64'], true );
		if ( false === $binary ) {
			return new WP_Error( 'invalid', 'data_base64 不是合法的 Base64 数据' );
		}
		if ( strlen( $binary ) > wp_max_upload_size() ) {
			return new WP_Error( 'too_large', '文件超过站点最大上传限制（' . size_format( wp_max_upload_size() ) . '）' );
		}

		$filetype = wp_check_filetype( $filename );
		if ( empty( $filetype['type'] ) && ! empty( $args['mime_type'] ) ) {
			if ( in_array( $args['mime_type'], get_allowed_mime_types(), true ) ) {
				$filetype['type'] = $args['mime_type'];
			}
		}
		if ( empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_type', '站点不允许上传该文件类型：' . $filename );
		}

		$upload = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			wp_slash(
				array(
					'post_mime_type' => $filetype['type'],
					'post_title'     => ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : preg_replace( '/\.[^.]+$/', '', $filename ),
					'post_excerpt'   => isset( $args['caption'] ) ? sanitize_text_field( $args['caption'] ) : '',
					'post_content'   => isset( $args['description'] ) ? sanitize_textarea_field( $args['description'] ) : '',
					'post_status'    => 'inherit',
					'post_author'    => get_current_user_id(),
				)
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}

		return array(
			'id'   => $attachment_id,
			'url'  => wp_get_attachment_url( $attachment_id ),
			'mime' => $filetype['type'],
			'file' => basename( $upload['file'] ),
		);
	}

	/**
	 * 检索媒体库
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_list_media( array $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'forbidden', '当前账号无权访问媒体库（需要 upload_files 权限）' );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
			'posts_per_page' => min( 50, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 10 ) ),
			'paged'          => max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ),
		);
		if ( ! empty( $args['type'] ) ) {
			$query_args['post_mime_type'] = sanitize_mime_type( $args['type'] );
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$item   = array(
				'id'    => $attachment->ID,
				'title' => get_the_title( $attachment ),
				'url'   => wp_get_attachment_url( $attachment->ID ),
				'mime'  => $attachment->post_mime_type,
				'date'  => $attachment->post_date,
				'alt'   => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			);
			$meta   = wp_get_attachment_metadata( $attachment->ID );
			if ( is_array( $meta ) && isset( $meta['width'] ) ) {
				$item['width']  = (int) $meta['width'];
				$item['height'] = (int) $meta['height'];
			}
			$items[] = $item;
		}

		return array(
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'items' => $items,
		);
	}

	/**
	 * 删除媒体
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_delete_media( array $args ) {
		if ( empty( $args['id'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：id' );
		}

		$attachment = get_post( (int) $args['id'] );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', '附件不存在：ID ' . (int) $args['id'] );
		}
		if ( ! current_user_can( 'delete_post', $attachment->ID ) ) {
			return new WP_Error( 'forbidden', '当前账号无权删除该附件' );
		}

		$result = wp_delete_attachment( $attachment->ID, ! empty( $args['force'] ) );
		if ( ! $result ) {
			return new WP_Error( 'failed', '删除失败' );
		}

		return array(
			'id'     => $attachment->ID,
			'action' => ! empty( $args['force'] ) ? 'deleted' : 'trashed',
		);
	}

	/* --------------------------------------------------------------------
	 * 用户
	 * ------------------------------------------------------------------ */

	/**
	 * 搜索用户
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_search_users( array $args ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return new WP_Error( 'forbidden', '当前账号无权检索用户（需要 list_users 权限，通常为管理员）' );
		}

		$query_args = array(
			'number'  => min( 50, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 10 ) ),
			'paged'   => max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 ),
			'orderby' => 'registered',
			'order'   => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( $args['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}
		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = sanitize_key( $args['role'] );
		}

		$query = new WP_User_Query( $query_args );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'roles'        => $user->roles,
				'registered'   => $user->user_registered,
				'url'          => $user->user_url,
				'posts'        => (int) count_user_posts( $user->ID ),
			);
		}

		return array(
			'total' => (int) $query->get_total(),
			'items' => $items,
		);
	}

	/* --------------------------------------------------------------------
	 * 评论
	 * ------------------------------------------------------------------ */

	/**
	 * 检索评论
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_list_comments( array $args ) {
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'approve';

		if ( ! in_array( $status, array( 'approve', 'hold', 'spam', 'trash', 'all' ), true ) ) {
			return new WP_Error( 'invalid', 'status 仅支持 approve / hold / spam / trash / all' );
		}
		if ( 'approve' !== $status && ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'forbidden', '查看非公开评论需要 moderate_comments 权限' );
		}

		$per_page = min( 50, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 10 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );

		$query_args = array(
			'status' => $status,
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'order'  => 'DESC',
		);
		if ( ! empty( $args['post_id'] ) ) {
			$query_args['post_id'] = (int) $args['post_id'];
		}

		$can_moderate = current_user_can( 'moderate_comments' );
		$items        = array();
		foreach ( get_comments( $query_args ) as $comment ) {
			$item = array(
				'id'      => (int) $comment->comment_ID,
				'post_id' => (int) $comment->comment_post_ID,
				'post'    => get_the_title( $comment->comment_post_ID ),
				'parent'  => (int) $comment->comment_parent,
				'author'  => $comment->comment_author,
				'date'    => $comment->comment_date,
				'status'  => wp_get_comment_status( $comment->comment_ID ),
				'content' => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 80, '…' ),
			);
			if ( $can_moderate ) {
				$item['author_email'] = $comment->comment_author_email;
				$item['author_url']   = $comment->comment_author_url;
			}
			$items[] = $item;
		}

		$count_args           = $query_args;
		$count_args['count']  = true;
		unset( $count_args['number'], $count_args['offset'] );

		return array(
			'total' => (int) get_comments( $count_args ),
			'items' => $items,
		);
	}

	/**
	 * 发表／回复评论
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_reply_comment( array $args ) {
		if ( empty( $args['content'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：content' );
		}

		$parent_id = 0;
		$post_id   = 0;

		if ( ! empty( $args['comment_id'] ) ) {
			$parent = get_comment( (int) $args['comment_id'] );
			if ( ! $parent ) {
				return new WP_Error( 'not_found', '评论不存在：ID ' . (int) $args['comment_id'] );
			}
			$parent_id = (int) $parent->comment_ID;
			$post_id   = (int) $parent->comment_post_ID;
		} elseif ( ! empty( $args['post_id'] ) ) {
			$post_id = (int) $args['post_id'];
		} else {
			return new WP_Error( 'invalid', '需要提供 post_id 或 comment_id 之一' );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', '文章不存在或未公开：ID ' . $post_id );
		}
		if ( ! comments_open( $post_id ) ) {
			return new WP_Error( 'closed', '该文章评论已关闭' );
		}

		$comment_id = wp_new_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_parent'  => $parent_id,
				'comment_content' => $args['content'],
				'user_id'         => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $comment_id ) ) {
			return $comment_id;
		}
		if ( ! $comment_id ) {
			return new WP_Error( 'failed', '评论提交失败，可能被反垃圾插件拦截或内容重复' );
		}

		$status = wp_get_comment_status( $comment_id );

		return array(
			'id'      => (int) $comment_id,
			'post_id' => $post_id,
			'parent'  => $parent_id,
			'status'  => $status,
			'note'    => ( 'approved' === $status ) ? '评论已公开' : '评论已进入审核队列，由站点审核设置决定',
		);
	}

	/**
	 * 审核评论
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_moderate_comment( array $args ) {
		if ( empty( $args['id'] ) || empty( $args['action'] ) ) {
			return new WP_Error( 'invalid', '缺少必填参数：id 与 action' );
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'forbidden', '当前账号无权审核评论' );
		}

		$action = sanitize_key( $args['action'] );
		if ( ! in_array( $action, array( 'approve', 'hold', 'spam', 'trash' ), true ) ) {
			return new WP_Error( 'invalid', 'action 仅支持 approve / hold / spam / trash' );
		}

		$comment = get_comment( (int) $args['id'] );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', '评论不存在：ID ' . (int) $args['id'] );
		}

		if ( ! wp_set_comment_status( $comment->comment_ID, $action ) ) {
			return new WP_Error( 'failed', '状态变更失败' );
		}

		return array(
			'id'     => (int) $comment->comment_ID,
			'status' => wp_get_comment_status( $comment->comment_ID ),
		);
	}

	/* --------------------------------------------------------------------
	 * 分类与标签
	 * ------------------------------------------------------------------ */

	/**
	 * 列出分类／标签
	 *
	 * @param array $args 参数
	 * @return array|WP_Error
	 */
	private static function tool_list_terms( array $args ) {
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			return new WP_Error( 'invalid', 'taxonomy 仅支持 category / post_tag' );
		}

		$per_page = min( 50, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 10 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );

		$base = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $args['hide_empty'] ),
		);
		if ( ! empty( $args['search'] ) ) {
			$base['search'] = sanitize_text_field( $args['search'] );
		}

		$total = wp_count_terms( $base );
		if ( is_wp_error( $total ) ) {
			return $total;
		}

		$terms = get_terms(
			$base + array(
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'count'  => (int) $term->count,
				'parent' => (int) $term->parent,
			);
		}

		return array(
			'total' => (int) $total,
			'items' => $items,
		);
	}

	/* --------------------------------------------------------------------
	 * 站点
	 * ------------------------------------------------------------------ */

	/**
	 * 站点信息与运行状态
	 *
	 * @param array $args 参数
	 * @return array
	 */
	private static function tool_get_site_info( array $args ) {
		$post_counts  = wp_count_posts( 'post' );
		$page_counts  = wp_count_posts( 'page' );
		$media_query  = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
			)
		);
		$options      = Stella_MCP_Server::options();
		$enabled_tool = array();
		foreach ( self::catalog() as $name => $label ) {
			if ( ! empty( $options['tools'][ $name ] ) ) {
				$enabled_tool[] = $name;
			}
		}

		$info = array(
			'name'           => get_bloginfo( 'name' ),
			'description'    => get_bloginfo( 'description' ),
			'url'            => home_url(),
			'endpoint'       => rest_url( 'mcp/v1/server' ),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => phpversion(),
			'plugin_version' => STELLA_MCP_VERSION,
			'current_user'   => wp_get_current_user()->user_login,
			'counts'         => array(
				'posts_publish' => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
				'posts_draft'   => isset( $post_counts->draft ) ? (int) $post_counts->draft : 0,
				'pages'         => isset( $page_counts->publish ) ? (int) $page_counts->publish : 0,
				'media'         => (int) $media_query->found_posts,
			),
			'enabled_tools'  => $enabled_tool,
		);

		if ( current_user_can( 'list_users' ) ) {
			$user_count           = count_users();
			$info['counts']['users'] = isset( $user_count['total_users'] ) ? (int) $user_count['total_users'] : 0;
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			$comment_counts                    = wp_count_comments();
			$info['counts']['comments_pending'] = (int) $comment_counts->moderated;
			$info['counts']['comments_approved'] = (int) $comment_counts->approved;
		}
		if ( ! empty( $args['detail'] ) && current_user_can( 'manage_options' ) ) {
			$theme                            = wp_get_theme();
			$info['theme']                    = $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
			$info['counts']['plugins_active'] = count( (array) get_option( 'active_plugins', array() ) );
		}

		return $info;
	}

	/* --------------------------------------------------------------------
	 * 共用辅助
	 * ------------------------------------------------------------------ */

	/**
	 * 格式化文章输出
	 *
	 * @param WP_Post $post 文章对象
	 * @param bool    $full 是否包含全文
	 * @return array
	 */
	private static function format_post( $post, $full ) {
		$data = array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'status'     => $post->post_status,
			'date'       => $post->post_date,
			'modified'   => $post->post_modified,
			'link'       => get_permalink( $post ),
			'excerpt'    => wp_trim_words( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 55, '…' ),
			'author'     => get_the_author_meta( 'display_name', $post->post_author ),
			'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
			'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		);

		if ( $full ) {
			$data['content']        = $post->post_content;
			$data['slug']           = $post->post_name;
			$data['featured_image'] = get_the_post_thumbnail_url( $post->ID, 'full' ) ?: null;
		}

		return $data;
	}

	/**
	 * 写入分类与标签（create / update 共用）
	 *
	 * @param int   $post_id 文章 ID
	 * @param array $args    参数
	 */
	private static function apply_terms( $post_id, array $args ) {
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', $args['tags'] ), 'post_tag', false );
		}

		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$cat_ids = array();
			foreach ( $args['categories'] as $cat ) {
				if ( is_numeric( $cat ) ) {
					$cat_ids[] = (int) $cat;
					continue;
				}
				$name = sanitize_text_field( $cat );
				$id   = get_cat_ID( $name );
				if ( ! $id && current_user_can( 'manage_categories' ) ) {
					$id = wp_create_category( $name );
				}
				if ( $id ) {
					$cat_ids[] = (int) $id;
				}
			}
			if ( $cat_ids ) {
				wp_set_post_categories( $post_id, $cat_ids, false );
			}
		}
	}

	/**
	 * 写入特色图（create / update 共用）；featured_media_id 为 0 时移除
	 *
	 * @param int   $post_id 文章 ID
	 * @param array $args    参数
	 */
	private static function apply_thumbnail( $post_id, array $args ) {
		if ( ! isset( $args['featured_media_id'] ) ) {
			return;
		}
		$media_id = (int) $args['featured_media_id'];
		if ( $media_id > 0 ) {
			set_post_thumbnail( $post_id, $media_id );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}
}
