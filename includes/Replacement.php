<?php
namespace ZoerConnect;
/** Conservative replacements: objects/references are refused, never instantiated.
 * Rules marked atomic (generated URL/path rules) run as ONE left-to-right pass,
 * longest match first, so a destination containing a source never re-matches.
 * A bounded atomic rule only matches when a path boundary follows it.
 */
final class Replacement {
    public const MAX_VALUE = 16777216;
    public const MAX_RULES = 250;
    public static function apply(string $value,array $rules,?int &$count=null): string {
        $count=0;
        if(count($rules)>self::MAX_RULES)throw new \InvalidArgumentException('Replacement limit exceeded.');
        foreach($rules as $r)if(!is_array($r)||!is_string($r['find']??null)||$r['find']===''||!is_string($r['replace']??null)||!in_array($r['mode']??null,['literal','regex'],true)||!is_bool($r['caseSensitive']??true)||!is_bool($r['boundary']??false)||(($r['atomic']??false)&&($r['mode']!=='literal'||!($r['caseSensitive']??true))))throw new \InvalidArgumentException('Invalid replacement rule.');
        // Unrelated serialized plugin state must survive byte-for-byte. Do not
        // deserialize objects (e.g. Action Scheduler schedules) to change nothing.
        $possible=false;
        foreach($rules as $r)if(self::matches($value,$r)){$possible=true;break;}
        if(!$possible)return $value;
        // Only values a rule can change are bounded; unrelated large values pass untouched.
        if(strlen($value)>self::MAX_VALUE)throw new \InvalidArgumentException('Replacement limit exceeded.');
        return self::walk($value,$rules,0,$count);
    }
    private static function matches(string $value,array $r): bool {
        if($r['mode']==='literal')return ($r['caseSensitive']??true)?str_contains($value,$r['find']):stripos($value,$r['find'])!==false;
        // Arrays without objects are walked for anchored patterns; object payloads
        // are only refused when the pattern can match their serialized bytes.
        if(self::regex(static fn()=>@preg_match($r['find'],$value))===1)return true;
        return (bool)preg_match('/^(?:a|s):/',$value)&&!preg_match('/(?:^|[;{}])(?:O|C|R|r):/',$value);
    }
    private static function regex(callable $fn) {
        $old=ini_get('pcre.backtrack_limit');ini_set('pcre.backtrack_limit','100000');
        try{$result=$fn();if($result===null||$result===false)throw new \RuntimeException('Regex failed or exceeded limits.');return $result;}
        finally{ini_set('pcre.backtrack_limit',$old);}
    }
    private static function walk($value,array $rules,int $depth,int &$count) {
        if($depth>30)throw new \RuntimeException('Nested value limit exceeded.');
        if(is_array($value)){ $out=[];foreach($value as $key=>$item)$out[$key]=self::walk($item,$rules,$depth+1,$count);return $out; }
        if(!is_string($value))return $value;
        if(preg_match('/^(?:a|s|i|b|d|O|C|R|r):|^N;$/',$value)){
            if(preg_match('/(?:^|[;{}])(?:O|C|R|r):/',$value))throw new \RuntimeException('Serialized objects and references are unsupported.');
            $decoded=@unserialize($value,['allowed_classes'=>false,'max_depth'=>32]);
            if($decoded===false&&$value!=='b:0;')throw new \RuntimeException('Malformed serialized value.');
            return serialize(self::walk($decoded,$rules,$depth+1,$count));
        }
        for($i=0,$n=count($rules);$i<$n;$i++){
            $r=$rules[$i];$c=0;
            if($r['atomic']??false){
                $pairs=[];$bounded=[];for(;$i<$n&&($rules[$i]['atomic']??false);$i++)if(!array_key_exists('k'.$rules[$i]['find'],$pairs)){$pairs['k'.$rules[$i]['find']]=$rules[$i]['replace'];$bounded['k'.$rules[$i]['find']]=$rules[$i]['boundary']??false;}$i--;
                $finds=array_map(static fn($k)=>substr($k,1),array_keys($pairs));
                if(!array_filter($finds,static fn($f)=>str_contains($value,$f)))continue;
                usort($finds,static fn($a,$b)=>strlen($b)<=>strlen($a));
                // Boundary: path separator, quote, whitespace, backslash, '<', ')' or end.
                $pattern='/'.implode('|',array_map(static fn($f)=>preg_quote($f,'/').($bounded['k'.$f]?'(?=[\/\'"\s\\\\<)]|\z)':''),$finds)).'/';
                $value=self::regex(static function()use($pattern,$pairs,$value,&$c){return preg_replace_callback($pattern,static fn($m)=>$pairs['k'.$m[0]],$value,-1,$c);});
            }
            elseif($r['mode']==='literal')$value=($r['caseSensitive']??true)?str_replace($r['find'],$r['replace'],$value,$c):str_ireplace($r['find'],$r['replace'],$value,$c);
            else{
                if(strlen($r['find'])>500)throw new \InvalidArgumentException('Pattern too long.');
                $value=self::regex(static function()use($r,$value,&$c){return @preg_replace($r['find'],$r['replace'],$value,-1,$c);});
            }
            $count+=$c;
            if(strlen($value)>self::MAX_VALUE)throw new \RuntimeException('Replacement output too large.');
        }
        return $value;
    }
    /** Pattern actually run for a custom regex row: caseSensitive=false appends the
     * i modifier after the closing delimiter (PHP ignores spaces among modifiers). */
    public static function pattern(string $find,bool $caseSensitive): string {return $caseSensitive?$find:$find.'i';}
    /** Validated client rows. Regex rows need delimiters; the pattern that will run
     * (including the case-insensitive modifier) must compile. */
    public static function custom($rows): array {
        if($rows===null)return [];
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>50)throw new \InvalidArgumentException('Invalid custom replacements.');
        $out=[];
        foreach($rows as $row){
            if(!is_array($row)||array_diff(array_keys($row),['find','replace','regex','caseSensitive'])||!is_string($row['find']??null)||$row['find']===''||strlen($row['find'])>4096||!is_string($row['replace']??null)||strlen($row['replace'])>4096||!is_bool($row['regex']??false)||!is_bool($row['caseSensitive']??true))throw new \InvalidArgumentException('Invalid custom replacement row.');
            $regex=$row['regex']??false;$sensitive=$row['caseSensitive']??true;
            if($regex&&(strlen($row['find'])>500||str_contains($row['find'],"\0")||@preg_match(self::pattern($row['find'],$sensitive),'')===false))throw new \InvalidArgumentException('Invalid custom replacement pattern.');
            $out[]=['find'=>$row['find'],'replace'=>$row['replace'],'regex'=>$regex,'caseSensitive'=>$sensitive];
        }
        return $out;
    }
    /** 0.3.14 rules for clients that send no import options: every URL, untrailingslashed,
     * replaced by $target sequentially in the given order (earlier outputs can re-match). */
    public static function legacy(array $urls,string $target): array {return array_values(array_map(static fn($url)=>['mode'=>'literal','find'=>rtrim((string)$url,'/'),'replace'=>$target],$urls));}
    /** Ordered rules: automatic URLs (longest first), variants, paths, then custom rows.
     * $urls is a list (every source URL -> $target) or a map source URL => destination URL.
     * A source path needs at least two segments and only matches up to a path boundary.
     */
    public static function rules(array $urls,string $target,array $options,?string $sourcePath=null,?string $destinationPath=null): array {
        $rules=[];$seen=[$target=>true];
        $add=static function(string $find,string $replace,string $group,bool $boundary=false)use(&$rules,&$seen){if($find===''||$find===$replace||isset($seen[$find]))return;$seen[$find]=true;$rules[]=['mode'=>'literal','find'=>$find,'replace'=>$replace,'atomic'=>true,'group'=>$group]+($boundary?['boundary'=>true]:[]);};
        $map=[];
        if($options['automatic']??true){
            $list=array_is_list($urls);
            foreach($urls as $key=>$value){[$url,$to]=$list?[$value,$target]:[$key,$value];if(is_string($url)&&$url!==''&&is_string($to)&&$to!=='')$map[rtrim($url,'/')]??=rtrim($to,'/');}
            uksort($map,static fn($a,$b)=>strlen((string)$b)<=>strlen((string)$a));
            foreach($map as $find=>$to)$add((string)$find,$to,'automatic');
        }
        if($map&&($options['variants']??false)){
            $twin=static fn($u)=>str_starts_with($u,'https://')?'http://'.substr($u,8):(str_starts_with($u,'http://')?'https://'.substr($u,7):$u);
            $all=[];foreach($map as $u=>$to){$all[]=[(string)$u,$to];$all[]=[$twin((string)$u),$to];}
            foreach($all as [$u,$to])$add($u,$to,'variant');
            foreach($all as [$u,$to])$add(str_replace('/','\\/',$u),str_replace('/','\\/',$to),'variant');
            foreach($all as [$u,$to])$add(rawurlencode($u),rawurlencode($to),'variant');
            $relative=static fn($u)=>preg_replace('~^https?:~','',$u);
            foreach($map as $u=>$to)$add($relative((string)$u),$relative($to),'variant');
        }
        if(($options['paths']??false)&&is_string($sourcePath)&&is_string($destinationPath)){
            $from=rtrim($sourcePath,'/');$to=rtrim($destinationPath,'/');
            // A one-segment source path (e.g. /app) is too ambiguous: omit the rule.
            if(count(array_filter(explode('/',$from),static fn($p)=>$p!==''))>=2&&$to!==''){$add($from,$to,'path',true);$add(str_replace('/','\\/',$from),str_replace('/','\\/',$to),'path',true);}
        }
        foreach($options['custom']??[] as $row){
            $rules[]=['mode'=>$row['regex']?'regex':'literal','find'=>$row['regex']?self::pattern($row['find'],$row['caseSensitive']):$row['find'],'replace'=>$row['replace'],'caseSensitive'=>$row['regex']?true:$row['caseSensitive'],'group'=>'custom'];
        }
        if(count($rules)>self::MAX_RULES)throw new \InvalidArgumentException('Replacement limit exceeded.');
        return $rules;
    }
}
