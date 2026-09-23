<?php
namespace ZoerConnect;
final class ExportProfile {
    public static function normalize(array $input): array {
        if(array_diff(array_keys($input),['name','themes','plugins','media','muplugins','core','excludes']))throw new \InvalidArgumentException('Unknown profile field.');
        $name=$input['name']??'';
        if(!is_string($name)||trim($name)===''||strlen($name)>80)throw new \InvalidArgumentException('Profile name required (80 characters maximum).');
        $out=['name'=>trim($name)];
        foreach(['themes','plugins','media','muplugins','core'] as $key){if(isset($input[$key])&&!is_bool($input[$key]))throw new \InvalidArgumentException('Invalid resource switch.');$out[$key]=$input[$key]??false;}
        $patterns=$input['excludes']??[];
        if(!is_array($patterns)||!array_is_list($patterns)||count($patterns)>100)throw new \InvalidArgumentException('Invalid exclusions.');
        foreach($patterns as $p)Selection::excluded('validation/file.txt',[$p]);
        // Finder metadata and repository control files are not WordPress site
        // content, and Zoer cannot import these paths. Keep caller exclusions
        // last so an explicit negation still has its documented meaning.
        $out['excludes']=array_values(array_unique([
            '**/.DS_Store','**/__MACOSX/','**/._*',
            '**/.editorconfig','**/.gitattributes','**/.gitignore','**/.gitkeep','**/.npmignore',
            ...$patterns,
        ]));return $out;
    }
}
