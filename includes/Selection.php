<?php
namespace ZoerConnect;

/** Pure planning filters. Inventory must be obtained by a trusted source scanner. */
final class Selection {
    public static function resources(array $inventory, string $mode, array $selected=[]): array {
        if(!in_array($mode,['all','active','selected','except'],true))throw new \InvalidArgumentException('Unknown selection mode.');
        $known=[];
        foreach($inventory as $item){if(!isset($item['id'])||!is_string($item['id'])||isset($known[$item['id']]))throw new \InvalidArgumentException('Invalid inventory.');$known[$item['id']]=true;}
        foreach($selected as $id)if(!is_string($id)||!isset($known[$id]))throw new \InvalidArgumentException('Selection is absent from inventory.');
        return array_values(array_filter($inventory,static fn($item)=>match($mode){
            'all'=>true,'active'=>($item['active']??false)===true,'selected'=>in_array($item['id'],$selected,true),'except'=>!in_array($item['id'],$selected,true),
        }));
    }
    public static function path(string $path): void {
        if($path===''||str_starts_with($path,'/')||str_contains($path,'\\')||preg_match('/[\x00-\x1f\x7f]/',$path))throw new \InvalidArgumentException('Unsafe relative path.');
        foreach(explode('/',$path) as $part)if($part===''||$part==='.'||$part==='..')throw new \InvalidArgumentException('Unsafe relative path.');
    }
    /** Ordered glob exclusions: *, ?, **, root slash, directory slash and ! negation.
     * Bracket classes and escape syntax deliberately rejected, not silently misinterpreted.
     */
    public static function excluded(string $path,array $patterns): bool {
        self::path($path);$excluded=false;
        foreach($patterns as $pattern){
            if(!is_string($pattern)||strlen($pattern)>500)throw new \InvalidArgumentException('Invalid exclusion.');
            $pattern=trim($pattern);if($pattern===''||$pattern[0]==='#')continue;
            $negated=$pattern[0]==='!';if($negated)$pattern=substr($pattern,1);
            if($pattern===''||strpbrk($pattern,'[]\\')!==false||preg_match('/[\x00-\x1f\x7f]/',$pattern))throw new \InvalidArgumentException('Unsupported glob syntax.');
            $anchored=str_starts_with($pattern,'/');$directory=str_ends_with($pattern,'/');$pattern=trim($pattern,'/');
            if($pattern==='')throw new \InvalidArgumentException('Empty glob.');
            $regex='';
            for($i=0;$i<strlen($pattern);$i++){
                if(substr($pattern,$i,3)==='**/'){$regex.='(?:.*/)?';$i+=2;}
                elseif(substr($pattern,$i,2)==='**'){$regex.='.*';$i++;}
                elseif($pattern[$i]==='*')$regex.='[^/]*';
                elseif($pattern[$i]==='?')$regex.='[^/]';
                else $regex.=preg_quote($pattern[$i],'~');
            }
            $prefix=($anchored||str_contains($pattern,'/'))?'^':'(?:^|/)';
            $suffix=$directory?'(?:/.*)$':'(?:$|/.*$)';
            if(preg_match('~'.$prefix.$regex.$suffix.'~D',$path))$excluded=!$negated;
        }
        return $excluded;
    }
    public static function media(array $files,string $mode,array $baseline=[],?int $after=null,array $excludes=[]): array {
        if(!in_array($mode,['all','changed','after'],true)||($mode==='after'&&($after===null||$after<0)))throw new \InvalidArgumentException('Invalid media mode/date.');
        $result=[];
        foreach($files as $file){
            self::path($file['path']??'');
            if(!is_int($file['mtime']??null)||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']??''))throw new \InvalidArgumentException('Invalid media inventory.');
            if(self::excluded($file['path'],$excludes))continue;
            if($mode==='changed'&&isset($baseline[$file['path']])&&hash_equals($baseline[$file['path']],$file['sha256']))continue;
            if($mode==='after'&&$file['mtime']<=$after)continue;
            $result[]=$file;
        }
        return $result;
    }
    /** Filters a supplied row collection; related-table reconciliation is caller responsibility. */
    public static function rows(array $rows,string $kind,array $options): array {
        $allowed=['posts','comments','options'];if(!in_array($kind,$allowed,true))throw new \InvalidArgumentException('Unsupported row kind.');
        if(array_diff(array_keys($options),['postTypes','excludeRevisions','excludeSpam','excludeTransients']))throw new \InvalidArgumentException('Unknown row option.');
        $types=$options['postTypes']??null;
        if($types!==null){if(!is_array($types))throw new \InvalidArgumentException('Invalid post types.');foreach($types as $type)if(!is_string($type)||!preg_match('/^[a-zA-Z0-9_-]+$/D',$type))throw new \InvalidArgumentException('Invalid post type.');}
        foreach(['excludeRevisions','excludeSpam','excludeTransients'] as $key)if(isset($options[$key])&&!is_bool($options[$key]))throw new \InvalidArgumentException('Boolean required.');
        return array_values(array_filter($rows,static function($row)use($kind,$options,$types){
            if($kind==='posts')return !(($options['excludeRevisions']??false)&&($row['post_type']??'')==='revision')&&($types===null||in_array($row['post_type']??'',$types,true));
            if($kind==='comments')return !(($options['excludeSpam']??false)&&($row['comment_approved']??'')==='spam');
            return !(($options['excludeTransients']??false)&&(str_starts_with($row['option_name']??'','_transient_')||str_starts_with($row['option_name']??'','_site_transient_')));
        }));
    }
}
