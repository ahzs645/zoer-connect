<?php
// Export source identity: originalUrls carries the source siteurl when WordPress
// runs in a subdirectory of its home, so the importer replaces URLs under both.
require __DIR__.'/../includes/Plugin.php';
use ZoerConnect\Plugin;
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
check(Plugin::exportSource('https://example.org/','https://example.org/wp/','wp_','6.6','/var/www/html/')===['url'=>'https://example.org','originalUrls'=>['https://example.org/wp'],'prefix'=>'wp_','wordpressVersion'=>'6.6','abspath'=>'/var/www/html'],'subdirectory siteurl is reported as an original URL');
check(Plugin::exportSource('https://example.org','https://example.org/','wp_','6.6','/srv')['originalUrls']===[],'siteurl equal to home adds no original URL');
check(Plugin::exportSource('https://example.org','http://example.org','wp_','6.6','/srv')['originalUrls']===['http://example.org'],'siteurl on another scheme is an original URL');
foreach(['empty'=>'','relative'=>'/wp','other scheme'=>'ftp://example.org/wp','too long'=>'https://example.org/'.str_repeat('a',2048)] as $label=>$siteurl)
 check(Plugin::exportSource('https://example.org',$siteurl,'wp_','6.6','/srv')['originalUrls']===[],"unusable siteurl ($label) is left out");
