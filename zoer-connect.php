<?php
/**
 * Plugin Name: Zoer Connect
 * Description: Authenticated connection and verified migration staging for Zoer. Live publication is not yet supported.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: zoer-connect
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/StageStore.php';
require_once __DIR__ . '/includes/Plugin.php';
\ZoerConnect\Plugin::boot();
