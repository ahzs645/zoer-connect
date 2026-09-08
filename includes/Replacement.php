<?php
namespace ZoerConnect;
/** Conservative replacements: objects/references are refused, never instantiated. */
final class Replacement {
    public static function apply(string $value,array $rules): string {
        if(strlen($value)>1048576||count($rules)>50)throw new \InvalidArgumentException('Replacement limit exceeded.');
        foreach($rules as $r)if(!is_array($r)||!is_string($r['find']??null)||$r['find']===''||!is_string($r['replace']??null)||!in_array($r['mode']??null,['literal','regex'],true))throw new \InvalidArgumentException('Invalid replacement rule.');
        return self::walk($value,$rules,0);
    }
    private static function walk($value,array $rules,int $depth) {
        if($depth>30)throw new \RuntimeException('Nested value limit exceeded.');
        if(is_array($value)){ $out=[];foreach($value as $key=>$item)$out[$key]=self::walk($item,$rules,$depth+1);return $out; }
        if(!is_string($value))return $value;
        if(preg_match('/^(?:a|s|i|b|d|O|C|R|r):|^N;$/',$value)){
            if(preg_match('/(?:^|[;{}])(?:O|C|R|r):/',$value))throw new \RuntimeException('Serialized objects and references are unsupported.');
            $decoded=@unserialize($value,['allowed_classes'=>false,'max_depth'=>32]);
            if($decoded===false&&$value!=='b:0;')throw new \RuntimeException('Malformed serialized value.');
            return serialize(self::walk($decoded,$rules,$depth+1));
        }
        foreach($rules as $r){
            if($r['mode']==='literal')$value=str_replace($r['find'],$r['replace'],$value);
            else{
                if(strlen($r['find'])>500)throw new \InvalidArgumentException('Pattern too long.');
                $old=ini_get('pcre.backtrack_limit');ini_set('pcre.backtrack_limit','100000');
                try{$next=@preg_replace($r['find'],$r['replace'],$value);if($next===null)throw new \RuntimeException('Regex failed or exceeded limits.');$value=$next;}
                finally{ini_set('pcre.backtrack_limit',$old);}
            }
            if(strlen($value)>1048576)throw new \RuntimeException('Replacement output too large.');
        }
        return $value;
    }
}
