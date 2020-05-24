<?php

class Test_Share_On_Mastodon extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_share_on_mastodon_register() {
		$plugin = \Share_On_Mastodon\Share_On_Mastodon::get_instance();

		\WP_Mock::userFunction( 'register_activation_hook', array(
			'times' => 1,
			'args'  => array(
				dirname( dirname( __FILE__ ) ) . '/share-on-mastodon.php',
				array( $plugin, 'activate' ),
			),
		) );

		\WP_Mock::userFunction( 'register_deactivation_hook', array(
			'times' => 1,
			'args'  => array(
				dirname( dirname( __FILE__ ) ) . '/share-on-mastodon.php',
				array( $plugin, 'deactivate' ),
			),
		) );

		\WP_Mock::expectActionAdded( 'plugins_loaded', array( $plugin, 'load_textdomain' ) );
		\WP_Mock::expectActionAdded( 'share_on_mastodon_verify_token', array( $plugin->get_options_handler(), 'cron_verify_token' ) );

		$plugin->register();

		$this->assertHooksAdded();
	}
}
