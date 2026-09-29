<?php
require __DIR__.'/../includes/Replacement.php';
use ZoerConnect\Replacement;
function expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function reject($fn,$label){try{$fn();}catch(Throwable $e){echo "PASS $label\n";return;}throw new RuntimeException($label);}
$literal=[['mode'=>'literal','find'=>'https://old.example','replace'=>'https://new.example']];
// The value limit applies only once a rule can match; unrelated large values pass untouched.
$big=str_repeat('x',2*1048576);
expect(Replacement::apply($big,$literal)===$big,'unrelated 2 MiB value preserved without limit error');
expect(Replacement::apply($big.'https://old.example',$literal,$n)===$big.'https://new.example'&&$n===1,'matching 2 MiB value replaced under the 16 MiB limit');
$huge=str_repeat('y',Replacement::MAX_VALUE+1);
expect(Replacement::apply($huge,$literal)===$huge,'unrelated >16 MiB value preserved');
reject(fn()=>Replacement::apply($huge.'https://old.example',$literal),'matching >16 MiB value refused');
unset($big,$huge);
// Case-insensitive literal mode and counting, including inside serialized arrays.
$ci=[['mode'=>'literal','find'=>'Old Brand','replace'=>'New Brand','caseSensitive'=>false]];
expect(Replacement::apply('old brand, OLD BRAND, Old Brand',$ci,$n)==='New Brand, New Brand, New Brand'&&$n===3,'case-insensitive literal replacement counted');
expect(Replacement::apply('OLD BRAND',[['mode'=>'literal','find'=>'Old Brand','replace'=>'x']],$n)==='OLD BRAND'&&$n===0,'case-sensitive literal default unchanged');
$value=serialize(['a'=>'https://old.example/1','b'=>['https://old.example/2 https://old.example/3']]);
$out=unserialize(Replacement::apply($value,$literal,$n));
expect($n===3&&$out['b'][0]==='https://new.example/2 https://new.example/3','serialized replacements counted and lengths rewritten');
// Regex rules only walk objects they can actually match.
$object='O:8:"stdClass":1:{s:1:"u";s:5:"plain";}';
expect(Replacement::apply($object,[['mode'=>'regex','find'=>'/old\.example/','replace'=>'x']])===$object,'unmatched regex leaves serialized object untouched');
reject(fn()=>Replacement::apply($object,[['mode'=>'regex','find'=>'/plain/','replace'=>'x']]),'matching regex on serialized object refused');
expect(unserialize(Replacement::apply(serialize(['k'=>'exact']),[['mode'=>'regex','find'=>'/^exact$/','replace'=>'done']]))['k']==='done','anchored regex reaches inner serialized string');
// Automatic rules: longest first, one pass (no re-replacement when the target contains the source).
$rules=Replacement::rules(['https://old.example','https://old.example/blog/'],'https://old.example.org',['automatic'=>true]);
expect(array_column($rules,'find')===['https://old.example/blog','https://old.example'],'automatic rules ordered longest first and untrailingslashed');
expect(Replacement::apply('https://old.example/blog/x https://old.example/y',$rules,$n)==='https://old.example.org/x https://old.example.org/y'&&$n===2,'most specific source URL wins');
$rules=Replacement::rules(['https://old.example'],'https://old.example.org/site',['automatic'=>true,'variants'=>true]);
$groups=array_column($rules,'group');
expect($groups[0]==='automatic'&&!in_array('custom',$groups,true)&&count(array_unique(array_column($rules,'find')))===count($rules),'variant rules deduplicated after automatic rules');
$in='https://old.example/a http://old.example/b https:\/\/old.example\/c http:\/\/old.example\/d https%3A%2F%2Fold.example%2Fe //old.example/f';
$want='https://old.example.org/site/a https://old.example.org/site/b https:\/\/old.example.org\/site\/c https:\/\/old.example.org\/site\/d https%3A%2F%2Fold.example.org%2Fsite%2Fe //old.example.org/site/f';
expect(Replacement::apply($in,$rules,$n)===$want&&$n===6,'http/https, JSON-escaped, rawurlencoded and protocol-relative variants in one pass');
expect(Replacement::apply('https://old.example',Replacement::rules(['https://old.example'],'https://new.example',['automatic'=>false,'variants'=>true]))==='https://old.example','automatic=false disables URL rules and their variants');
$same=Replacement::rules(['http://site.example'],'https://site.example',['variants'=>true]);
expect(!in_array('https://site.example',array_column($same,'find'),true)&&!in_array('//site.example',array_column($same,'find'),true),'scheme-only change produces no identity or self-matching rules');
expect(Replacement::apply('http://site.example/x //site.example/y',$same)==='https://site.example/x //site.example/y','scheme upgrade keeps protocol-relative URLs');
// Paths, then custom rows (sequential, user order).
$custom=Replacement::custom([['find'=>'Acme','replace'=>'Zenith'],['find'=>'/colou?r/','replace'=>'hue','regex'=>true,'caseSensitive'=>false],['find'=>'zenith','replace'=>'Z','caseSensitive'=>false]]);
$rules=Replacement::rules(['https://old.example'],'https://new.example',['paths'=>true,'custom'=>$custom],'/var/www/source/','/srv/destination');
expect(array_column($rules,'group')===['automatic','path','path','custom','custom','custom'],'paths follow URLs; custom rows last');
expect(Replacement::apply('/var/www/source/wp-content {"p":"\/var\/www\/source\/x"} Acme COLOR',$rules)==='/srv/destination/wp-content {"p":"\/srv\/destination\/x"} Z hue','path, JSON path, chained custom literal and case-insensitive regex rows');
expect(Replacement::rules([],'https://new.example',['paths'=>true],null,'/srv/destination')===[],'paths without a source path produce no rule');
reject(fn()=>Replacement::custom(array_fill(0,51,['find'=>'a','replace'=>'b'])),'more than 50 custom rows refused');
reject(fn()=>Replacement::custom([['find'=>'','replace'=>'b']]),'empty custom find refused');
reject(fn()=>Replacement::custom([['find'=>'/(/','replace'=>'b','regex'=>true]]),'invalid custom regex refused');
reject(fn()=>Replacement::custom([['find'=>'a','replace'=>'b','sql'=>'DROP']]),'unknown custom row key refused');
reject(fn()=>Replacement::custom([['find'=>'a','replace'=>'b','regex'=>'yes']]),'non-boolean custom flag refused');
reject(fn()=>Replacement::apply('x',[['mode'=>'regex','find'=>'/x/','replace'=>'y','atomic'=>true]]),'atomic regex rule refused');
// 0.3.14 parity: without client options the rules stay sequential, in order and undeduplicated.
$v='<img src="https://old.example/wp/wp-content/uploads/a.jpg"> <a href="https://old.example/about">';
expect(Replacement::apply($v,Replacement::legacy(['https://old.example','https://old.example','https://old.example/wp'],'https://new.example'))==='<img src="https://new.example/wp/wp-content/uploads/a.jpg"> <a href="https://new.example/about">','legacy rules keep the 0.3.14 home-first output');
expect(Replacement::apply('https://old.example/x',Replacement::legacy(['https://old.example/','https://old.example'],'https://old.example.org'))==='https://old.example.org.org/x','legacy rules re-match sequentially exactly like 0.3.14');
// Mapped sources: each source URL (and its variants) goes to its own destination in one longest-first pass.
$rules=Replacement::rules(['https://old.example'=>'https://new.example','https://old.example/wp'=>'https://new.example/core'],'https://new.example',['variants'=>true]);
expect(Replacement::apply('https://old.example/wp/wp-content/a.jpg https://old.example/about http://old.example/wp/x https:\/\/old.example\/wp\/y //old.example/wp/z',$rules)==='https://new.example/core/wp-content/a.jpg https://new.example/about https://new.example/core/x https:\/\/new.example\/core\/y //new.example/core/z','mapped source URLs and their variants use their own destination');
// Source paths need two segments and must be followed by a path boundary.
$rules=Replacement::rules(['https://old.example'],'https://new.example',['paths'=>true],'/app','/var/www/html');
expect(array_column($rules,'group')===['automatic']&&Replacement::apply('https://old.example/apple-pie /application /app/wp-content',$rules)==='https://new.example/apple-pie /application /app/wp-content','one-segment source path omitted instead of corrupting URLs');
$rules=Replacement::rules([],'https://new.example',['paths'=>true],'/var/www','/srv/site');
expect(Replacement::apply('/var/www/x "/var/www" /var/www-old/y /var/wwwroot \/var\/www\/z \/var\/wwwroot (/var/www) </var/www< \'/var/www\' /var/www',$rules,$n)==='/srv/site/x "/srv/site" /var/www-old/y /var/wwwroot \/srv\/site\/z \/var\/wwwroot (/srv/site) </srv/site< \'/srv/site\' /srv/site'&&$n===7,'path rules stop at a boundary, plain and JSON-escaped');
// caseSensitive:false regex rows are validated as they will run (leading whitespace before the delimiter is legal PCRE).
$custom=Replacement::custom([['find'=>' /foo/','replace'=>'bar','regex'=>true,'caseSensitive'=>false]]);
expect(Replacement::apply('FOO',Replacement::rules([],'https://new.example',['automatic'=>false,'custom'=>$custom]))==='bar','case-insensitive regex row validated and applied in its transformed form');
