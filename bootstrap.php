<?php

// First we need to load the composer autoloader so we can use WP Mock
require_once __DIR__ . '/vendor/autoload.php';

// Now call the bootstrap method of WP Mock
WP_Mock::bootstrap();

/**
 * Now we include any plugin files that we need to be able to run the tests. This
 * should be files that define the functions and classes you're going to test.
 */
require_once __DIR__ . '/includes/class-block-editor.php';
require_once __DIR__ . '/includes/class-image-handler.php';
require_once __DIR__ . '/includes/class-micropub-compat.php';
require_once __DIR__ . '/includes/class-notices.php';
require_once __DIR__ . '/includes/class-options-handler.php';
require_once __DIR__ . '/includes/class-post-handler.php';
require_once __DIR__ . '/includes/class-share-on-mastodon.php';
require_once __DIR__ . '/includes/class-syn-links-compat.php';
require_once __DIR__ . '/includes/functions.php';
