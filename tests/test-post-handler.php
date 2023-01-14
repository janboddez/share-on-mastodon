<?php

class Test_Post_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_post_handler_register() {
		$post_handler = new \Share_On_Mastodon\Post_Handler();

		\WP_Mock::expectActionAdded( 'transition_post_status', array( $post_handler, 'update_meta' ), 11, 3 );
		\WP_Mock::expectActionAdded( 'transition_post_status', array( $post_handler, 'toot' ), 999, 3 );
		\WP_Mock::expectActionAdded( 'share_on_mastodon_post', array( $post_handler, 'post_to_mastodon' ) );

		\WP_Mock::expectActionAdded( 'add_meta_boxes', array( $post_handler, 'add_meta_box' ) );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( $post_handler, 'enqueue_scripts' ) );
		\WP_Mock::expectActionAdded( 'wp_ajax_share_on_mastodon_unlink_url', array( $post_handler, 'unlink_url' ) );

		\WP_Mock::expectActionAdded( 'admin_notices', array( $post_handler, 'admin_notice' ) );
		\WP_Mock::expectFilterAdded( 'removable_query_args', array( $post_handler, 'removable_query_args' ) );

		$post_handler->register();

		$this->assertHooksAdded();
	}
}
