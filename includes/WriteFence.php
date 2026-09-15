<?php
namespace ZoerConnect;

/** WordPress request fence. Requires the earliest MU bootstrap on a single web
 * host with local flock semantics. External database writers must be excluded by
 * deployment policy; an HTTP maintenance flag cannot fence direct SQL clients.
 */
final class WriteFence {
    private const SOURCE_HASH='bdff5d75aaf6b2ace3348d59016098daef717ad37b00f98373e065acce8d57ed';
    public static function runtimeVerified(): bool {
        $source=@file_get_contents(__FILE__);
        if($source===false)return false;
        $normalized=preg_replace("/private const SOURCE_HASH='[a-f0-9]{64}';/", "private const SOURCE_HASH='".str_repeat('0',64)."';",$source,1);
        return is_string($normalized)&&hash_equals(self::SOURCE_HASH,hash('sha256',$normalized));
    }

    private string $private;
    private string $root;
    private static $lease=null;
    private const MU='000-zoer-connect-fence.php';
    public function __construct(string $privateRoot,string $wordpressRoot) {
        $private=realpath($privateRoot); $root=realpath($wordpressRoot);
        if (!$private||!$root||is_link($privateRoot)||$private===$root||str_starts_with($private,$root.'/')) throw new \RuntimeException('Private write fence storage is required.');
        $this->private=$private; $this->root=$root;
    }
    private function bootstrap(bool $sharedCaches=false): string {
        $plugin=$this->root.'/wp-content/plugins/zoer-connect/includes/';
        return "<?php\n".($sharedCaches?"// Shared-hosting cache coexistence; early cache responses are outside this fence.\n":"")."// Zoer Connect request fence: keep before ordinary plugins and MU plugins.\ndefined('ABSPATH') || exit;\nrequire_once ".var_export($plugin.'WriteFence.php',true).";\n\\ZoerConnect\\WriteFence::boot(".var_export($this->private,true).", ".var_export($this->root,true).", static function (string \$route) {\n require_once ".var_export($plugin.'Plugin.php',true).";\n return \\ZoerConnect\\Plugin::earlyImportRecovery(\$route);\n});\n";
    }
    private function compatible(bool $sharedCaches=false): bool {
        if (defined('WP_CONTENT_DIR') && realpath(WP_CONTENT_DIR)!==realpath($this->root.'/wp-content')) return false;
        if (defined('WPMU_PLUGIN_DIR') && realpath(WPMU_PLUGIN_DIR)!==realpath($this->root.'/wp-content/mu-plugins')) return false;
        // These execute before MU plugins and could write or serve an early response.
        foreach (['advanced-cache.php','object-cache.php','db.php','sunrise.php','maintenance.php'] as $name)
            if (is_link($this->root.'/wp-content/'.$name)) return false;
            elseif (file_exists($this->root.'/wp-content/'.$name) && (!$sharedCaches || !in_array($name,['advanced-cache.php','object-cache.php'],true) || !is_file($this->root.'/wp-content/'.$name))) return false;
        return true;
    }
    public function installed(): bool {
        if (!$this->compatible($this->sharedCaches())) return false;
        $dir=$this->root.'/wp-content/mu-plugins'; $path=$dir.'/'.self::MU;
        if (is_link($dir)||is_link($path)||!is_file($path)||file_get_contents($path)!==$this->bootstrap($this->sharedCaches())) return false;
        foreach (glob($dir.'/*.php')?:[] as $file) if (strcmp(basename($file),self::MU)<0) return false;
        return true;
    }
    /** Explicit setup step, never invoked as a side effect of normal requests. */
    public function sharedCaches(): bool {
        $path=$this->root.'/wp-content/mu-plugins/'.self::MU;
        return !is_link($path)&&is_file($path)&&file_get_contents($path)===$this->bootstrap(true);
    }
    public function install(bool $sharedCaches=false): void {
        $this->control(fn()=>$this->installLocked($sharedCaches));
    }
    private function installLocked(bool $sharedCaches): void {
        if ($this->marker()!==null) throw new \RuntimeException('Finish or recover the active import before changing setup.');
        if (!$this->compatible($sharedCaches)) throw new \RuntimeException('Early WordPress drop-ins and custom content paths are unsupported by this fence.');
        $dir=$this->root.'/wp-content/mu-plugins'; $path=$dir.'/'.self::MU;
        if (is_link($dir)||is_link($path)) throw new \RuntimeException('MU bootstrap must not be a symlink.');
        if (!is_dir($dir)&&!mkdir($dir,0755,true)) throw new \RuntimeException('Cannot create MU directory.');
        foreach (glob($dir.'/*.php')?:[] as $file) if (strcmp(basename($file),self::MU)<0) throw new \RuntimeException('An earlier MU plugin prevents a reliable fence.');
        $body=$this->bootstrap($sharedCaches);
        $previous=null;
        if (file_exists($path)) {
            if (file_get_contents($path)===$body) return;
            if (file_get_contents($path)!==$this->bootstrap(!$sharedCaches)) throw new \RuntimeException('Existing MU fence differs; inspect it before replacement.');
            $previous=file_get_contents($path);
        }
        // Never expose a partial *.php file to another WordPress request.
        $tmp=$dir.'/.zoer-fence-'.bin2hex(random_bytes(8)).'.tmp';
        $h=fopen($tmp,'x');
        if (!$h) throw new \RuntimeException('Cannot stage MU bootstrap.');
        try {
            if (fwrite($h,$body)!==strlen($body)||!fflush($h)||(function_exists('fsync')&&!fsync($h))||!chmod($tmp,0644)) throw new \RuntimeException('Cannot write MU bootstrap.');
            fclose($h);$h=null;
            // link publishes atomically without ever overwriting another installer.
            if ($previous!==null) {
                if (file_get_contents($path)!==$previous || !rename($tmp,$path)) throw new \RuntimeException('MU bootstrap changed during setup.');
            } elseif(function_exists('link')) {
                if(!link($tmp,$path))throw new \RuntimeException('Cannot atomically install MU bootstrap.');
            } else {
                // Shared hosts may disable hard links. The private control lock
                // serializes our installers; only rename fully flushed bytes.
                clearstatcache(true,$path);
                if(file_exists($path)||is_link($path)||!rename($tmp,$path))throw new \RuntimeException('Cannot atomically install MU bootstrap.');
            }
        } finally {if(is_resource($h))fclose($h);if(is_file($tmp))unlink($tmp);}
    }
    private function identity(string $id,string $binding): void {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$binding)) throw new \InvalidArgumentException('Invalid fence identity.');
    }
    private function marker(): ?array {
        $path=$this->private.'/write-fence.json';
        if (!file_exists($path)) return null;
        $state=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new \RuntimeException('Invalid fence journal.');
        return $state;
    }
    private function owner(string $id,string $binding): void {
        $s=$this->marker();
        if (!$s||($s['id']??null)!==$id||!is_string($s['binding']??null)||!hash_equals($s['binding'],$binding)) throw new \RuntimeException('Import does not own the write fence.');
    }
    private function control(callable $fn) {
        $h=fopen($this->private.'/write-fence.control','c');
        if (!$h||!flock($h,LOCK_EX|LOCK_NB)) { if($h)fclose($h); throw new \RuntimeException('Write fence control is busy.'); }
        try {return $fn();}finally{flock($h,LOCK_UN);fclose($h);}
    }
    public function reserve(string $id,string $binding): void {
        $this->identity($id,$binding);
        $this->control(function()use($id,$binding){
            if (!$this->installed()) throw new \RuntimeException('Install and verify the earliest MU fence first.');
            if ($this->marker()!==null) {$this->owner($id,$binding);return;}
            $json=json_encode(['id'=>$id,'binding'=>$binding,'createdAt'=>time()],JSON_THROW_ON_ERROR);
            $tmp=$this->private.'/write-fence.tmp';
            if(file_put_contents($tmp,$json)!==strlen($json)||!chmod($tmp,0600)||!rename($tmp,$this->private.'/write-fence.json'))throw new \RuntimeException('Cannot persist write fence.');
        });
    }
    public function exclusive(string $id,string $binding,callable $fn) {
        $this->identity($id,$binding);
        return $this->control(function()use($id,$binding,$fn){
            $this->owner($id,$binding);
            if (!$this->installed()) throw new \RuntimeException('MU fence changed.');
            $h=fopen($this->private.'/write-fence.requests','c');
            if (!$h||!flock($h,LOCK_EX|LOCK_NB)) {if($h)fclose($h);throw new \RuntimeException('Earlier WordPress requests are still draining; retry.');}
            try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);}
        });
    }
    /** Caller has verified completion or restoration; never release on timeout. */
    public function release(string $id,string $binding,?callable $beforeRelease=null): void {
        $this->identity($id,$binding);
        if ($this->marker()===null) return; // Replay after release persisted but caller journal write was interrupted.
        $this->exclusive($id,$binding,function()use($beforeRelease){if($beforeRelease!==null)$beforeRelease();if(!unlink($this->private.'/write-fence.json'))throw new \RuntimeException('Cannot release write fence.');});
    }
    public function enter(): void {
        if (self::$lease!==null) return;
        $h=fopen($this->private.'/write-fence.requests','c');
        if (!$h||!flock($h,LOCK_SH|LOCK_NB)) {if($h)fclose($h);throw new \RuntimeException('WordPress is paused for a verified transfer.');}
        try {if($this->marker()!==null)throw new \RuntimeException('WordPress is paused for a verified transfer.');}
        catch(\Throwable $e){flock($h,LOCK_UN);fclose($h);throw $e;}
        // Retain through every shutdown callback, including plugins that write there.
        self::$lease=$h;
        require_once __DIR__.'/RequestDrain.php';
        (new RequestDrain($this->private))->enrolled();
    }
    public static function importRoute(array $server,array $query): ?string {
        $route=$query['rest_route']??null;
        if ($route===null) {
            $path=parse_url((string)($server['REQUEST_URI']??''),PHP_URL_PATH);
            if (is_string($path)&&preg_match('~/wp-json(/zoer-connect/v1/imports(?:/.*)?)$~D',$path,$m))$route=$m[1];
        }
        return is_string($route)&&preg_match('~^/zoer-connect/v1/imports(?:/[a-f0-9]{32}(?:/[a-z-]+)?)?/?$~D',$route)?rtrim($route,'/'):null;
    }
    /** Runs during MU loading, before imported regular plugins or themes execute. */
    public static function boot(string $private,string $root,callable $dispatch): void {
        $route=self::importRoute($_SERVER,$_GET);
        try {
            $fence=new self($private,$root);
            if (!$fence->installed()) throw new \RuntimeException('MU fence verification failed.');
            if ($route!==null) {
                // Authentication is compulsory in this protected handler. Do not
                // defer to rest_api_init, which runs after ordinary plugin code.
                $result=$dispatch($route);
                header('Content-Type: application/json');header('Cache-Control: no-store');
                echo json_encode($result,JSON_THROW_ON_ERROR);exit;
            }
            $fence->enter();
        } catch(\Throwable $e) {
            http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');header('Retry-After: 5');
            echo '{"code":"zoer_transfer_paused","message":"WordPress transfer recovery is required or a request is still draining."}';exit;
        }
    }
}
