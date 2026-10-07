<?php
/**
 * Plugin Name: Zoer Connect
 * Description: Authenticated WordPress transfers and verified recovery for Zoer.
 * Version: 0.5.2
 * Update URI: https://github.com/ahzs645/zoer-connect
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: zoer-connect
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/StageStore.php';
require_once __DIR__ . '/includes/ConnectionKey.php';
require_once __DIR__ . '/includes/ConnectionAdmin.php';
require_once __DIR__ . '/includes/Plugin.php';
require_once __DIR__ . '/includes/RewriteRefresh.php';
require_once __DIR__ . '/includes/GitHubUpdater.php';
\ZoerConnect\Plugin::boot();
\ZoerConnect\GitHubUpdater::boot();

foreach (["Selection", "ExportProfile", "FileExporter", "ExportAdmin", "DatabaseExporter", "RemoteExport", "PagedExport", "WriteFence", "ImportAdmin", "Diagnostics", "TransferAdmin"] as $class) require_once __DIR__ . "/includes/" . $class . ".php";
\ZoerConnect\ExportAdmin::boot();
\ZoerConnect\ImportAdmin::boot();
\ZoerConnect\TransferAdmin::boot();
