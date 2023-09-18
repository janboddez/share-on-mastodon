<?php

class Test_Post_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_post_handler_register() {
		$options      = array( 'post_types' => array( 'post', 'page', 'indieblocks_note' ) );
		$post_handler = new \Share_On_Mastodon\Post_Handler( $options );

		\WP_Mock::expectActionAdded( 'add_meta_boxes', array( $post_handler, 'add_meta_box' ) );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( $post_handler, 'enqueue_scripts' ) );
		\WP_Mock::expectActionAdded( 'wp_ajax_share_on_mastodon_unlink_url', array( $post_handler, 'unlink_url' ) );

		foreach ( $options['post_types'] as $post_type ) {
			\WP_Mock::expectActionAdded( "save_post_{$post_type}", array( $post_handler, 'update_meta' ), 10 );
			\WP_Mock::expectActionAdded( "save_post_{$post_type}", array( $post_handler, 'toot' ), 20 );
		}

		\WP_Mock::expectActionAdded( 'share_on_mastodon_post', array( $post_handler, 'post_to_mastodon' ) );

		$post_handler->register();

		$this->assertHooksAdded();
	}

	public function test_post_handler_is_older_than() {
		$class           = new \ReflectionClass( '\\Share_On_Mastodon\\Post_Handler' );
		$protectedMethod = $class->getMethod( 'is_older_than' );
		$protectedMethod->setAccessible( true );

		$post            = new stdClass();
		$post->ID        = 1;
		$post->post_date = '2022-01-01 08:30:05';

		\WP_Mock::userFunction( 'get_post_time', array(
			'times'  => 1,
			'args'   => array( 'U', true, $post ),
			'return' => strtotime( $post->post_date ),
		) );

		$post_handler = new \Share_On_Mastodon\Post_Handler();

		$this->assertEquals( $protectedMethod->invokeArgs( $post_handler, array( 3600, $post ) ), true );

		$post            = new stdClass();
		$post->post_date = '2023-01-01 08:30:05';

		\WP_Mock::userFunction( 'get_post_time', array(
			'times'  => 1,
			'args'   => array( 'U', true, $post ),
			'return' => strtotime( $post->post_date ),
		) );

		$class           = new \ReflectionClass( '\\Share_On_Mastodon\\Post_Handler' );
		$protectedMethod = $class->getMethod( 'is_older_than' );
		$protectedMethod->setAccessible( true );

		$post_handler = new \Share_On_Mastodon\Post_Handler();

		$this->assertEquals( $protectedMethod->invokeArgs( $post_handler, array( 315360000, $post ) ), false );

		$post = new stdClass();

		\WP_Mock::userFunction( 'get_post_time', array(
			'times'  => 1,
			'args'   => array( 'U', true, $post ),
			'return' => false,
		) );

		$this->assertEquals( $protectedMethod->invokeArgs( $post_handler, array( 3600, $post ) ), false );
	}
}
