<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class Engine {
    const ACTIVE=['pending','processing','validating','paused'];
    public static function table(string $name):string{global $wpdb;return $wpdb->prefix.'wpr_static_'.$name;}
    public static function install():void{
        if(is_multisite())wp_die('WPR Static: single-site only.');
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();$b=self::table('builds');$t=self::table('tasks');$l=self::table('logs');
        dbDelta("CREATE TABLE $b (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 status varchar(24) NOT NULL,
 settings longtext NOT NULL,
 phase varchar(24) NOT NULL DEFAULT 'crawl',
 created datetime NOT NULL,
 updated datetime NOT NULL,
 published datetime NULL,
 publish_path text NULL,
 PRIMARY KEY  (id),
 KEY status (status)
 ) $c;");
        dbDelta("CREATE TABLE $t (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 build_id bigint unsigned NOT NULL,
 url text NOT NULL,
 url_key char(64) NOT NULL,
 kind varchar(20) NOT NULL,
 state varchar(20) NOT NULL DEFAULT 'pending',
 attempts smallint unsigned NOT NULL DEFAULT 0,
 path text NULL,
 path_key char(64) NULL,
 bytes bigint unsigned NOT NULL DEFAULT 0,
 sha256 char(64) NULL,
 http_status smallint unsigned NULL,
 indexable tinyint NOT NULL DEFAULT 0,
 front_ok tinyint NOT NULL DEFAULT 0,
 final_url text NULL,
 referrer text NULL,
 error text NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY build_url (build_id,url_key),
 UNIQUE KEY build_path (build_id,path_key),
 KEY queue (build_id,state,id)
 ) $c;");
        dbDelta("CREATE TABLE $l (
 id bigint unsigned NOT NULL AUTO_INCREMENT,
 build_id bigint unsigned NOT NULL,
 level varchar(12) NOT NULL,
 code varchar(64) NOT NULL,
 message text NOT NULL,
 url text NULL,
 created datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY build_level (build_id,level,id)
 ) $c;");
        update_option('wpr_static_db_version','2',false);if(!wp_next_scheduled('wpr_static_tick'))wp_schedule_event(time()+10,'wpr_static_minute','wpr_static_tick');
    }
    public static function upgrade():void{if(!is_multisite()&&get_option('wpr_static_db_version')!=='2')self::install();}
    public static function deactivate():void{try{Frontend::disable();}catch(\Throwable $e){error_log('WPR Static disable: '.$e->getMessage());}wp_clear_scheduled_hook('wpr_static_tick');global $wpdb;$wpdb->query("UPDATE ".self::table('builds')." SET status='paused' WHERE status IN ('pending','processing','validating')");}
    public static function settings():array{return wp_parse_args(get_option('wpr_static_settings',[]),['destination'=>rtrim(home_url('/'),'/'),'mode'=>'absolute','exclude'=>"/cart/*\n/checkout/*\n/my-account/*",'include'=>'','types'=>'','local'=>'','limit'=>15000,'max_mb'=>32,'retain'=>3]);}
    public static function lines(string $s):array{return array_values(array_filter(array_map('trim',preg_split('/\R/',$s))));}
    public static function clean(array $in):array{
        $s=self::settings();$s['destination']=rtrim(esc_url_raw(trim($in['destination']??'')),'/');$s['mode']=($in['mode']??'')==='relative'?'relative':'absolute';
        if($s['destination'] && (!URLs::origin($s['destination']) || parse_url($s['destination'],PHP_URL_QUERY)!==null || parse_url($s['destination'],PHP_URL_FRAGMENT)!==null))throw new \RuntimeException('INVALID_PUBLIC_URL');
        foreach(['exclude','include','types'] as $k)$s[$k]=sanitize_textarea_field($in[$k]??'');$s['local']=trim(sanitize_text_field($in['local']??''));$s['limit']=max(100,min(100000,absint($in['limit']??15000)));$s['max_mb']=max(1,min(64,absint($in['max_mb']??32)));$s['retain']=max(1,min(10,absint($in['retain']??3)));return $s;
    }
    public static function doctor(?array $s=null):array{
        $s=$s??self::settings();$r=[];$add=function($code,$level,$msg)use(&$r){$r[]=['code'=>$code,'level'=>$level,'message'=>$msg];};
        $add('PHP',version_compare(PHP_VERSION,'8.1','>=')?'ok':'error',PHP_VERSION.' (8.1+)');$add('DOM',class_exists('DOMDocument')?'ok':'error','PHP DOM/XML');$add('ZIP',class_exists('ZipArchive')?'ok':(!empty($s['frontend'])?'warning':'error'),'PHP ZipArchive');
        $add('SINGLE_SITE',is_multisite()?'error':'ok','WordPress single-site');
        try{$root=Storage::root();$probe=$root.'/.probe-'.bin2hex(random_bytes(8));Storage::write($probe,'test');unlink($probe);$add('STORAGE','ok','Private storage writable');$free=@disk_free_space($root);$add('DISK',$free===false?'unknown':($free<50*1024*1024?'error':'ok'),$free===false?'Disk space unknown':size_format($free).' free');}catch(\Throwable $e){$add('STORAGE','error',$e->getMessage());}
        $add('MEMORY','ok','memory_limit='.ini_get('memory_limit'));$add('TIME','ok','max_execution_time='.ini_get('max_execution_time'));
        $add('CRON',defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON?'warning':'ok','WP-Cron requires traffic or external scheduler; CLI/admin runner available.');
        $add('PERMALINKS',get_option('permalink_structure')?'ok':'error','Pretty permalinks required for static output.');
        $add('DESTINATION',!empty($s['destination'])?'ok':($s['mode']==='absolute'?'error':'warning'),'Public URL required for absolute SEO URLs.');
        $add('INDEXING',get_option('blog_public')?'ok':'warning','WordPress search engine visibility setting.');
        try{$u=URLs::normalize(home_url('/'));$res=self::fetch($u,['source'=>$u,'max_mb'=>2,'frontend'=>!empty($s['frontend'])]);$add('LOOPBACK',$res['status']===200 && str_contains(strtolower($res['type']),'html')?'ok':'error','HTTP '.$res['status'].' '.$res['type']);$body=$res['body'];if(preg_match('/<form\b/i',$body))$add('FORMS','warning','Forms may require server-side endpoints.');if(preg_match('~admin-ajax\.php|wp-json|rest_route~i',$body))$add('DYNAMIC','warning','Potential WordPress API/AJAX dependency.');}catch(\Throwable $e){$add('LOOPBACK','error',$e->getMessage());}
        if(class_exists('WooCommerce'))$add('WOOCOMMERCE','warning','Cart, checkout, account are not static.');return $r;
    }
    public static function build(int $id):array{global $wpdb;$r=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('builds').' WHERE id=%d',$id),ARRAY_A);if(!$r)throw new \RuntimeException('BUILD_NOT_FOUND');$r['config']=json_decode($r['settings'],true,512,JSON_THROW_ON_ERROR);return $r;}
    public static function latest():int{global $wpdb;return (int)$wpdb->get_var('SELECT MAX(id) FROM '.self::table('builds'));}
    public static function active():int{global $wpdb;return (int)$wpdb->get_var("SELECT id FROM ".self::table('builds')." WHERE status IN ('pending','processing','validating','paused') ORDER BY id DESC LIMIT 1");}
    public static function state(int $id,string $status,?string $phase=null):void{global $wpdb;$d=['status'=>$status,'updated'=>gmdate('Y-m-d H:i:s')];if($phase!==null)$d['phase']=$phase;if($wpdb->update(self::table('builds'),$d,['id'=>$id])===false)throw new \RuntimeException('BUILD_STATE_WRITE_FAILED');}
    public static function log(int $id,string $level,string $code,string $msg,string $url=''):void{global $wpdb;$url=preg_replace('/\?.*$/','',$url);$msg=preg_replace('~(https?://[^\s?]+)\?[^\s]+~','$1?[redacted]',$msg);$wpdb->insert(self::table('logs'),['build_id'=>$id,'level'=>$level,'code'=>substr($code,0,64),'message'=>substr($msg,0,4000),'url'=>$url,'created'=>gmdate('Y-m-d H:i:s')]);}
    public static function excluded(string $url,array $s):bool{if(!URLs::safe($url))return true;$p=rawurldecode(parse_url($url,PHP_URL_PATH)??'/');foreach(self::lines(str_replace('\\n',"\n",$s['exclude']??'')) as $mask){if(fnmatch($mask,$p)||rtrim($mask,'/')===rtrim($p,'/')||(str_ends_with($mask,'/*')&&rtrim(substr($mask,0,-2),'/')===rtrim($p,'/')))return true;}return false;}
    public static function enqueue(int $id,string $url,string $kind,array $s,string $ref=''):bool{
        global $wpdb;$t=self::table('tasks');$special=in_array($kind,['seed','verify','finalize'],true);
        if(!$special){$url=URLs::normalize($url);if(!$url||!URLs::internal($url,$s['source'])||self::excluded($url,$s))return false;if($kind==='html'){if(parse_url($url,PHP_URL_QUERY)!==null)return false;$pid=url_to_postid($url);if($pid){$post=get_post($pid);if(!$post||$post->post_status!=='publish'||$post->post_password!==''||in_array($post->post_type,self::lines($s['types']??''),true))return false;}}}
        $key=hash('sha256',$kind.'|'.$url);if($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE build_id=%d AND url_key=%s",$id,$key)))return true;
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE build_id=%d",$id))>=$s['limit'])throw new \RuntimeException('URL_LIMIT_REACHED');
        $path=null;if(in_array($kind,['html','asset','404'],true))$path=URLs::path($url,$kind,$s['source']);
        if($path!==null){if($kind!=='404'&&preg_match('~^(?:robots\.txt|sitemap(?:-\d+)?\.xml|404\.html)$~i',$path))throw new \RuntimeException('RESERVED_OUTPUT_PATH: '.$path);$existing=$wpdb->get_row($wpdb->prepare("SELECT url,kind FROM $t WHERE build_id=%d AND path_key=%s",$id,hash('sha256',$path)),ARRAY_A);if($existing){if($kind==='html'&&rtrim($existing['url'],'/')===rtrim($url,'/'))return true;throw new \RuntimeException('OUTPUT_PATH_COLLISION: '.$path);}}
        $ok=$wpdb->insert($t,['build_id'=>$id,'url'=>$url,'url_key'=>$key,'kind'=>$kind,'state'=>'pending','path'=>$path,'path_key'=>$path===null?null:hash('sha256',$path),'referrer'=>$ref]);if(!$ok)throw new \RuntimeException('QUEUE_INSERT_FAILED');return true;
    }
    public static function start(bool $frontend=false):int{
        $lock=Storage::lock();try{if(self::active())throw new \RuntimeException('BUILD_ALREADY_ACTIVE');$s=self::settings();$s['source']=URLs::normalize(home_url('/'));$s['destination']=rtrim($s['destination'],'/');if($frontend){$f=Frontend::get();if(empty($f['enabled']))throw new \RuntimeException('FRONTEND_DISABLED');$s['frontend']=true;$s['front_revision']=$f['revision'];$s['destination']=rtrim(home_url('/'),'/');$s['mode']='absolute';}foreach(self::doctor($s) as $check)if($check['level']==='error')throw new \RuntimeException($check['code'].': '.$check['message']);
            global $wpdb;$now=gmdate('Y-m-d H:i:s');if(!$wpdb->insert(self::table('builds'),['status'=>'pending','settings'=>wp_json_encode($s),'created'=>$now,'updated'=>$now]))throw new \RuntimeException('BUILD_INSERT_FAILED');$id=(int)$wpdb->insert_id;
            try{if(!mkdir(Storage::build($id).'/site',0700,true))throw new \RuntimeException('BUILD_DIRECTORY_FAILED');if(!self::enqueue($id,$s['source'],'html',$s))throw new \RuntimeException('HOME_EXCLUDED');self::enqueue($id,'posts:0','seed',$s);self::enqueue($id,'terms:0','seed',$s);self::enqueue($id,URLs::resolve('wp-sitemap.xml',$s['source']),'sitemap',$s);self::enqueue($id,URLs::resolve('sitemap_index.xml',$s['source']),'sitemap',$s);self::enqueue($id,URLs::resolve('robots.txt',$s['source']),'robots',$s);if(empty($s['frontend']))self::enqueue($id,URLs::resolve('wpr-static-404-'.bin2hex(random_bytes(6)).'/',$s['source']),'404',$s);foreach(self::lines($s['include']) as $u){$u=URLs::resolve($u,$s['source']);if(!self::enqueue($id,$u,URLs::asset($u)?'asset':'html',$s))self::log($id,'warning','MANUAL_URL_EXCLUDED','Manual URL excluded',$u);}foreach((array)apply_filters('wpr_static_seed_urls',[],$s) as $u){$u=URLs::resolve((string)$u,$s['source']);self::enqueue($id,$u,URLs::asset($u)?'asset':'html',$s);}}catch(\Throwable $e){self::state($id,'failed');throw $e;}
            return $id;
        }finally{Storage::unlock($lock);}
    }
    public static function fetch(string $url,array $s):array{
        $current=$url;for($i=0;$i<=5;$i++){
            if(!URLs::internal($current,$s['source'])||self::excluded($current,$s))throw new \RuntimeException('FETCH_URL_FORBIDDEN');
            $proof=bin2hex(random_bytes(16));$response=wp_remote_get($current,['timeout'=>10,'redirection'=>0,'sslverify'=>true,'cookies'=>[],'headers'=>['Accept'=>'*/*','Cache-Control'=>'no-cache','X-WPR-Static-Origin'=>$proof],'limit_response_size'=>((int)$s['max_mb']*1024*1024)+1,'user-agent'=>'WPR Static/'.WPR_STATIC_VERSION]);
            if(is_wp_error($response))throw new \RuntimeException('HTTP_ERROR: '.$response->get_error_code());$status=(int)wp_remote_retrieve_response_code($response);
            if(in_array($status,[301,302,303,307,308],true)){$next=URLs::resolve((string)wp_remote_retrieve_header($response,'location'),$current);if(!$next||$next===$current)throw new \RuntimeException('REDIRECT_INVALID');$current=$next;continue;}
            $body=wp_remote_retrieve_body($response);$type=(string)wp_remote_retrieve_header($response,'content-type');if(!empty($s['frontend'])&&stripos($type,'html')!==false&&!hash_equals($proof,(string)wp_remote_retrieve_header($response,'x-wpr-static-origin')))throw new \RuntimeException('ORIGIN_PROOF_FAILED: another cache may be serving stale HTML');if(strlen($body)>((int)$s['max_mb']*1024*1024))throw new \RuntimeException('RESOURCE_SIZE_LIMIT');return ['status'=>$status,'type'=>(string)wp_remote_retrieve_header($response,'content-type'),'body'=>$body,'url'=>$current,'robots'=>(string)wp_remote_retrieve_header($response,'x-robots-tag'),'cache'=>(string)(wp_remote_retrieve_header($response,'x-wpr-static-original-cache')?:wp_remote_retrieve_header($response,'cache-control')),'vary'=>(string)wp_remote_retrieve_header($response,'vary'),'cookies'=>!empty(wp_remote_retrieve_cookies($response))||!empty(wp_remote_retrieve_header($response,'set-cookie')),'csp'=>(string)wp_remote_retrieve_header($response,'content-security-policy')];
        }throw new \RuntimeException('REDIRECT_LIMIT');
    }
    private static function seed(int $id,array $task,array $s):void{
        [$what,$offset]=explode(':',$task['url']);$offset=(int)$offset;$size=100;
        if($what==='posts'){$types=array_values(get_post_types(['public'=>true],'names'));$types=array_diff($types,['attachment'],self::lines($s['types']));$posts=get_posts(['post_type'=>array_values($types),'post_status'=>'publish','has_password'=>false,'posts_per_page'=>$size,'offset'=>$offset,'orderby'=>'ID','order'=>'ASC','fields'=>'ids','suppress_filters'=>false]);foreach($posts as $pid){$url=get_permalink($pid);if(is_string($url))self::enqueue($id,$url,'html',$s);}if($offset===0)foreach($types as $type){$url=get_post_type_archive_link($type);if($url)self::enqueue($id,$url,'html',$s);}if(count($posts)===$size)self::enqueue($id,'posts:'.($offset+$size),'seed',$s);}
        else{$tax=array_values(get_taxonomies(['public'=>true],'names'));if(!$tax)return;$terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>true,'number'=>$size,'offset'=>$offset,'orderby'=>'term_id','order'=>'ASC']);if(is_wp_error($terms))throw new \RuntimeException('TERM_DISCOVERY_FAILED');foreach($terms as $term){$url=get_term_link($term);if(!is_wp_error($url))self::enqueue($id,$url,'html',$s);}if(count($terms)===$size)self::enqueue($id,'terms:'.($offset+$size),'seed',$s);}
    }
    private static function xml(string $body):\DOMDocument{$dom=new \DOMDocument();if(stripos($body,'<!DOCTYPE')!==false||!@$dom->loadXML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new \RuntimeException('INVALID_XML');return $dom;}
    private static function ref(int $id,string $value,string $base,string $current,array $s,string $kind,bool $seo=false):string{
        $u=URLs::resolve($value,$base);if(!$u)return $value;if(!empty($s['frontend'])&&$kind==='asset')return $value;if(!URLs::internal($u,$s['source'])){if($kind==='asset')self::log($id,'warning','EXTERNAL_RESOURCE','External resource retained',$u);return $value;}
        if(self::excluded($u,$s)||($kind==='html'&&parse_url($u,PHP_URL_QUERY)!==null)){self::log($id,'warning','EXCLUDED_REFERENCE','Internal reference not exported',$u);return $value;}
        if(preg_match('~/(?:robots\.txt|sitemap(?:[-_][^/]*)?\.xml|wp-sitemap[^/]*\.xml)$~i',parse_url($u,PHP_URL_PATH)??'')){return $value;}if(!self::enqueue($id,$u,$kind,$s,$base)){self::log($id,'warning','SKIPPED_REFERENCE','Reference not eligible for export',$u);return $value;}if($seo&&empty($s['destination'])){self::log($id,'warning','SEO_URL_UNCHANGED','Public URL not configured; absolute SEO URL retained',$u);return $u.(str_contains($value,'#')?'#'.explode('#',$value,2)[1]:'');}$p=URLs::path($u,$kind,$s['source']);$fragment=str_contains($value,'#')?'#'.explode('#',$value,2)[1]:'';return URLs::target($p,$current,$s,$seo).$fragment;
    }
    private static function css(int $id,string $css,string $base,string $current,array $s):string{
        $css=preg_replace_callback('~url\(\s*(?:"([^"\r\n]*)"|\'([^\'\r\n]*)\'|([^\)\s]+))\s*\)~i',function($m)use($id,$base,$current,$s){$v=($m[1]??'')!==''?$m[1]:(($m[2]??'')!==''?$m[2]:($m[3]??''));return 'url("'.str_replace('"','%22',self::ref($id,$v,$base,$current,$s,'asset')).'")';},$css);
        return preg_replace_callback('~(@import\s+)(["\'])([^"\']+)\2~i',fn($m)=>$m[1].$m[2].self::ref($id,$m[3],$base,$current,$s,'asset').$m[2],$css);
    }
    private static function html(int $id,string $body,string $base,string $current,array $s):array{
        $dom=new \DOMDocument();$old=libxml_use_internal_errors(true);try{if(!$dom->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new \RuntimeException('HTML_PARSE_FAILED');}finally{libxml_clear_errors();libxml_use_internal_errors($old);}foreach(iterator_to_array($dom->childNodes) as $n)if($n->nodeType===XML_PI_NODE)$dom->removeChild($n);
        $indexable=true;$canonical='';$xp=new \DOMXPath($dom);$bases=$xp->query('//base[@href]');if($bases->length){$b=URLs::resolve($bases->item(0)->getAttribute('href'),$base);if($b&&URLs::internal($b,$s['source'])){$base=$b;foreach(iterator_to_array($bases) as $n)$n->removeAttribute('href');}else self::log($id,'error','EXTERNAL_BASE','External HTML base cannot be safely rewritten',$base);}
        foreach($xp->query('//*') as $el){$tag=strtolower($el->tagName);$rel=strtolower($el->getAttribute('rel'));$name=strtolower($el->getAttribute('name'));$property=strtolower($el->getAttribute('property'));
            if($tag==='meta'&&$name==='robots'&&preg_match('/\b(?:noindex|none)\b/i',$el->getAttribute('content')))$indexable=false;
            if($tag==='link'&&preg_match('/\bcanonical\b/',$rel))$canonical=URLs::resolve($el->getAttribute('href'),$base);
            if($tag==='form')self::log($id,'warning','DYNAMIC_FORM','Form may require a backend',$base);
            foreach(['href','src','poster','data-src','data-lazy-src','data-original','xlink:href'] as $attr){if(!$el->hasAttribute($attr))continue;$v=$el->getAttribute($attr);$u=URLs::resolve($v,$base);$seo=$tag==='link'&&preg_match('/\b(canonical|alternate)\b/',$rel)&&($rel==='canonical'||$el->hasAttribute('hreflang'));
                if($tag==='link'&&!$seo&&!preg_match('/\b(stylesheet|icon|preload|modulepreload|apple-touch-icon|manifest)\b/',$rel)){if($u&&URLs::internal($u,$s['source']))self::log($id,'warning','SERVICE_LINK','Service link retained',$u);continue;}
                $kind=($seo||in_array($tag,['a','area','iframe'],true)&&$attr==='href'||$tag==='iframe'&&$attr==='src')&&!URLs::asset($u)?'html':'asset';
                if($tag==='link'&&$rel==='canonical'&&$u&&URLs::internal($u,$s['source'])&&rtrim($u,'/')!==rtrim($base,'/'))$indexable=false;
                $el->setAttribute($attr,self::ref($id,$v,$base,$current,$s,$kind,$seo));
            }
            foreach(['srcset','data-srcset','data-lazy-srcset'] as $attr)if($el->hasAttribute($attr)){$v=$el->getAttribute($attr);if(str_contains($v,'data:')){self::log($id,'warning','SRCSET_DATA','Mixed data URI srcset retained',$base);continue;}$parts=[];foreach(explode(',',$v) as $part){$a=preg_split('/\s+/',trim($part),2);$parts[]=self::ref($id,$a[0],$base,$current,$s,'asset').(isset($a[1])?' '.$a[1]:'');}$el->setAttribute($attr,implode(', ',$parts));}
            if($el->hasAttribute('style'))$el->setAttribute('style',self::css($id,$el->getAttribute('style'),$base,$current,$s));
            if($tag==='style'){$css=self::css($id,$el->textContent,$base,$current,$s);while($el->firstChild)$el->removeChild($el->firstChild);$el->appendChild($dom->createTextNode($css));}
            if($tag==='meta'&&preg_match('/^(og:(url|image(?::(?:url|secure_url))?|video(?::url)?|audio(?::url)?)|twitter:(url|image|image:src))$/',$property?:$name)){$v=$el->getAttribute('content');$el->setAttribute('content',self::ref($id,$v,$base,$current,$s,in_array($property?:$name,['og:url','twitter:url'],true)?'html':'asset',true));}
            if($tag==='script'&&strtolower($el->getAttribute('type'))==='application/ld+json'){$data=json_decode($el->textContent,true);if(json_last_error()!==JSON_ERROR_NONE){self::log($id,'warning','JSON_LD_INVALID','Invalid JSON-LD',$base);}else{$walk=function(&$v,$key='')use(&$walk,$id,$base,$current,$s){if(is_array($v)){foreach($v as $k=>&$item)$walk($item,is_string($k)?$k:$key);unset($item);}elseif(is_string($v)&&in_array($key,['url','@id','image','logo','contentUrl','thumbnailUrl','mainEntityOfPage','sameAs'],true)&&preg_match('~^https?://~i',$v)){$u=URLs::resolve($v,$base);if(URLs::internal($u,$s['source']))$v=self::ref($id,$v,$base,$current,$s,URLs::asset($u)?'asset':'html',true);}};$walk($data);while($el->firstChild)$el->removeChild($el->firstChild);$el->appendChild($dom->createTextNode(wp_json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG)));}}
        }
        if($canonical&&!URLs::internal($canonical,$s['source']))$indexable=false;
        if(preg_match('~admin-ajax\.php|wp-json|rest_route~i',$body))self::log($id,'warning','DYNAMIC_API','Potential runtime WordPress dependency',$base);
        $out=$dom->saveHTML();if(!$out||!$xp->query('//body')->length)throw new \RuntimeException('HTML_EMPTY');if(!empty($s['destination'])&&URLs::origin($s['destination'])!==URLs::origin($s['source'])&&str_contains($out,URLs::origin($s['source'])))self::log($id,'warning','ORIGIN_REFERENCE','Source origin still present in rendered HTML; inspect runtime and excluded links',$base);return [$out,$indexable];
    }
    private static function task(int $id,array $t,array $s):array{
        $kind=$t['kind'];if($kind==='seed'){self::seed($id,$t,$s);return [];}
        if($kind==='verify'){self::verifyOne($id,(int)$t['url']);return [];}
        $r=self::fetch($t['url'],$s);$code=$r['status'];$body=$r['body'];$type=strtolower($r['type']);$base=$r['url'];
        if($kind==='html'&&($pid=url_to_postid($base))){$post=get_post($pid);if(!$post||$post->post_status!=='publish'||$post->post_password!==''||in_array($post->post_type,self::lines($s['types']??''),true))throw new \RuntimeException('PRIVATE_TARGET');}
        if($base!==$t['url'])self::log($id,'info','REDIRECT','Redirect resolved to '.$base,$t['url']);
        if($kind==='sitemap'||$kind==='robots'){
            if($code!==200){self::log($id,'warning','DISCOVERY_OPTIONAL','Optional discovery source HTTP '.$code,$t['url']);return ['http_status'=>$code];}
            if($kind==='robots'){foreach(preg_split('/\R/',$body) as $line)if(preg_match('/^\s*Sitemap:\s*(\S+)/i',$line,$m))self::enqueue($id,$m[1],'sitemap',$s);Storage::write(Storage::build($id).'/robots-source.txt',$body);return ['http_status'=>$code];}
            try{$xml=self::xml($body);$root=$xml->documentElement->localName;if(!in_array($root,['sitemapindex','urlset'],true))throw new \RuntimeException('NOT_SITEMAP');foreach($xml->getElementsByTagName('loc') as $loc){$parent=$loc->parentNode->localName;if(($root==='urlset'&&$parent==='url')||($root==='sitemapindex'&&$parent==='sitemap'))self::enqueue($id,trim($loc->textContent),$root==='sitemapindex'?'sitemap':'html',$s);}}catch(\RuntimeException $e){if(in_array($e->getMessage(),['INVALID_XML','NOT_SITEMAP'],true))self::log($id,'warning','SITEMAP_INVALID','Optional sitemap is invalid',$base);else throw $e;}return ['http_status'=>$code];
        }
        if($kind==='404'&&$code!==404)throw new \RuntimeException('404_TEMPLATE_NOT_FOUND');if($kind!=='404'&&$code!==200)throw new \RuntimeException('HTTP_'.$code);
        if($r['robots']!=='')self::log($id,'warning','X_ROBOTS_TAG','Source HTTP X-Robots-Tag requires destination server configuration',$base);if($body==='')throw new \RuntimeException('EMPTY_RESPONSE');$indexable=false;
        if(in_array($kind,['html','404'],true)){if(!str_contains($type,'html'))throw new \RuntimeException('EXPECTED_HTML');$original=$body;[$body,$indexable]=self::html($id,$body,$base,$t['path'],$s);if(!empty($s['frontend']))$body=$original;if(($base!==$t['url']&&rtrim($base,'/')!==rtrim($t['url'],'/'))||preg_match('/\b(noindex|none)\b/i',$r['robots']))$indexable=false;}
        else{if(str_contains($type,'text/html')||str_contains($type,'php')||preg_match('/^\s*<\?php/i',$body)||!URLs::asset($t['url']))throw new \RuntimeException('UNSAFE_ASSET_RESPONSE');if(preg_match('/\.css$/i',parse_url($base,PHP_URL_PATH)??''))$body=self::css($id,$body,$base,$t['path'],$s);}
        $frontOk=!empty($s['frontend'])&&$kind==='html'&&$base===$t['url']&&Frontend::eligible($r,$body);if(!empty($s['frontend'])&&$kind==='html'&&!$frontOk)self::log($id,'warning','DYNAMIC_ROUTE_BYPASS','Route remains dynamic: redirect, form, private cache, headers or nonce',$base);$path=Storage::path(Storage::build($id).'/site',$t['path']);Storage::write($path,$body);return ['front_ok'=>$frontOk?1:0,'final_url'=>$base,'bytes'=>strlen($body),'sha256'=>hash('sha256',$body),'http_status'=>$code,'indexable'=>($kind==='html'&&$indexable)?1:0];
    }
    private static function verifyOne(int $id,int $task):void{global $wpdb;$t=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('tasks').' WHERE id=%d AND build_id=%d',$task,$id),ARRAY_A);if(!$t||$t['state']!=='done'||!$t['path'])throw new \RuntimeException('VERIFY_TASK_INVALID');$p=Storage::path(Storage::build($id).'/site',$t['path']);if(!is_file($p)||!hash_equals($t['sha256'],hash_file('sha256',$p)))throw new \RuntimeException('FILE_INTEGRITY_FAILED: '.$t['path']);}
    private static function finish(int $id,array $s):void{
        global $wpdb;$t=self::table('tasks');$l=self::table('logs');$dir=Storage::build($id);$root=$dir.'/site';
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE build_id=%d AND state='failed'",$id))|| (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $l WHERE build_id=%d AND level='error'",$id))){self::state($id,'failed');return;}
        if(!is_file($root.'/index.html')||(empty($s['frontend'])&&!is_file($root.'/404.html')))throw new \RuntimeException('REQUIRED_HTML_MISSING');
        $manifest=['schema_version'=>1,'build_id'=>$id,'plugin_version'=>WPR_STATIC_VERSION,'source_url'=>$s['source'],'destination_url'=>$s['destination'],'created_at'=>gmdate('c'),'status'=>'validated','files'=>[]];$last=0;$sitemap=[];$maps=[];$n=0;$seen=[];
        do{$rows=$wpdb->get_results($wpdb->prepare("SELECT id,url,kind,path,bytes,sha256,http_status,indexable,front_ok,final_url FROM $t WHERE build_id=%d AND state='done' AND path IS NOT NULL AND id>%d ORDER BY id LIMIT 250",$id,$last),ARRAY_A);foreach($rows as $r){$last=(int)$r['id'];$seen[$r['path']]=true;$manifest['files'][]=['path'=>$r['path'],'source_url'=>$r['url'],'type'=>$r['kind'],'front_ok'=>(bool)$r['front_ok'],'final_url'=>$r['final_url'],'http_status'=>(int)$r['http_status'],'size'=>(int)$r['bytes'],'sha256'=>$r['sha256']];if($r['indexable']&&$s['destination']){$sitemap[]=!empty($s['frontend'])?$r['url']:rtrim($s['destination'],'/').'/'.URLs::publicPath($r['path']);if(count($sitemap)>=10000){$maps[]=self::writeMap($root,$sitemap,++$n);$sitemap=[];}}}}while(count($rows)===250);
        if($s['destination']){if($maps){if($sitemap)$maps[]=self::writeMap($root,$sitemap,++$n);$xml='<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($maps as $m)$xml.='<sitemap><loc>'.self::xmlEscape(rtrim($s['destination'],'/').'/'.$m).'</loc></sitemap>';$xml.='</sitemapindex>';Storage::write($root.'/sitemap.xml',$xml);}else self::writeMap($root,$sitemap,0);}else self::log($id,'warning','SITEMAP_NO_DESTINATION','Sitemap omitted: public URL not configured.');
        $robots=is_file($dir.'/robots-source.txt')?file_get_contents($dir.'/robots-source.txt'):"User-agent: *\n";if(!get_option('blog_public'))$robots="User-agent: *\nDisallow: /\n";$robots=preg_replace('/^\s*Sitemap:.*$/mi','',$robots);if($s['destination'])$robots=rtrim($robots)."\nSitemap: ".rtrim($s['destination'],'/')."/sitemap.xml\n";Storage::write($root.'/robots.txt',$robots);
        foreach(array_merge(['robots.txt'],$s['destination']?['sitemap.xml']:[],$maps) as $p){if(isset($seen[$p]))throw new \RuntimeException('GENERATED_FILE_COLLISION');$seen[$p]=true;$manifest['files'][]=['path'=>$p,'type'=>'generated','size'=>filesize($root.'/'.$p),'sha256'=>hash_file('sha256',$root.'/'.$p)];}
        foreach(Storage::files($root) as $rel=>$p)if(!isset($seen[$rel]))throw new \RuntimeException('UNTRACKED_OUTPUT: '.$rel);
        Storage::write($dir.'/manifest.json',wp_json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));self::state($id,'ready','done');self::log($id,'info','BUILD_READY','Validated static output is ready.');Frontend::onReady($id,$s);if(!empty($s['frontend']))try{self::pruneFrontend($id,(int)$s['retain']);}catch(\Throwable $e){self::log($id,'warning','FRONTEND_RETENTION_FAILED',$e->getMessage());}
    }
    private static function xmlEscape(string $s):string{return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');}
    private static function writeMap(string $root,array $urls,int $n):string{$p=$n?'sitemap-'.$n.'.xml':'sitemap.xml';$xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $u)$xml.='<url><loc>'.self::xmlEscape($u).'</loc></url>';Storage::write($root.'/'.$p,$xml.'</urlset>');return $p;}
    public static function tick(int $id,int $budget=12):void{
        $lock=Storage::lock();try{global $wpdb;$table=self::table('tasks');$b=self::build($id);if(!in_array($b['status'],['pending','processing','validating'],true))return;$s=$b['config'];$start=microtime(true);$phase=$b['phase'];self::state($id,$phase==='verify'?'validating':'processing');
            // The process lock is released automatically if PHP dies. Reset any interrupted tasks.
            $wpdb->query($wpdb->prepare("UPDATE $table SET state='pending' WHERE build_id=%d AND state='running'",$id));
            do{$task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE build_id=%d AND state='pending' ORDER BY id LIMIT 1",$id),ARRAY_A);
                if(!$task){if($phase==='crawl'){$last=0;do{$rows=$wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE build_id=%d AND path IS NOT NULL AND state='done' AND id>%d ORDER BY id LIMIT 250",$id,$last));foreach($rows as $tid){$last=(int)$tid;self::enqueue($id,(string)$tid,'verify',array_replace($s,['limit'=>$s['limit']*3]));}}while(count($rows)===250);self::state($id,'validating','verify');$phase='verify';continue;}self::finish($id,$s);return;}
                $attempt=(int)$task['attempts']+1;$wpdb->update($table,['state'=>'running','attempts'=>$attempt],['id'=>$task['id']]);
                try{$fields=self::task($id,$task,$s);$fields['state']='done';$fields['error']=null;$wpdb->update($table,$fields,['id'=>$task['id']]);}catch(\Throwable $e){$msg=$e->getMessage();$retry=$attempt<3&&(str_starts_with($msg,'HTTP_ERROR')||preg_match('/^HTTP_(429|5\d\d)$/',$msg));$optional=(in_array($task['kind'],['sitemap','robots'],true)&&!in_array($msg,['URL_LIMIT_REACHED','QUEUE_INSERT_FAILED'],true)&&!str_starts_with($msg,'OUTPUT_PATH_COLLISION'))||(!empty($s['frontend'])&&$task['kind']==='html'&&$task['path']!=='index.html'&&in_array($msg,['HTTP_404','HTTP_410','EXPECTED_HTML','PRIVATE_TARGET','FETCH_URL_FORBIDDEN'],true));$wpdb->update($table,['state'=>$retry?'pending':($optional?'skipped':'failed'),'error'=>substr($msg,0,1000)],['id'=>$task['id']]);self::log($id,$retry||$optional?'warning':'error',preg_match('/^[A-Z][A-Z0-9_]+/',$msg,$m)?$m[0]:'TASK_FAILED',$msg,$task['url']);if($retry)break;}
                self::state($id,$phase==='verify'?'validating':'processing');
            }while(microtime(true)-$start<$budget && self::memoryAvailable());
        }catch(\Throwable $e){self::state($id,'failed');self::log($id,'error','BUILD_FAILED',$e->getMessage());}finally{Storage::unlock($lock);}
    }
    private static function memoryAvailable():bool{$v=trim(ini_get('memory_limit'));if($v==='-1')return true;$n=(int)$v;$u=strtolower(substr($v,-1));$n*=match($u){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};return memory_get_usage(true)<$n*0.75;}
    public static function cron():void{try{$id=self::active();if($id)self::tick($id);else Frontend::drive();}catch(\Throwable $e){/* next scheduled tick retries lock/storage */}}
    public static function control(int $id,string $action):void{$lock=Storage::lock();try{$b=self::build($id);$status=$b['status'];if($action==='pause'&&in_array($status,['pending','processing','validating'],true))self::state($id,'paused');elseif($action==='resume'&&$status==='paused')self::state($id,$b['phase']==='verify'?'validating':'processing');elseif($action==='cancel'&&in_array($status,self::ACTIVE,true))self::state($id,'cancelled');else throw new \RuntimeException('INVALID_STATE_TRANSITION');}finally{Storage::unlock($lock);}}
    public static function stats(int $id):array{global $wpdb;$t=self::table('tasks');$l=self::table('logs');$b=self::build($id);unset($b['settings'],$b['config']);$rows=$wpdb->get_results($wpdb->prepare("SELECT kind,state,COUNT(*) AS total,COALESCE(SUM(bytes),0) AS bytes FROM $t WHERE build_id=%d GROUP BY kind,state",$id),ARRAY_A);$b['counts']=$rows;$b['issues']=$wpdb->get_results($wpdb->prepare("SELECT level,COUNT(*) AS total FROM $l WHERE build_id=%d GROUP BY level",$id),ARRAY_A);return $b;}
    public static function validate(int $id):array{
        $b=self::build($id);if($b['status']!=='ready')throw new \RuntimeException('BUILD_NOT_READY');$dir=Storage::build($id);$m=json_decode(file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$known=[];foreach($m['files'] as $f){$p=Storage::path($dir.'/site',$f['path']);if(!is_file($p)||!hash_equals($f['sha256'],hash_file('sha256',$p)))throw new \RuntimeException('FILE_INTEGRITY_FAILED: '.$f['path']);$known[$f['path']]=true;}foreach(Storage::files($dir.'/site') as $rel=>$p)if(!isset($known[$rel]))throw new \RuntimeException('UNTRACKED_OUTPUT: '.$rel);return $m;
    }
    public static function export(int $id):string{$lock=Storage::lock();try{if(!empty(self::build($id)['config']['frontend']))throw new \RuntimeException('LIVE_BUILD_NOT_PORTABLE: generate an export build first');self::validate($id);return Storage::zip($id);}finally{Storage::unlock($lock);}}
    public static function publish(int $id,?string $dest=null):void{$lock=Storage::lock();try{$b=self::build($id);if(!empty($b['config']['frontend']))throw new \RuntimeException('LIVE_BUILD_NOT_PORTABLE');self::validate($id);$dest=$dest??$b['config']['local'];if(!$dest)throw new \RuntimeException('DESTINATION_REQUIRED');if(!$b['config']['destination'])throw new \RuntimeException('PUBLIC_URL_REQUIRED');if(get_option('wpr_static_publish_journal'))throw new \RuntimeException('PUBLISH_RECOVERY_REQUIRED');Storage::publish($id,$dest);global $wpdb;$wpdb->update(self::table('builds'),['published'=>gmdate('Y-m-d H:i:s'),'publish_path'=>$dest],['id'=>$id]);self::log($id,'info','PUBLISHED','Static output published.');}finally{Storage::unlock($lock);}}
    // Called only while the worker lock is held. Portable export builds are never auto-deleted.
    private static function pruneFrontend(int $current,int $retain):void {
        global $wpdb;$rows=$wpdb->get_results("SELECT id,status,settings FROM ".self::table('builds')." WHERE status NOT IN ('pending','processing','validating','paused') ORDER BY id DESC",ARRAY_A);$n=0;
        foreach($rows as $r){$s=json_decode($r['settings'],true);if(empty($s['frontend']))continue;$id=(int)$r['id'];$n++;if($n<=max(1,$retain)||$id===$current||(int)(Frontend::get()['active_id']??0)===$id)continue;
            Storage::remove(Storage::build($id));foreach(['tasks','logs'] as $t)$wpdb->delete(self::table($t),['build_id'=>$id]);$wpdb->delete(self::table('builds'),['id'=>$id]);}
    }
    public static function history():array{global $wpdb;return $wpdb->get_results('SELECT id,status,created,updated,published FROM '.self::table('builds').' ORDER BY id DESC LIMIT 100',ARRAY_A);}
    public static function logs(int $id,int $limit=200):array{global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT created,level,code,message,url FROM '.self::table('logs').' WHERE build_id=%d ORDER BY id DESC LIMIT %d',$id,$limit),ARRAY_A);}
    public static function delete(int $id):void{$lock=Storage::lock();try{$b=self::build($id);if((int)(Frontend::get()['active_id']??0)===$id)throw new \RuntimeException('FRONTEND_BUILD_ACTIVE');if(in_array($b['status'],self::ACTIVE,true))throw new \RuntimeException('BUILD_ACTIVE');Storage::remove(Storage::build($id));global $wpdb;foreach(['tasks','logs'] as $t)$wpdb->delete(self::table($t),['build_id'=>$id]);$wpdb->delete(self::table('builds'),['id'=>$id]);}finally{Storage::unlock($lock);}}
}
