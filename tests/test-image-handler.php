<?php

class Test_Image_Handler extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_get_referenced_images() {
		\WP_Mock::userFunction( 'get_option', array(
			'times'  => 1,
			'args'   => array( 'share_on_mastodon_settings', \Share_On_Mastodon\Options_Handler::DEFAULT_OPTIONS ),
			'return' => array( 'referenced_images' => true ),
		) );

		\WP_Mock::userFunction( 'attachment_url_to_postid', array(
			'times'  => 1,
			'args'   => 'https://example.org/images/an-image.png',
			'return' => 12,
		) );

		\WP_Mock::userFunction( 'has_post_thumbnail', array(
			'times'  => 1,
			'args'   => 1,
			'return' => false,
		) );

		\WP_Mock::userFunction( 'get_attached_media', array(
			'times'  => 1,
			'args'   => array( 'image', 1 ),
			'return' => array(),
		) );

		\WP_Mock::userFunction( 'get_bloginfo', array(
			'times'  => 1,
			'args'   => 'charset',
			'return' => 'UTF-8',
		) );

		$post                = new stdClass();
		$post->ID            = 1;
		$post->post_content  = 'Some text. <a href="https://example.org/images/an-image.png"><img src="https://example.org/images/an-image.png" alt="The image\'s alt text." class="aligncenter" width="1920" height="1080"></a>';

		$expected = array(
			array(
				'id'  => 12,
				'alt' => 'The image\'s alt text.',
			),
		);

		$this->assertEquals( $expected, \Share_On_Mastodon\Image_Handler::get_images( $post ) );
	}
}
