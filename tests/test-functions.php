<?php

class Test_Functions extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
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
				'id'  => 2,
				'alt' => '',
			),
		);

		$this->assertEquals( $expected, \Share_On_Mastodon\convert_media_array( $input ) );
	}
}
