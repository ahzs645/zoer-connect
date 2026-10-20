<?php
namespace ZoerConnect;

/**
 * A request-fence MU file that Zoer Connect wrote for another installation and that arrived here with a copy
 * of the site (a hosting backup restore, a migration or a cloned folder). Its absolute paths belong to the other
 * site, so WriteFence does not recognise it and setup used to stop with "Existing MU fence differs".
 *
 * Only an exact Zoer Connect bootstrap (current or 0.5.x form, either cache mode) for different paths counts;
 * anything else is still someone else's file. Kept outside WriteFence.php, whose digest is part of each site's
 * import readiness receipt, so recognising copies does not reset readiness on upgraded sites.
 */
final class CopiedFence {
    public const MU='wp-content/mu-plugins/000-zoer-connect-fence.php';
    private const LITERAL="'(?:[^'\\\\]|\\\\.)*'";

    /** The bootstrap WriteFence writes for an installation at $root with private storage $private. */
    private static function current(string $root,string $private,bool $sharedCaches): string {
        $plugin=$root.'/wp-content/plugins/zoer-connect/includes/';
        return "<?php\n".($sharedCaches?"// Shared-hosting cache coexistence; early cache responses are outside this fence.\n":"")."// Zoer Connect request fence: keep before ordinary plugins and MU plugins.\n// Inert in a copy of this site at another path, and after Zoer Connect is removed while no transfer is paused.\ndefined('ABSPATH') || exit;\nif (realpath(ABSPATH) !== ".var_export($root,true).") return;\nif (!is_file(".var_export($plugin.'WriteFence.php',true).")) {\n if (!file_exists(".var_export($private.'/write-fence.json',true).")) return;\n http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Retry-After: 300');\n echo '{\"code\":\"zoer_transfer_paused\",\"message\":\"A Zoer Connect transfer is paused but the plugin files are missing. Reinstall Zoer Connect to finish or roll back the transfer.\"}'; exit;\n}\nrequire_once ".var_export($plugin.'WriteFence.php',true).";\n\\ZoerConnect\\WriteFence::boot(".var_export($private,true).", ".var_export($root,true).", static function (string \$route) {\n require_once ".var_export($plugin.'Plugin.php',true).";\n return \\ZoerConnect\\Plugin::earlyImportRecovery(\$route);\n});\n";
    }
    private static function legacy(string $root,string $private,bool $sharedCaches): string {
        $plugin=$root.'/wp-content/plugins/zoer-connect/includes/';
        return "<?php\n".($sharedCaches?"// Shared-hosting cache coexistence; early cache responses are outside this fence.\n":"")."// Zoer Connect request fence: keep before ordinary plugins and MU plugins.\ndefined('ABSPATH') || exit;\nrequire_once ".var_export($plugin.'WriteFence.php',true).";\n\\ZoerConnect\\WriteFence::boot(".var_export($private,true).", ".var_export($root,true).", static function (string \$route) {\n require_once ".var_export($plugin.'Plugin.php',true).";\n return \\ZoerConnect\\Plugin::earlyImportRecovery(\$route);\n});\n";
    }
    private static function unquote(string $literal): string {
        return preg_replace("/\\\\([\\\\'])/",'$1',substr($literal,1,-1));
    }
    /** The installation paths of an exact Zoer Connect bootstrap, or null for any other content. */
    public static function paths(string $body): ?array {
        if (strlen($body)>8192||!preg_match('/\\\\ZoerConnect\\\\WriteFence::boot\(('.self::LITERAL.'), ('.self::LITERAL.'), static function/',$body,$m)) return null;
        $private=self::unquote($m[1]);$root=self::unquote($m[2]);
        foreach ([false,true] as $sharedCaches) foreach ([self::current($root,$private,$sharedCaches),self::legacy($root,$private,$sharedCaches)] as $candidate)
            if (hash_equals($candidate,$body)) return ['root'=>$root,'private'=>$private];
        return null;
    }
    /**
     * Removes the MU file when it is Zoer Connect's fence for another installation, so setup can install this
     * site's own. Returns whether it was removed. This site's own fence and foreign files are left alone. A fence
     * for this same WordPress folder with an earlier private storage folder is replaced only while that folder
     * holds no paused transfer. The caller holds the import setup lock and has checked this site's own journal.
     */
    public static function removeIfCopied(string $root,string $private): bool {
        $path=rtrim($root,'/').'/'.self::MU;
        if (is_link($path)||!is_file($path)) return false;
        $body=(string)file_get_contents($path);
        $paths=self::paths($body);
        $here=realpath($root);$storage=realpath($private);
        if ($paths===null||$here===false||$storage===false) return false;
        if ($paths['root']===$here&&$paths['private']===$storage) return false;
        if ($paths['root']===$here&&@file_exists($paths['private'].'/write-fence.json'))
            throw new \RuntimeException('A transfer paused with an earlier private storage folder still holds this site. Finish or roll it back before setting up imports.');
        if ((string)file_get_contents($path)!==$body||!unlink($path)) throw new \RuntimeException('Cannot replace the request fence copied from another site.');
        return true;
    }
}
