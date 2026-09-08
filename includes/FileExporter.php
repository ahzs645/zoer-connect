<?php
namespace ZoerConnect;
final class FileExporter {
    public static function plan(string $root,array $profile): array {
        $profile=ExportProfile::normalize($profile);$root=realpath($root);
        if(!$root)throw new \RuntimeException('Source missing.');
        $roots=[];foreach(['themes'=>'wp-content/themes','plugins'=>'wp-content/plugins','media'=>'wp-content/uploads','muplugins'=>'wp-content/mu-plugins'] as $key=>$path)if($profile[$key])$roots[]=$path;
        if($profile['core']){$roots[]='wp-admin';$roots[]='wp-includes';}
        $files=[];
        $add=static function(string $path)use(&$files,$root,$profile){
            Selection::path($path);
            if(str_starts_with($path,'wp-content/plugins/zoer-connect/'))return;
            if(Selection::excluded($path,['**/.git/','**/node_modules/','**/.env','**/.env.*','**/*.log',...$profile['excludes']]))return;
            $full=$root.'/'.$path;$real=realpath($full);
            if(is_link($full)||!$real||!str_starts_with($real,$root.'/')||!is_file($real))throw new \RuntimeException('Unsafe source file.');
            $bytes=filesize($real);
            if($bytes>33554432)throw new \RuntimeException('A selected file exceeds the current 32 MiB export limit.');
            $files[]=['path'=>$path,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$real)];
            if(count($files)>20000)throw new \RuntimeException('Too many selected files.');
        };
        foreach($roots as $relative){
            $dir=$root.'/'.$relative;if(!file_exists($dir))continue;
            if(is_link($dir)||!is_dir($dir))throw new \RuntimeException('Unsafe source directory.');
            $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
            foreach($walk as $entry){if($entry->isLink())throw new \RuntimeException('Symlink source rejected.');if($entry->isFile())$add(substr($entry->getPathname(),strlen($root)+1));}
        }
        if($profile['core'])foreach(['index.php','license.txt','readme.html','wp-activate.php','wp-blog-header.php','wp-comments-post.php','wp-cron.php','wp-links-opml.php','wp-load.php','wp-login.php','wp-mail.php','wp-settings.php','wp-signup.php','wp-trackback.php','xmlrpc.php'] as $file)if(is_file($root.'/'.$file))$add($file);
        usort($files,static fn($a,$b)=>strcmp($a['path'],$b['path']));
        return ['version'=>1,'kind'=>'file-export','profile'=>$profile,'files'=>$files,'bytes'=>array_sum(array_column($files,'bytes'))];
    }
    public static function zip(string $root,array $plan,string $destination): void {
        $verified=self::plan($root,$plan['profile']);
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
