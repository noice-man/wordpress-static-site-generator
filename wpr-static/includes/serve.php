<?php
/** Minimal anonymous HTML router. Also loaded by our advanced-cache drop-in. */
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class Serve {
    public static function requestPath(array $server): ?string {
        if (!in_array($server['REQUEST_METHOD']??'', ['GET','HEAD'], true)) return null;
        foreach (['QUERY_STRING','HTTP_COOKIE','HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','PHP_AUTH_USER','HTTP_RANGE','HTTP_X_WPR_STATIC_ORIGIN','HTTP_X_HTTP_METHOD_OVERRIDE','HTTP_X_ORIGINAL_URL','HTTP_X_REWRITE_URL'] as $key) if (!empty($server[$key])) return null;
        $uri=$server['REQUEST_URI']??'';
        if (!is_string($uri)||!str_starts_with($uri,'/')||str_contains($uri,'?')||str_contains($uri,'#')||strlen($uri)>4096||preg_match('~[\\\\\x00-\x20\x7f]~',$uri)) return null;
        $parts=[];foreach(explode('/',$uri) as $part){$decoded=rawurldecode($part);if($decoded==='.'||$decoded==='..'||preg_match('~[/\\\\\x00-\x1f\x7f]~',$decoded)||preg_match('/%[a-f0-9]{2}/i',$decoded))return null;$parts[]=rawurlencode($decoded);}
        $path=implode('/',$parts);if(str_contains($path,'//')||preg_match('~(?:^|/)(?:wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php|\.wpr-static[^/]*)(?:/|$)~i',rawurldecode($path)))return null;
        return $path;
    }
    public static function state(string $root): array { $p=$root.'/frontend.json';if(!is_file($p)||is_link($p))return []; $s=json_decode((string)@file_get_contents($p),true);return is_array($s)?$s:[]; }
    public static function match(string $root,array $server): ?array {
        $path=self::requestPath($server);if($path===null)return null;$s=self::state($root);if(empty($s['enabled'])||empty($s['active_id'])||empty($s['host']))return null;
        if(strtolower($server['HTTP_HOST']??'')!==$s['host'])return null;
        // HTTPS is intentionally not inferred from arbitrary forwarded headers.
        $https=(!empty($server['HTTPS'])&&strtolower((string)$server['HTTPS'])!=='off')||((string)($server['SERVER_PORT']??'')==='443');
        if(($s['scheme']??'https')!==($https?'https':'http'))return null;
        $id=(int)$s['active_id'];if($id<1||!is_file($root.'/frontend-enabled')||is_link($root.'/frontend-enabled')||trim((string)@file_get_contents($root.'/frontend-enabled'))!==(string)$id)return null;
        $base=$root.'/build-'.$id;$route=$base.'/routes/'.hash('sha256',$path).'.json';if(!is_file($route)||is_link($route))return null;
        $r=json_decode((string)@file_get_contents($route),true);if(!is_array($r)||($r['uri']??null)!==$path||!is_string($r['file']??null))return null;
        $rel=$r['file'];if(!preg_match('~\.html?$~i',$rel)||str_starts_with($rel,'/')||preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x1f]~',$rel))return null;
        $file=$base.'/site/'.$rel;$real=realpath($file);$site=realpath($base.'/site');if(!$real||!$site||!str_starts_with($real,$site.'/')||!is_file($real)||!is_readable($real))return null;
        return ['file'=>$real,'id'=>$id];
    }
    public static function run(string $root,string $mode='wordpress-php'):void {
        if(PHP_SAPI==='cli'||headers_sent()||defined('DONOTCACHEPAGE')&&DONOTCACHEPAGE)return;
        $match=self::match($root,$_SERVER);if(!$match)return;
        $handle=@fopen($match['file'],'rb');if(!$handle)return;
        http_response_code(200);header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');header('X-WPR-Static: '.$mode);
        if(($_SERVER['REQUEST_METHOD']??'')!=='HEAD')fpassthru($handle);fclose($handle);exit;
    }
}
