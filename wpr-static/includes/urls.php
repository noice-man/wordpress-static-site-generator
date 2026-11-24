<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class URLs {
    public static function origin(string $u): string {
        $p=parse_url($u); if (!$p || !isset($p['host'],$p['scheme']) || !in_array(strtolower($p['scheme']),['http','https'],true) || isset($p['user']) || isset($p['pass'])) return '';
        $s=strtolower($p['scheme']); $h=strtolower($p['host']); $port=$p['port']??null;
        return $s.'://'.$h.($port && $port!==($s==='https'?443:80)?':'.$port:'');
    }
    public static function normalize(string $u): string {
        $o=self::origin($u); if (!$o || preg_match('/[\x00-\x20\x7f\\\\]/',$u)) return '';
        $p=parse_url($u); $parts=[];
        foreach(explode('/', $p['path']??'/') as $part){
            if ($part==='' || $part==='.') continue;
            $d=rawurldecode($part); if ($d==='..') {array_pop($parts);continue;}
            if ($d==='.') continue;
            if (preg_match('~[/\\\\\x00-\x1f\x7f]~',$d) || preg_match('/%[a-f0-9]{2}/i',$d)) return '';
            $parts[]=rawurlencode($d);
        }
        $path='/'.implode('/',$parts); if ($path!=='/' && str_ends_with($p['path']??'/','/')) $path.='/';
        return $o.$path.(isset($p['query'])&&$p['query']!==''?'?'.$p['query']:'');
    }
    public static function resolve(string $ref,string $base): string {
        $ref=trim($ref); if ($ref==='' || str_starts_with($ref,'#') || preg_match('/[\x00-\x1f\x7f\\\\]/',$ref)) return '';
        $ref=explode('#',$ref,2)[0]; $ref=str_replace(' ','%20',$ref);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i',$ref)) return self::normalize($ref);
        $o=self::origin($base); if (!$o) return '';
        if (str_starts_with($ref,'//')) return self::normalize(parse_url($base,PHP_URL_SCHEME).':'.$ref);
        $path=parse_url($base,PHP_URL_PATH)?:'/';
        if (str_starts_with($ref,'?')) return self::normalize($o.$path.$ref);
        if (str_starts_with($ref,'/')) return self::normalize($o.$ref);
        return self::normalize($o.substr($path,0,strrpos($path,'/')+1).$ref);
    }
    public static function internal(string $url,string $source): bool {return self::origin($url)!=='' && self::origin($url)===self::origin($source);}
    public static function safe(string $u): bool {
        $p=rawurldecode(parse_url($u,PHP_URL_PATH)??''); $q=parse_url($u,PHP_URL_QUERY)??'';
        if (preg_match('~(?:^|/)(?:\.[^/]+|wp-admin|wp-json|wp-login\.php|xmlrpc\.php|wp-config(?:\.[^/]+)?|wp-cron\.php|wp-comments-post\.php|wp-signup\.php|wp-activate\.php|wp-trackback\.php|wpr-static)(?:/|$)~i',$p)) return false;
        if (preg_match('~(?:^|/)(?:credentials|secrets|config|settings|service-account|database)(?:\.[^/]+)?$~i',$p)) return false;
        if (preg_match('~\.(?:php\d*|phtml|phar|cgi|pl|asp[x]?|jsp|sql|log|ini|conf|bak|env|key|pem|sh|zip|tar|gz|7z|rar)(?:/|$)~i',$p)) return false;
        if (preg_match('~(?:^|/)(?:backups?|updraft|ai1wm-backups|wpvividbackups|wflogs|debug\.log)(?:/|$)~i',$p)) return false;
        if (preg_match('~(?:^|&)(?:preview|preview_id|preview_nonce|rest_route|_wpnonce|nonce|token|password|auth|add-to-cart|wc-ajax|session|key|action|s)(?:=|&|$)~i',rawurldecode($q))) return false;
        return true;
    }
    public static function asset(string $u): bool {return (bool)preg_match('~\.(css|m?js|jpe?g|png|webp|avif|gif|svg|ico|woff2?|ttf|otf|eot|pdf|json|xml|txt|mp[34]|webm|ogg|wav|m4a|mov|vtt|wasm)$~i',parse_url($u,PHP_URL_PATH)??'');}
    public static function path(string $u,string $kind,string $source): string {
        $raw=parse_url($u,PHP_URL_PATH)?:'/'; $home=rtrim(parse_url($source,PHP_URL_PATH)?:'/','/');
        if ($home!=='' && ($raw===$home || str_starts_with($raw,$home.'/'))) $raw=substr($raw,strlen($home))?:'/';
        $path=rawurldecode(ltrim($raw,'/')); if(preg_match('~[\x00-\x1f\x7f:<>|?*\"]~',$path))throw new \RuntimeException('UNSAFE_OUTPUT_NAME'); $q=parse_url($u,PHP_URL_QUERY);
        if ($kind==='404') return '404.html';
        if ($kind==='html') {if ($q!==null) throw new \RuntimeException('HTML_QUERY_UNSUPPORTED');return $path===''?'index.html':(preg_match('/\.html?$/i',$path)?$path:rtrim($path,'/').'/index.html');}
        if ($path==='' || str_ends_with($path,'/')) throw new \RuntimeException('ASSET_PATH_INVALID');
        if ($q!==null) {$ext=pathinfo($path,PATHINFO_EXTENSION);$suffix='--'.substr(hash('sha256',$q),0,16);$path=$ext?substr($path,0,-strlen($ext)-1).$suffix.'.'.$ext:$path.$suffix;}
        return $path;
    }
    public static function publicPath(string $path): string { $path=$path==='index.html'?'':(str_ends_with($path,'/index.html')?substr($path,0,-10):$path);return implode('/',array_map('rawurlencode',explode('/',$path)));}
    public static function target(string $path,string $current,array $s,bool $seo=false): string {
        $p=self::publicPath($path);
        if ($seo || ($s['mode']??'absolute')==='absolute') return !empty($s['destination'])?rtrim($s['destination'],'/').'/'.$p:'/'.$p;
        $depth=substr_count($current,'/'); return str_repeat('../',$depth).($p===''?'./':$p);
    }
}
