<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
/** Optional Apache optimization. Only a verified, dedicated guarded directory is used. */
final class Direct {
    const BEGIN='# BEGIN WPR STATIC FRONTEND';
    const END='# END WPR STATIC FRONTEND';
    public static function name():string{return '.wpr-static-front-'.substr(hash('sha256',rtrim(ABSPATH,'/')),0,16);}
    public static function root():string{return rtrim(ABSPATH,'/').'/'.self::name();}
    private static function owner():string{return hash('sha256','WPR STATIC DIRECT V1|'.rtrim(ABSPATH,'/'));}
    private static function owned():bool{$r=self::root();return is_dir($r)&&!is_link($r)&&!is_link($r.'/owner')&&is_file($r.'/owner')&&hash_equals(self::owner(),(string)file_get_contents($r.'/owner'));}
    public static function kill(array $state=[]):void {if(!self::owned())return;$p=self::root().'/enabled';if(is_file($p)&&!@unlink($p)){self::removeBlock();if(is_file($p))throw new \RuntimeException('DIRECT_DISABLE_FAILED');}}
    private static function path():string{return rtrim(ABSPATH,'/').'/.htaccess';}
    public static function strip(string $text):string{return preg_replace('~^'.preg_quote(self::BEGIN,'~').'\r?\n.*?^'.preg_quote(self::END,'~').'\r?\n?~ms','',$text)??$text;}
    private static function writeConfig(string $old,string $new):void {
        $p=self::path();if(is_link($p)||!is_writable(dirname($p)))throw new \RuntimeException('APACHE_CONFIG_NOT_WRITABLE');
        if(!file_exists($p)){if($old!=='')throw new \RuntimeException('APACHE_CONFIG_CHANGED');$f=@fopen($p,'x');if(!$f)throw new \RuntimeException('APACHE_CONFIG_CREATE_FAILED');$n=fwrite($f,$new);fclose($f);if($n!==strlen($new)){@unlink($p);throw new \RuntimeException('APACHE_CONFIG_WRITE_FAILED');}return;}
        if(!is_file($p)||!is_writable($p)||file_get_contents($p)!==$old)throw new \RuntimeException('APACHE_CONFIG_CHANGED');$t=tempnam(dirname($p),'.wpr-');if(!$t)throw new \RuntimeException('APACHE_CONFIG_WRITE_FAILED');try{if(file_put_contents($t,$new)!==strlen($new)||!chmod($t,fileperms($p)&0777)||file_get_contents($p)!==$old||!rename($t,$p))throw new \RuntimeException('APACHE_CONFIG_WRITE_FAILED');}finally{if(is_file($t))unlink($t);}
    }
    private static function removeBlock():void { $p=self::path();if(!is_file($p)||is_link($p))return;$old=file_get_contents($p);$new=self::strip($old);if($new!==$old)self::writeConfig($old,$new); }
    public static function remove():void {self::kill();try{self::removeBlock();}finally{if(self::owned())Storage::remove(self::root());}}
    public static function routeFile(string $uri):?string {
        // Unicode and unusual URL shapes go through the exact-match PHP router.
        if(!preg_match('#^/[A-Za-z0-9._~/-]*$#D',$uri))return null;
        if($uri==='/')return 'root.html';if(str_contains($uri,'//'))return null;$p=trim($uri,'/');foreach(explode('/',$p) as $part)if($part===''||str_starts_with($part,'.'))return null;
        return (str_ends_with($uri,'/')?'slash/':'plain/').$p.'/index.html';
    }
    public static function rules(int $id,string $host,string $scheme,string $probe):string {
        $name=self::name();$dir=self::root();$path=preg_quote($name,'~');$host=preg_quote($host,'~');
        $out=self::BEGIN."\n<IfModule mod_rewrite.c>\nRewriteEngine On\n";
        $out.='RewriteRule ^'.$path.'(?:/|$) - [F,END]' . "\n";
        $out.='RewriteRule ^'.preg_quote($probe,'~').'$ '.$name.'/probe.txt [END]' . "\n";
        $common="RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$\nRewriteCond %{QUERY_STRING} ^$\nRewriteCond %{HTTP_COOKIE} ^$\nRewriteCond %{HTTP:Authorization} ^$\nRewriteCond %{HTTP:X-WPR-Static-Origin} ^$\nRewriteCond %{HTTP:Range} ^$\nRewriteCond %{HTTP:X-HTTP-Method-Override} ^$\nRewriteCond %{HTTP:X-Original-URL} ^$\nRewriteCond %{HTTP:X-Rewrite-URL} ^$\nRewriteCond %{HTTP_HOST} ^".$host."$ [NC]\nRewriteCond %{HTTPS} ".($scheme==='https'?'=on':'!=on')."\nRewriteCond %{THE_REQUEST} \"^[A-Z]+ /[A-Za-z0-9._~/-]* HTTP/\"\nRewriteCond \"".$dir."/enabled\" -f\nRewriteCond \"".WPR_STATIC_FILE."\" -f\n";
        foreach(['^$'=>'root.html','^([A-Za-z0-9_~-][A-Za-z0-9._~/-]*)/$'=>'slash/$1/index.html','^([A-Za-z0-9_~-][A-Za-z0-9._~/-]*)$'=>'plain/$1/index.html'] as $rule=>$file){$out.=$common;if($rule!=='^$')$out.="RewriteCond %{REQUEST_FILENAME} !-f\n";$out.='RewriteCond "'.$dir.'/release-'.$id.'/'.$file.'" -f' . "\n".'RewriteRule '.$rule.' '.$name.'/release-'.$id.'/'.$file.' [END]' . "\n";}
        return $out."</IfModule>\n".self::END."\n";
    }
    public static function activate(int $id,array $routes,array $state):array {
        $state['direct_routes']=0;$state['note']='';self::kill();
        $doc=realpath($_SERVER['DOCUMENT_ROOT']??'');$home=home_url('/');
        if(!$doc||$doc!==realpath(ABSPATH)||parse_url($home,PHP_URL_PATH)!=='/'||!preg_match('/Apache/i',$_SERVER['SERVER_SOFTWARE']??'')||!is_writable(ABSPATH)||preg_match('~["\\\\\r\n]~',ABSPATH.WPR_STATIC_FILE)){$state['note']='APACHE_DIRECT_NOT_AVAILABLE';return $state;}
        $p=self::path();if(is_link($p)||file_exists($p)&&(!is_file($p)||!is_writable($p))){$state['note']='APACHE_CONFIG_NOT_WRITABLE';return $state;}
        $old=is_file($p)?file_get_contents($p):'';$base=self::strip($old);
        // Do not put the static router ahead of unknown redirects, access controls or other cache rules.
        $outside=preg_replace('~^# BEGIN WordPress\r?\n.*?^# END WordPress\r?\n?~ms','',$base);
        if(preg_match('/^[ \t]*[^#\s]/m',$outside??'')){$state['note']='APACHE_CUSTOM_RULES_PRESENT';return $state;}
        $root=self::root();$stage=$root.'/release-'.$id;$newRoot=false;$probe='wpr-static-probe-'.bin2hex(random_bytes(12));$value=bin2hex(random_bytes(24));
        try{
            if(!file_exists($root)){if(!mkdir($root,0755))throw new \RuntimeException('DIRECT_ROOT_FAILED');$newRoot=true;Storage::write($root.'/owner',self::owner());}
            if(!self::owned())throw new \RuntimeException('DIRECT_ROOT_NOT_OWNED');
            // A direct external request must never reach these copies. END in the root rule prevents re-entry for internal rewrites.
            Storage::write($root.'/.htaccess',"<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [F,END]\n</IfModule>\n<IfModule mod_headers.c>\nHeader always set X-WPR-Static \"apache\"\nHeader always set Cache-Control \"no-store, private, max-age=0\"\nHeader always set X-Content-Type-Options \"nosniff\"\n<FilesMatch \"\\.html$\">\nHeader always set Content-Type \"text/html; charset=UTF-8\"\n</FilesMatch>\n</IfModule>\n");chmod($root.'/.htaccess',0644);
            Storage::write($root.'/probe.txt',$value);chmod($root.'/probe.txt',0644);
            self::kill();$rules=self::rules($id,$state['host'],$state['scheme'],$probe);self::writeConfig($old,$rules.$base);
            // Verify actual HTTP routing AND rejection of direct/encoded directory access before copying any pages.
            $args=['timeout'=>3,'redirection'=>0,'sslverify'=>true,'cookies'=>[],'headers'=>['Cache-Control'=>'no-cache']];
            $good=wp_remote_get(rtrim($home,'/').'/'.$probe,$args);if(is_wp_error($good)||wp_remote_retrieve_response_code($good)!==200||wp_remote_retrieve_body($good)!==$value||wp_remote_retrieve_header($good,'x-wpr-static')!=='apache'||!str_contains(strtolower((string)wp_remote_retrieve_header($good,'cache-control')),'no-store'))throw new \RuntimeException('APACHE_DIRECT_PROBE_FAILED');
            foreach([$rootUrl=self::name(),str_replace('.','%2e',self::name())] as $url){$bad=wp_remote_get(rtrim($home,'/').'/'.$url.'/probe.txt',$args);if(is_wp_error($bad)||!in_array(wp_remote_retrieve_response_code($bad),[403,404],true)||wp_remote_retrieve_body($bad)===$value)throw new \RuntimeException('APACHE_DIRECT_GUARD_FAILED');}
            if(is_dir($stage))Storage::remove($stage);if(!mkdir($stage,0755))throw new \RuntimeException('DIRECT_STAGE_FAILED');$n=0;$bytes=0;$time=microtime(true);
            foreach($routes as $r){$rel=self::routeFile($r['uri']);if($rel===null)continue;$src=Storage::path(Storage::build($id).'/site',$r['file']);$bytes+=filesize($src);if($n>=3000||$bytes>134217728||microtime(true)-$time>5)throw new \RuntimeException('DIRECT_COPY_BUDGET');$dest=Storage::path($stage,$rel);if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0755,true))throw new \RuntimeException('DIRECT_MKDIR_FAILED');if(!copy($src,$dest)||!chmod($dest,0644)||!hash_equals(hash_file('sha256',$src),hash_file('sha256',$dest)))throw new \RuntimeException('DIRECT_COPY_FAILED');$n++;}
            if(!$n)throw new \RuntimeException('APACHE_NO_ELIGIBLE_ROUTES');
            @unlink($root.'/probe.txt');Storage::write($root.'/enabled',(string)$id);chmod($root.'/enabled',0644);
            foreach($routes as $r){if(self::routeFile($r['uri'])===null)continue;$test=wp_remote_get(rtrim($home,'/').$r['uri'],$args);if(is_wp_error($test)||wp_remote_retrieve_response_code($test)!==200||wp_remote_retrieve_header($test,'x-wpr-static')!=='apache'||!hash_equals(hash_file('sha256',Storage::path(Storage::build($id).'/site',$r['file'])),hash('sha256',wp_remote_retrieve_body($test))))throw new \RuntimeException('APACHE_HTML_PROBE_FAILED');break;}
            foreach(new \FilesystemIterator($root) as $f)if($f->isDir()&&!$f->isLink()&&preg_match('/^release-\d+$/',$f->getFilename())&&$f->getPathname()!==$stage)try{Storage::remove($f->getPathname());}catch(\Throwable $e){}
            $state['delivery']='apache+'.$state['delivery'];$state['direct_routes']=$n;return $state;
        }catch(\Throwable $e){try{self::kill();self::removeBlock();if(self::owned())Storage::remove($root);}catch(\Throwable $cleanup){}$state['note']=$e->getMessage();return $state;}
    }
}
