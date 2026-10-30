<?php
namespace ZoerConnect;

/**
 * A connector-only credential. The raw value is shown once and never persisted.
 *
 * Scopes (0.10.0): `pull` reads the site, `push` stages and imports uploads and the
 * database, and `code` additionally lets a push write wp-content/plugins and
 * wp-content/themes (PHP that WordPress executes). New keys are created with every
 * scope off unless the administrator turns it on. A key stored before 0.10.0 has no
 * `code` field: if it has Push it keeps code access so an existing pairing keeps
 * working, and the admin screen flags it as a legacy key until the choice is saved.
 */
final class ConnectionKey {
    public const OPTION = 'zoer_connect_connection';
    /** Live paths whose files WordPress executes as code. Uploads never may contain code. */
    private const CODE_PATH = '~^wp-content/(?:plugins|themes)/~';

    /**
     * $previous (a reset) keeps its Push and Pull choices; Code is always the explicit
     * $code choice, so a reset never silently carries legacy code access forward.
     * Without $previous, Push and Code are off unless requested; Pull starts off.
     */
    public static function create(int $owner, ?array $previous = null, bool $push = false, bool $code = false): array {
        if($owner<1)throw new \InvalidArgumentException('Administrator required.');
        $secret='zc_'.bin2hex(random_bytes(32));
        $push=$previous === null ? $push : ($previous['push']??false) === true;
        return [$secret,['hash'=>hash('sha256',$secret),'owner'=>$owner,'createdAt'=>gmdate('c'),'push'=>$push,'pull'=>($previous['pull']??false) === true,'code'=>$push && $code]];
    }

    public static function matches(string $secret, array $record): bool {
        return preg_match('/^zc_[a-f0-9]{64}$/D',$secret)===1
            && is_string($record['hash']??null)
            && hash_equals($record['hash'],hash('sha256',$secret));
    }

    public static function permits(array $record, string $scope): bool {
        if($scope==='status')return true;
        if($scope==='code')return ($record['push']??false)===true && (array_key_exists('code',$record) ? $record['code']===true : true);
        return in_array($scope,['push','pull'],true) && ($record[$scope]??false)===true;
    }

    /** A pre-0.10.0 key whose code access comes only from Push (no stored `code` choice). */
    public static function legacyCode(array $record): bool {
        return isset($record['hash']) && ($record['push']??false)===true && !array_key_exists('code',$record);
    }

    /** True when a live path is plugin or theme code rather than an upload. */
    public static function codePath(string $path): bool {
        return preg_match(self::CODE_PATH,$path)===1;
    }

    /** True when any `path` in a list of file entries is plugin or theme code. */
    public static function touchesCode($files): bool {
        if(!is_array($files))return false;
        foreach($files as $file)if(is_array($file)&&is_string($file['path']??null)&&self::codePath($file['path']))return true;
        return false;
    }

    /** An import request needs Code when it writes plugin/theme files or asks to replace the
     * destination's plugin or theme selection (resources.plugins / resources.themes). */
    public static function importTouchesCode(array $body): bool {
        $resources=is_array($body['resources']??null)?$body['resources']:[];
        return self::touchesCode($body['files']??null) || ($resources['plugins']??false)===true || ($resources['themes']??false)===true;
    }
}
