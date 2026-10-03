<?php
namespace ZoerConnect;
final class FileExporter {
    /** Match the receiving client's portable hidden-file boundary. Unknown dotfiles
     * may contain credentials or development caches; never export them by default. */
    public static function portableHiddenPath(string $path):bool {
        $metadata=['.editorconfig','.gitattributes','.gitignore','.gitkeep','.npmignore','.nvmrc','.node-version','.deployignore','.deepsource.toml','.wp-env.json',
            '.prettierignore','.prettierrc','.prettierrc.json','.prettierrc.yaml','.prettierrc.yml','.prettierrc.js','.prettierrc.cjs',
            '.eslintignore','.eslintrc','.eslintrc.json','.eslintrc.yaml','.eslintrc.yml','.eslintrc.js','.eslintrc.cjs',
            '.markdownlintignore','.markdownlint.json','.markdownlint.yaml','.markdownlint.yml',
            '.phpcs.xml','.phpcs.xml.dist','.phpcs.dir.xml','.phpcs.dir.phpcompatibility.xml','.phpstorm.meta.php'];
        $parts=explode('/',$path);foreach($parts as $i=>$part){
            if(!str_starts_with($part,'.'))continue;$leaf=$i===count($parts)-1;
            if($leaf&&$part==='.htaccess'&&str_starts_with($path,'wp-content/'))continue;
            if(preg_match('~^wp-content/(plugins|themes)/~',$path)&&($leaf?in_array($part,$metadata,true):in_array($part,['.trash','.github'],true)))continue;
            return false;
        }return true;
    }
    /** Content roots to traverse. Theme/plugin modes resolve against a trusted top-level scan of the source;
     * $active lists active theme slugs (stylesheet and template) and plugin slugs (directory or single file). */
    public static function roots(string $root,array $profile,array $active=[]): array {
        $roots=[];
        foreach(['themes'=>'wp-content/themes','plugins'=>'wp-content/plugins','media'=>'wp-content/uploads','muplugins'=>'wp-content/mu-plugins'] as $key=>$path){
            if(!$profile[$key])continue;
            $mode=$profile[$key.'Mode']??'all';
            if($mode==='all'){$roots[]=$path;continue;}
            $dir=rtrim($root,'/').'/'.$path;if(!file_exists($dir)&&!is_link($dir))continue;
            if(is_link($dir)||!is_dir($dir))throw new \RuntimeException('Unsafe source directory.');
            $names=scandir($dir);if($names===false)throw new \RuntimeException('Unsafe source directory.');
            $inventory=[];foreach($names as $name)if($name!=='.'&&$name!=='..')$inventory[]=['id'=>$name,'active'=>in_array($name,$active[$key]??[],true)];
            $items=$profile[$key.'Items']??[];
            // Excluding a resource that no longer exists is harmless; selecting one is refused.
            if($mode==='except')$items=array_values(array_intersect($items,array_column($inventory,'id')));
            foreach(Selection::resources($inventory,$mode,$items) as $item)$roots[]=$path.'/'.$item['id'];
        }
        if($profile['core']){$roots[]='wp-admin';$roots[]='wp-includes';}
        return $roots;
    }
    public static function plan(string $root,array $profile,array $active=[]): array {
        $profile=ExportProfile::normalize($profile);$root=realpath($root);
        if(!$root)throw new \RuntimeException('Source missing.');
        $roots=self::roots($root,$profile,$active);$since=ExportProfile::mediaSince($profile);
        $files=[];
        $add=static function(string $path)use(&$files,$root,$profile,$since){
            Selection::path($path);
            if(!self::portableHiddenPath($path))return;
            if(str_starts_with($path,'wp-content/plugins/zoer-connect/'))return;
            if(Selection::excluded($path,['**/.git/','**/node_modules/','**/.env','**/.env.*','**/*.log',...$profile['excludes']]))return;
            $full=$root.'/'.$path;$real=realpath($full);
            if(is_link($full)||!$real||!str_starts_with($real,$root.'/')||!is_file($real))throw new \RuntimeException('Unsafe source file.');
            if($since!==null&&str_starts_with($path,'wp-content/uploads/')&&filemtime($real)<$since)return;
            $bytes=filesize($real);
            if($bytes>33554432)throw new \RuntimeException('A selected file exceeds the current 32 MiB export limit.');
            $files[]=['path'=>$path,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$real)];
            if(count($files)>20000)throw new \RuntimeException('Too many selected files.');
        };
        foreach($roots as $relative){
            $dir=$root.'/'.$relative;if(!file_exists($dir))continue;
            // A selected single-file plugin is a file root.
            if(!is_link($dir)&&is_file($dir)&&substr_count($relative,'/')===2){$add($relative);continue;}
            if(is_link($dir)||!is_dir($dir))throw new \RuntimeException('Unsafe source directory.');
            $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
            foreach($walk as $entry){if($entry->isLink())throw new \RuntimeException('Symlink source rejected.');if($entry->isFile())$add(substr($entry->getPathname(),strlen($root)+1));}
        }
        if($profile['core'])foreach(['index.php','license.txt','readme.html','wp-activate.php','wp-blog-header.php','wp-comments-post.php','wp-cron.php','wp-links-opml.php','wp-load.php','wp-login.php','wp-mail.php','wp-settings.php','wp-signup.php','wp-trackback.php','xmlrpc.php'] as $file)if(is_file($root.'/'.$file))$add($file);
        usort($files,static fn($a,$b)=>strcmp($a['path'],$b['path']));
        return ['version'=>1,'kind'=>'file-export','profile'=>$profile,'files'=>$files,'bytes'=>array_sum(array_column($files,'bytes'))];
    }
    public static function zip(string $root,array $plan,string $destination,array $active=[]): void {
        $verified=self::plan($root,$plan['profile'],$active);
        if($verified!==$plan)throw new \RuntimeException('Source changed since review.');
        if($plan['bytes']>536870912)throw new \RuntimeException('Selected export exceeds 512 MiB.');
        $zip=new \ZipArchive();if($zip->open($destination,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Cannot create export.');
        try{
            if(!$zip->addFromString('zoer-manifest.json',json_encode($plan,JSON_THROW_ON_ERROR)))throw new \RuntimeException('Cannot write manifest.');
            foreach($plan['files'] as $file){if(!$zip->addFile(rtrim($root,'/').'/'.$file['path'],'wordpress/'.$file['path']))throw new \RuntimeException('Cannot add export file.');}
            if(!$zip->close())throw new \RuntimeException('Cannot finalize export.');
            if($zip->open($destination)!==true)throw new \RuntimeException('Cannot verify export.');
            foreach($plan['files'] as $file){$stream=$zip->getStream('wordpress/'.$file['path']);if(!$stream)throw new \RuntimeException('Export entry missing.');$hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);if(hash_final($hash)!==$file['sha256'])throw new \RuntimeException('Source changed during export.');}
            $zip->close();
        }catch(\Throwable $e){@$zip->close();@unlink($destination);throw $e;}
    }
}
