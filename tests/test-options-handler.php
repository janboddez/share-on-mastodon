<?php

class Test_Options_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_options_handler_register() {
		$options = array(
			'mastodon_host'          => '',
			'mastodon_client_id'     => '',
			'mastodon_client_secret' => '',
			'mastodon_access_token'  => '',
			'post_types'             => array(),
			'mastodon_username'      => '',
			'delay_sharing'          => 0,
			'micropub_compat'        => false,
		);

		\WP_Mock::userFunction( 'get_option', array(
			'times'  => 1,
			'args'   => array(
				'share_on_mastodon_settings',
				$options,
			),
			'return' => $options,
		) );

		$options_handler = new \Share_On_Mastodon\Options_Handler();

		\WP_Mock::expectActionAdded( 'admin_menu', array( $options_handler, 'create_menu' ) );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', array( $options_handler, 'enqueue_scripts' ) );
		\WP_Mock::expectActionAdded( 'admin_post_share_on_mastodon', array( $options_handler, 'admin_post' ) );

		$options_handler->register();

		$this->assertHooksAdded();
	}

	public function test_options_handler_add_settings() {
		$options_handler = new \Share_On_Mastodon\Options_Handler();

		\WP_Mock::userFunction( 'add_options_page', array(
			'times' => 1,
			'args'  => array(
				'Share on Mastodon',
				'Share on Mastodon',
				'manage_options',
				'share-on-mastodon',
				array( $options_handler, 'settings_page' )
			),
		) );

		\WP_Mock::expectActionAdded( 'admin_init', array( $options_handler, 'add_settings' ) );

		$options_handler->create_menu();

		$this->assertHooksAdded();
	}
}
