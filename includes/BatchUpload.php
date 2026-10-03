<?php
namespace ZoerConnect;
require_once __DIR__.'/StageStore.php';

/** A span-level refusal (digest_mismatch, gap, bounds, unverifiable, decode). Blocks
 * applied before it stay applied; the client resumes from the returned cursor. */
final class BatchRejected extends \InvalidArgumentException {public function __construct(public readonly string $reason){parent::__construct($reason);}}
/** The request body exceeds a PHP or batch limit: 413 zoer_import_body_limit. */
final class BatchBodyLimit extends \RuntimeException {}

/** Batched block upload framing. Octet-stream (and a multipart file part) carry
 * "ZBT1" | uint32 BE header length | JSON header | span bytes; JSON carries base64
 * spans. Span bytes are read lazily in <=256 KiB pieces (deflate input in 4 KiB
 * pieces, output bounded by rawLen), never buffering the body. Spans are consumed
 * strictly in order through next()/read()/end(). */
final class BatchUpload {
    public const MAGIC='ZBT1';
    public const MAX_HEADER=65536;
    public const MAX_SPANS=1024;
    public const JSON_BYTES=2097152;
    public const DEADLINE_MS=2000;
    private const HARD_MAX=67108864;
    private $in=null;
    private array $spans=[];
    private ?array $inline=null;
    private bool $deflate=false;
    private int $k=-1;
    private int $left=0;
    private $ctx=null;
    private string $buffer='';
    private int $produced=0;
    private function __construct(){}
    /** php.ini quantity ("8M", "1G", "0" = unlimited) in bytes. */
    public static function iniBytes($value): int {
        if(!is_string($value)||!preg_match('/^\s*(\d+)\s*([kmg]?)/i',$value,$m))return 0;
        return (int)$m[1]*[''=>1,'k'=>1024,'m'=>1048576,'g'=>1073741824][strtolower($m[2])];
    }
    public static function transports(): array {return ['octet-stream',...(filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN)?['multipart']:[]),'json'];}
    /** maxBatchBytes = min(post_max_size - 64 KiB, 8 MiB), for multipart also within
     * upload_max_filesize; the zoer_connect_max_batch_bytes filter may lower or raise
     * it within those PHP limits (never below one block). */
    public static function limits(string $transport='octet-stream'): array {
        $post=self::iniBytes(ini_get('post_max_size'));$ceiling=$post>0?$post-self::MAX_HEADER:self::HARD_MAX;
        if($transport==='multipart'&&($upload=self::iniBytes(ini_get('upload_max_filesize')))>0)$ceiling=min($ceiling,$upload-self::MAX_HEADER-8);
        $max=min($ceiling,8388608);
        if(function_exists('apply_filters'))$max=(int)apply_filters('zoer_connect_max_batch_bytes',$max,$transport);
        $max=max(StageStore::CHUNK,min($max,$ceiling,self::HARD_MAX));
        return ['blockBytes'=>StageStore::CHUNK,'maxBatchBytes'=>$max,'maxJsonBatchBytes'=>self::JSON_BYTES,'maxSpans'=>self::MAX_SPANS,'deadlineMs'=>self::DEADLINE_MS];
    }
    private function fill(int $n): string {
        $out='';
        while(strlen($out)<$n){$piece=fread($this->in,min(StageStore::CHUNK,$n-strlen($out)));if($piece===false||$piece==='')break;$out.=$piece;}
        return $out;
    }
    /** Parses and validates the frame header; $length is the declared body (or file
     * part) size, which must equal 8 + header + payloadBytes. */
    public static function framed($in,int $length,int $max): self {
        $b=new self();$b->in=$in;
        if($length>$max+8+self::MAX_HEADER)throw new BatchBodyLimit('Batch exceeds the maximum batch size.');
        $lead=$b->fill(8);
        if($lead===''&&$length>0)throw new BatchBodyLimit('The request body was discarded by a PHP size limit.');
        if(strlen($lead)!==8||substr($lead,0,4)!==self::MAGIC)throw new \InvalidArgumentException('Invalid batch frame.');
        $h=unpack('N',substr($lead,4))[1];
        if($h<2||$h>self::MAX_HEADER)throw new \InvalidArgumentException('Batch header exceeds 64 KiB.');
        $raw=$b->fill($h);
        try{$header=strlen($raw)===$h?json_decode($raw,true,8,JSON_THROW_ON_ERROR):null;}catch(\JsonException $e){$header=null;}
        if(!is_array($header)||array_is_list($header)||array_diff(array_keys($header),['v','spans','payloadBytes','enc'])||($header['v']??null)!==1||!is_int($header['payloadBytes']??null)||$header['payloadBytes']<1||!in_array($header['enc']??null,[null,'deflate'],true))throw new \InvalidArgumentException('Invalid batch header.');
        $b->deflate=($header['enc']??null)==='deflate';
        if($b->deflate&&(!function_exists('inflate_init')||!function_exists('inflate_add')))throw new \InvalidArgumentException('Deflate is unavailable on this server.');
        $b->spans=self::spans($header['spans']??null,$b->deflate?4:3);
        $wire=0;foreach($b->spans as $span)$wire+=$span[3];
        if($wire!==$header['payloadBytes'])throw new \InvalidArgumentException('Batch payloadBytes does not match its spans.');
        if($wire>$max)throw new BatchBodyLimit('Batch exceeds the maximum batch size.');
        if($length!==8+$h+$wire)throw new \InvalidArgumentException('Batch Content-Length mismatch.');
        return $b;
    }
    /** JSON transport: {"v":1,"spans":[[i,o,len,"<b64>"],...]} or, with "enc":"deflate",
     * [[i,o,rawLen,encLen,"<b64 of zlib bytes>"],...]; base64 is decoded per span. */
    public static function json(array $body): self {
        if(array_is_list($body)||array_diff(array_keys($body),['v','spans','enc'])||($body['v']??null)!==1||!is_array($body['spans']??null)||!in_array($body['enc']??null,[null,'deflate'],true))throw new \InvalidArgumentException('Invalid batch header.');
        $b=new self();$b->inline=[];$shape=[];$b->deflate=($body['enc']??null)==='deflate';$width=$b->deflate?4:3;
        if($b->deflate&&(!function_exists('inflate_init')||!function_exists('inflate_add')))throw new \InvalidArgumentException('Deflate is unavailable on this server.');
        foreach($body['spans'] as $span){if(!is_array($span)||!array_is_list($span)||count($span)!==$width+1||!is_string($span[$width]))throw new \InvalidArgumentException('Invalid batch span.');$b->inline[]=$span[$width];$shape[]=array_slice($span,0,$width);}
        $b->spans=self::spans($shape,$width);
        return $b;
    }
    /** Normalized [index, offset, rawLen, wireLen]. */
    private static function spans($spans,int $width): array {
        if(!is_array($spans)||!array_is_list($spans)||!$spans||count($spans)>self::MAX_SPANS)throw new \InvalidArgumentException('A batch carries 1 to 1024 spans.');
        $out=[];
        foreach($spans as $span){
            if(!is_array($span)||!array_is_list($span)||count($span)!==$width)throw new \InvalidArgumentException('Invalid batch span.');
            foreach($span as $n)if(!is_int($n)||$n<0||$n>2147483648)throw new \InvalidArgumentException('Invalid batch span.');
            if($span[2]<1||($width===4&&$span[3]<1))throw new \InvalidArgumentException('Invalid batch span.');
            $out[]=[$span[0],$span[1],$span[2],$span[3]??$span[2]];
        }
        return $out;
    }
    public function spanList(): array {return $this->spans;}
    /** Starts the next span. Unread bytes of a previous span must not remain. */
    public function next(): void {
        $this->k++;$span=$this->spans[$this->k]??throw new \LogicException('No further span.');
        $this->left=$span[3];$this->buffer='';$this->produced=0;$this->ctx=null;
        if($this->inline!==null){
            // A JSON span's wire bytes are served from memory through the framed paths.
            $data=base64_decode($this->inline[$this->k],true);$this->inline[$this->k]='';
            if($this->in)fclose($this->in);$this->in=fopen('php://memory','w+b');
            if($data===false||strlen($data)!==$span[3]||!$this->in||fwrite($this->in,$data)!==strlen($data)||!rewind($this->in))throw new BatchRejected('decode');
        }
        if($this->deflate){$this->ctx=inflate_init(ZLIB_ENCODING_DEFLATE);if(!$this->ctx)throw new BatchRejected('decode');}
    }
    /** Exactly $n raw bytes of the current span. */
    public function read(int $n): string {
        if(!$this->deflate){
            if($n>$this->left)throw new BatchRejected('decode');
            $data=$this->fill($n);$this->left-=strlen($data);if(strlen($data)!==$n)throw new BatchRejected('decode');return $data;
        }
        while(strlen($this->buffer)<$n)$this->inflate();
        $data=substr($this->buffer,0,$n);$this->buffer=(string)substr($this->buffer,$n);return $data;
    }
    private function inflate(): void {
        if(!$this->ctx||$this->left<1)throw new BatchRejected('decode');
        $piece=$this->fill(min(4096,$this->left));if($piece==='')throw new BatchRejected('decode');$this->left-=strlen($piece);
        $out=@inflate_add($this->ctx,$piece,ZLIB_SYNC_FLUSH);
        if(!is_string($out))throw new BatchRejected('decode');
        $this->produced+=strlen($out);if($this->produced>$this->spans[$this->k][2])throw new BatchRejected('decode');
        $this->buffer.=$out;
    }
    /** The current span was consumed exactly: every wire byte read and, for deflate,
     * the zlib stream ended there with exactly rawLen bytes of output. */
    public function end(): void {
        if($this->deflate){
            while($this->left>0)$this->inflate();
            if($this->buffer!==''||$this->produced!==$this->spans[$this->k][2]||inflate_get_status($this->ctx)!==ZLIB_STREAM_END||inflate_get_read_len($this->ctx)!==$this->spans[$this->k][3])throw new BatchRejected('decode');
        }elseif($this->left!==0||$this->buffer!=='')throw new BatchRejected('decode');
    }
}
