<?php
namespace ZoerConnect;
final class ExportProfile {
    // Finder metadata and repository control files are not WordPress site
    // content, and Zoer cannot import these paths.
    public const DEFAULT_EXCLUDES=['**/.DS_Store','**/__MACOSX/','**/._*','**/.editorconfig','**/.gitattributes','**/.gitignore','**/.gitkeep','**/.npmignore'];
    public static function normalize(array $input): array {
        if(array_diff(array_keys($input),['name','themes','plugins','media','muplugins','core','excludes','themesMode','themesItems','pluginsMode','pluginsItems','mediaSince']))throw new \InvalidArgumentException('Unknown profile field.');
        $name=$input['name']??'';
        if(!is_string($name)||trim($name)===''||strlen($name)>80)throw new \InvalidArgumentException('Profile name required (80 characters maximum).');
        $out=['name'=>trim($name)];
        foreach(['themes','plugins','media','muplugins','core'] as $key){if(isset($input[$key])&&!is_bool($input[$key]))throw new \InvalidArgumentException('Invalid resource switch.');$out[$key]=$input[$key]??false;}
        $patterns=$input['excludes']??[];
        if(!is_array($patterns)||!array_is_list($patterns)||count($patterns)>100)throw new \InvalidArgumentException('Invalid exclusions.');
        foreach($patterns as $p)Selection::excluded('validation/file.txt',[$p]);
        // Keep caller exclusions last so an explicit negation still has its documented meaning.
        $out['excludes']=array_values(array_unique([...self::DEFAULT_EXCLUDES,...$patterns]));
        // 0.4.0 resource modes. 'all' and absent keys are stored as absent so 0.3.14 request bindings
        // (the SHA-256 of the normalized profile) stay identical for older clients.
        foreach(['themes','plugins'] as $key){
            $mode=$input[$key.'Mode']??'all';$items=$input[$key.'Items']??[];
            if(!in_array($mode,['all','active','selected','except'],true))throw new \InvalidArgumentException('Unknown '.$key.' selection mode.');
            if(!is_array($items)||!array_is_list($items)||count($items)>500)throw new \InvalidArgumentException('Invalid '.$key.' selection.');
            foreach($items as $item){if(!is_string($item)||strlen($item)>200||str_contains($item,'/'))throw new \InvalidArgumentException('Invalid '.$key.' selection.');Selection::path($item);}
            if($mode==='all')continue;
            $items=in_array($mode,['selected','except'],true)?array_values(array_unique($items)):[];sort($items);
            $out[$key.'Mode']=$mode;$out[$key.'Items']=$items;
        }
        $since=$input['mediaSince']??null;
        if($since!==null){
            if(!is_string($since)||!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$since,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new \InvalidArgumentException('Media date must be YYYY-MM-DD.');
            $out['mediaSince']=$since;
        }
        return $out;
    }
    /** Upload files modified on/after this UTC midnight are exported; null exports every upload. */
    public static function mediaSince(array $profile): ?int {return isset($profile['mediaSince'])?(int)gmmktime(0,0,0,(int)substr($profile['mediaSince'],5,2),(int)substr($profile['mediaSince'],8,2),(int)substr($profile['mediaSince'],0,4)):null;}
}
