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

		\WP_Mock::userFunction( 'attachment_url_to_postid', array(
			'times'  => 1,
			'args'   => 'https://example.org/images/another-image.png',
			'return' => 17,
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
		$post->post_content  = '<p>Some text.<br><a href="https://example.org/images/an-image.png"><img src="https://example.org/images/an-image.png" alt="The image\'s alt text." class="aligncenter" width="1920" height="1080"></a></p><p><img src="https://example.org/images/another-image.png" alt="Another image\'s alt text."></p>';

		$expected = array(
			12 => 'The image\'s alt text.',
			17 => 'Another image\'s alt text.',
		);

		$this->assertEquals( $expected, \Share_On_Mastodon\Image_Handler::get_images( $post ) );
	}

	public function test_convert_media_array() {
		$input = array(
			0     => 1,
			'key' => array(
				'id'  => 7,
				'alt' => 'some alt text',
			),
			2     => '3',
			3     => 2,
			4     => '',
			99    => array(
				'id'  => 1,
				'alt' => 'different alt text',
			),
			10    => array(
				'id'  => 7,
				'alt' => 'still different alt text',
			),
		);

		$expected = array(
			array(
				'id'  => 1,
				'alt' => '',
			),
			array(
				'id'  => 7,
				'alt' => 'some alt text',
			),
			array(
				'id'  => 3,
				'alt' => '',
			),
			array(
				'id'  => 2,
				'alt' => '',
			),
		);

		$class           = new \ReflectionClass( '\\Share_On_Mastodon\\Image_Handler' );
		$protectedMethod = $class->getMethod( 'convert_media_array' );
		$protectedMethod->setAccessible( true );

		$image_handler = new \Share_On_Mastodon\Image_Handler();
		$actual        = $protectedMethod->invokeArgs( $image_handler, array( $input ) );

		$this->assertEquals( $expected, $actual );
	}
}
