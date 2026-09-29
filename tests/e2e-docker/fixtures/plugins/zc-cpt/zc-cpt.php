<?php
/**
 * Plugin Name: ZC Post Types (e2e fixture)
 * Description: Registers a public zc_book post type and a private zc_internal post type.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

add_action('init', static function () {
    register_post_type('zc_book', ['label' => 'Books', 'public' => true, 'show_in_rest' => true, 'has_archive' => true, 'supports' => ['title', 'editor', 'author', 'custom-fields', 'revisions']]);
    register_post_type('zc_internal', ['label' => 'Internal notes', 'public' => false, 'show_ui' => true, 'supports' => ['title', 'editor', 'custom-fields']]);
});
