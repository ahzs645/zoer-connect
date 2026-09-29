<?php
defined('ABSPATH') || exit;
// Visible marker so HTTP checks can tell which theme rendered the page.
add_action('wp_head', static function () {
    echo "<meta name=\"zc-theme\" content=\"zc-child\">\n";
});
