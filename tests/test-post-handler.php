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

		\WP_Mock::expectActionAdded( 'add_meta_boxes', array( $post_handler, 'add_meta_box' ) );
		\WP_Mock::expectActionAdded( 'transition_post_status', array( $post_handler, 'update_meta' ), 11, 3 );
		\WP_Mock::expectActionAdded( 'transition_post_status', array( $post_handler, 'toot' ), 999, 3 );

		$post_handler->register();
	}
}
