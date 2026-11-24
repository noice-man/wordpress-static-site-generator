<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class Frontend {
    const MARKER='WPR STATIC FRONTEND';
    public static function get():array {try{return Serve::state(Storage::root());}catch(\Throwable $e){return [];}}
    private static function change(callable $fn):array {
        $root=Storage::root();$f=fopen($root.'/frontend.lock','c');if(!$f||!flock($f,LOCK_EX))throw new \RuntimeException('FRONTEND_LOCK_FAILED');
        try{$s=Serve::state($root);$s=$fn($s);if(empty($s['enabled'])||empty($s['active_id']))self::killPrivate($root);Storage::write($root.'/frontend.json',wp_json_encode($s,JSON_UNESCAPED_SLASHES));if(!empty($s['enabled'])&&!empty($s['active_id']))Storage::write($root.'/frontend-enabled',(string)$s['active_id']);return $s;}finally{flock($f,LOCK_UN);fclose($f);}
    }
    private static function killPrivate(?string $root=null):void {$p=($root??Storage::root()).'/frontend-enabled';if(is_file($p)&&!@unlink($p))throw new \RuntimeException('FRONTEND_DISABLE_FAILED');}
    public static function boot():void {
        // Bypass all cooperating caches on crawler requests; proof echoed by the live WP response.
        if(isset($_SERVER['HTTP_X_WPR_STATIC_ORIGIN'])&&preg_match('/^[a-f0-9]{32}$/D',$_SERVER['HTTP_X_WPR_STATIC_ORIGIN'])){
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            add_action('send_headers',static function(){header('X-WPR-Static-Origin: '.$_SERVER['HTTP_X_WPR_STATIC_ORIGIN']);$cache=[];foreach(headers_list() as $header)if(stripos($header,'Cache-Control:')===0)$cache[]=trim(substr($header,14));header('X-WPR-Static-Original-Cache: '.($cache?implode(', ',$cache):'public'));header('Cache-Control: no-store, private, max-age=0');},PHP_INT_MAX);
        }
        // Fallback when the server does not permit an early drop-in. Do not interfere with another page cache.
        add_action('template_redirect',static function(){if(is_user_logged_in()||is_preview()||is_search()||is_404()||is_feed()||is_trackback()||is_customize_preview()||is_admin())return;try{self::assertCacheCompatible();Serve::run(Storage::root());}catch(\Throwable $e){}},-9999);
        // Invalidate globally before public content may become private, including copies on archives.
        add_filter('wp_insert_post_data',static function($data,$postarr){if(($data['post_type']??'')!=='revision'&&!in_array($data['post_status']??'',['auto-draft','inherit'],true))self::dirty();return $data;},1,2);
        add_action('before_delete_post',[self::class,'dirty'],1);add_action('wp_trash_post',[self::class,'dirty'],1);
        foreach(['transition_post_status','deleted_post','created_term','edited_term','delete_term','set_object_terms','wp_update_nav_menu','switch_theme','customize_save_after','upgrader_process_complete','activated_plugin','deactivated_plugin','wp_set_comment_status','comment_post','edit_comment','deleted_comment'] as $hook)add_action($hook,[self::class,'dirty'],99);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta','added_term_meta','updated_term_meta','deleted_term_meta'] as $hook)add_action($hook,[self::class,'metaDirty'],99,3);
        add_action('updated_option',static function($name){if(in_array($name,['blogname','blogdescription','blog_public','permalink_structure','home','siteurl','show_on_front','page_on_front','page_for_posts','sidebars_widgets','active_plugins','stylesheet','template','wpr_static_settings'],true)||str_starts_with($name,'theme_mods_')||str_starts_with($name,'widget_'))self::dirty();},99,1);
    }
    public static function metaDirty($meta,$object,$key):void {if(in_array($key,['_edit_lock','_edit_last','_wp_old_slug','_wp_old_date'],true))return;self::dirty();}
    public static function dirty(...$unused):void {
        // Ignore routine writes unless the feature was explicitly enabled.
        if(empty(self::get()['enabled'])){try{Direct::kill();}catch(\Throwable $e){}return;}
        try{self::change(function($s){if(empty($s['enabled']))return $s;self::killPrivate();self::killDirect($s);$s['revision']=bin2hex(random_bytes(16));$s['due']=time()+60;$s['active_id']=0;$s['delivery']='wordpress';$s['error']='';return $s;});}catch(\Throwable $e){error_log('WPR Static invalidation: '.$e->getMessage());}
    }
    public static function enable():int {
        if(class_exists('WooCommerce'))throw new \RuntimeException('FRONTEND_WOOCOMMERCE_UNSUPPORTED');
        self::assertCacheCompatible();
        self::change(function($s){$s['enabled']=true;$s['auto']=$s['auto']??true;$s['revision']=bin2hex(random_bytes(16));$s['due']=time();$s['manual']=true;$s['error']='';return $s;});
        return self::drive();
    }
    public static function rebuild():int {
        if(empty(self::get()['enabled']))return self::enable();
        self::change(function($s){$s['revision']=bin2hex(random_bytes(16));$s['due']=time();$s['manual']=true;$s['error']='';return $s;});return self::drive();
    }
    public static function setAuto(bool $auto):void {self::change(function($s)use($auto){$s['auto']=$auto;return $s;});}
    public static function disable():void {
        try{self::change(function($s){self::killPrivate();self::killDirect($s);$s['enabled']=false;$s['active_id']=0;$s['due']=0;$s['delivery']='wordpress';$s['revision']=bin2hex(random_bytes(16));return $s;});}finally{self::removeDropin();self::removeRules();}
    }
    public static function drive():int {
        $s=self::get();$active=Engine::active();if($active)return $active;
        if(empty($s['enabled'])||empty($s['due'])||$s['due']>time()||($s['attempted']??'')===($s['revision']??'')||empty($s['auto'])&&empty($s['manual']))return 0;
        $claimed=false;$s=self::change(function($s)use(&$claimed){if(!empty($s['enabled'])&&!empty($s['due'])&&$s['due']<=time()&&($s['attempted']??'')!==($s['revision']??'')&&(!empty($s['auto'])||!empty($s['manual']))){$s['attempted']=$s['revision'];$s['manual']=false;$claimed=true;}return $s;});
        if(!$claimed)return 0;
        try{self::assertCacheCompatible();return Engine::start(true);}catch(\Throwable $e){self::change(function($n)use($s,$e){if(($n['revision']??'')===($s['revision']??'')){$n['error']=$e->getMessage();if($e->getMessage()==='WORKER_BUSY')unset($n['attempted']);}return $n;});throw $e;}
    }
    public static function assertCacheCompatible():void {
        $drop=WP_CONTENT_DIR.'/advanced-cache.php';if(file_exists($drop)&&!self::ownedDropin($drop))throw new \RuntimeException('FRONTEND_OTHER_PAGE_CACHE: disable the existing page cache first');
        if(class_exists('WooCommerce'))throw new \RuntimeException('FRONTEND_WOOCOMMERCE_UNSUPPORTED');
    }
    private static function ownedDropin(string $p):bool{return !is_link($p)&&is_file($p)&&str_contains((string)@file_get_contents($p,false,null,0,512),'WPR STATIC OWNED DROPIN V1');}
    private static function wpConfig():?string {foreach([ABSPATH.'wp-config.php',dirname(rtrim(ABSPATH,'/')).'/wp-config.php'] as $p)if(is_file($p)&&!is_link($p))return $p;return null;}
    private static function replaceFile(string $p,string $old,string $new):void {
        if(is_link($p)||!is_file($p)||file_get_contents($p)!==$old)throw new \RuntimeException('CONFIG_CHANGED');$mode=fileperms($p)&0777;$tmp=dirname($p).'/.wpr-config-'.bin2hex(random_bytes(12)).'.php';$fp=@fopen($tmp,'x');if(!$fp)throw new \RuntimeException('CONFIG_WRITE_FAILED');chmod($tmp,0600);fclose($fp);try{if(file_put_contents($tmp,$new)!==strlen($new)||!chmod($tmp,$mode)||file_get_contents($p)!==$old||!rename($tmp,$p))throw new \RuntimeException('CONFIG_WRITE_FAILED');}finally{if(is_file($tmp))unlink($tmp);}
    }
    private static function installDropin():string {
        self::assertCacheCompatible();$drop=WP_CONTENT_DIR.'/advanced-cache.php';$config=self::wpConfig();
        if(!defined('WP_CACHE')||!WP_CACHE){if(!$config||!is_writable($config)||!is_writable(dirname($config)))return 'wordpress-php';$old=file_get_contents($config);if(stripos($old,'WP_CACHE')!==false)return 'wordpress-php';
            // Only standard, literal wp-settings require; never guess around a custom bootstrap.
            $pattern='~^([ \t]*require_once\s+ABSPATH\s*\.\s*[\x27\x22]wp-settings\.php[\x27\x22]\s*;)[ \t]*$~m';$new=preg_replace($pattern,"// BEGIN WPR STATIC CACHE\nif (!defined('WP_CACHE')) define('WP_CACHE', true);\n// END WPR STATIC CACHE\n$1",$old,1,$count);if($count!==1||!is_string($new))return 'wordpress-php';
            self::replaceFile($config,$old,$new);
        }
        $code="<?php\n// WPR STATIC OWNED DROPIN V1\nif (!defined('ABSPATH')) return;\nif (is_file(".var_export(WPR_STATIC_FILE,true).") && is_file(".var_export(__DIR__.'/serve.php',true).")) { require_once ".var_export(__DIR__.'/serve.php',true)."; \\WPR_Static\\Serve::run(".var_export(Storage::root(),true).", 'early-php'); }\n";
        if(file_exists($drop)){if(!self::ownedDropin($drop))throw new \RuntimeException('FRONTEND_OTHER_PAGE_CACHE');self::replaceFile($drop,file_get_contents($drop),$code);}else{if(!is_writable(WP_CONTENT_DIR))return 'wordpress-php';$f=@fopen($drop,'x');if(!$f)return 'wordpress-php';$n=fwrite($f,$code);fclose($f);if($n!==strlen($code)){@unlink($drop);return 'wordpress-php';}}
        return 'early-php';
    }
    private static function removeDropin():void {
        $p=WP_CONTENT_DIR.'/advanced-cache.php';if(self::ownedDropin($p))@unlink($p);
        $c=self::wpConfig();if(!$c||!is_writable($c)||is_file($p))return;$old=file_get_contents($c);$new=str_replace("// BEGIN WPR STATIC CACHE\nif (!defined('WP_CACHE')) define('WP_CACHE', true);\n// END WPR STATIC CACHE\n",'',$old);if($new!==$old)try{self::replaceFile($c,$old,$new);}catch(\Throwable $e){}
    }
    public static function eligible(array $r,string $html):bool {
        if(($r['status']??0)!==200||preg_match('/charset\s*=\s*(?!utf-?8\b)[a-z0-9_-]+/i',$r['type']??'')||!empty($r['cookies'])||!empty($r['robots'])||!empty($r['csp']))return false;
        if(preg_match('/\b(?:private|no-store)\b/i',$r['cache']??''))return false;foreach(explode(',',strtolower($r['vary']??'')) as $vary)if(!in_array(trim($vary),['','accept-encoding'],true))return false;
        if(preg_match_all('~<form\b[^>]*>~i',$html,$forms))foreach($forms[0] as $form){if(preg_match('~\bmethod\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))~i',$form,$method)){if(strtolower($method[1]?:($method[2]??'')?:($method[3]??''))!=='get')return false;}}
        if(preg_match('~\b(?:_wpnonce|wp_rest_nonce|wpApiSettings\s*[^;]{0,150}nonce)\b|\bnonce\s*=|["\x27]nonce["\x27]\s*:|post-password-form|woocommerce-(?:cart|checkout|account)~i',$html))return false;
        return true;
    }
    public static function prepare(int $id,array $manifest):array {
        $base=Storage::build($id);$dir=$base.'/routes';if(!is_dir($dir)&&!mkdir($dir,0700))throw new \RuntimeException('ROUTE_DIRECTORY_FAILED');$routes=[];
        foreach($manifest['files'] as $f){if(($f['type']??'')!=='html'||empty($f['front_ok']))continue;$url=$f['source_url'];$uri=parse_url($url,PHP_URL_PATH)?:'/';if(Serve::requestPath(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$uri])!==$uri)continue;
            $route=['uri'=>$uri,'file'=>$f['path']];Storage::write($dir.'/'.hash('sha256',$uri).'.json',wp_json_encode($route));$routes[]=$route;}
        return $routes;
    }
    public static function onReady(int $id,array $s):void {
        if(empty($s['frontend']))return;
        try{$current=self::get();if(empty($current['enabled'])||($current['revision']??'')!==($s['front_revision']??'')){Engine::log($id,'warning','FRONTEND_STALE_BUILD','Content changed during generation; not activated.');return;}
            self::assertCacheCompatible();$m=Engine::validate($id);$routes=self::prepare($id,$m);if(!$routes)throw new \RuntimeException('FRONTEND_NO_SAFE_ROUTES');
            self::change(function($state)use($id,$s,$routes){if(empty($state['enabled'])||($state['revision']??'')!==($s['front_revision']??''))return $state;
                $source=parse_url($s['source']);$state['host']=strtolower($source['host'].(isset($source['port'])?':'.$source['port']:''));$state['scheme']=$source['scheme'];$state['active_id']=$id;$state['routes']=count($routes);$state['delivery']='wordpress-php';$state['error']='';$state['due']=0;
                try{$state['delivery']=self::installDropin();}catch(\Throwable $e){$state['note']=$e->getMessage();}
                // Apache optimization is best-effort, with a live probe and fail-closed marker.
                try{$state=Direct::activate($id,$routes,$state);}catch(\Throwable $e){$state['note']=$e->getMessage();}
                return $state;});Engine::log($id,'info','FRONTEND_READY','Eligible static frontend routes prepared.');
        }catch(\Throwable $e){try{Direct::kill();self::killPrivate();}catch(\Throwable $ignored){}Engine::log($id,'error','FRONTEND_ACTIVATION_FAILED',$e->getMessage());self::change(function($n)use($e){$n['error']=$e->getMessage();$n['active_id']=0;$n['delivery']='wordpress';return $n;});}
    }
    private static function killDirect(array $s):void{Direct::kill($s);}
    private static function removeRules():void{Direct::remove();}
}
