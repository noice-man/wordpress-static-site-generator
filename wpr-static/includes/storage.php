<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class Storage {
    public static function within(string $path,string $root): bool {return $path===$root || str_starts_with($path,rtrim($root,'/').'/');}
    public static function root(): string {
        $wp=realpath(ABSPATH); $web=!empty($_SERVER['DOCUMENT_ROOT'])?(realpath($_SERVER['DOCUMENT_ROOT'])?:$wp):$wp;
        $default=rtrim(dirname($web),'/').'/.wpr-static-'.substr(hash('sha256',$wp.'|'.get_current_blog_id()),0,20);
        $root=defined('WPR_STATIC_STORAGE')?WPR_STATIC_STORAGE:$default;
        if (!is_string($root) || !str_starts_with($root,'/') || is_link($root) || !is_dir(dirname($root))) throw new \RuntimeException('STORAGE_PATH_INVALID');
        $parent=realpath(dirname($root));$root=$parent.'/'.basename($root);
        if (!$parent || self::within($root,$web) || self::within($root,$wp) || self::within($web,$root) || self::within($wp,$root)) throw new \RuntimeException('STORAGE_MUST_BE_PRIVATE');
        if (!is_dir($root) && !@mkdir($root,0700)) throw new \RuntimeException('STORAGE_NOT_WRITABLE: define WPR_STATIC_STORAGE outside document root');
        if (is_link($root) || !is_writable($root)) throw new \RuntimeException('STORAGE_NOT_WRITABLE');
        @chmod($root,0700);return $root;
    }
    public static function build(int $id): string {if($id<1)throw new \RuntimeException('BUILD_INVALID');return self::root().'/build-'.$id;}
    public static function path(string $root,string $relative): string {
        if ($relative==='' || str_starts_with($relative,'/') || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x1f]~',$relative)) throw new \RuntimeException('PATH_INVALID');
        $path=$root;foreach(explode('/',$relative) as $part){$path.='/'.$part;if(is_link($path))throw new \RuntimeException('SYMLINK_REJECTED');}return $path;
    }
    public static function write(string $path,string $data): void {
        $d=dirname($path);if(!is_dir($d)&&!@mkdir($d,0700,true))throw new \RuntimeException('MKDIR_FAILED');
        $tmp=tempnam($d,'.wpr-');if(!$tmp)throw new \RuntimeException('TEMP_FAILED');
        try{if(file_put_contents($tmp,$data)!==strlen($data)||!rename($tmp,$path))throw new \RuntimeException('WRITE_FAILED');}finally{if(is_file($tmp))unlink($tmp);}
    }
    public static function remove(string $p): void {if(is_link($p))throw new \RuntimeException('SYMLINK_REJECTED');if(is_dir($p)){foreach(new \FilesystemIterator($p) as $f)self::remove($f->getPathname());if(!rmdir($p))throw new \RuntimeException('REMOVE_FAILED');}elseif(is_file($p)&&!unlink($p))throw new \RuntimeException('REMOVE_FAILED');}
    public static function files(string $root): \Generator {if(!is_dir($root))return; $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){if($f->isLink())throw new \RuntimeException('UNSAFE_FILE');if($f->isDir())continue;if(!$f->isFile())throw new \RuntimeException('UNSAFE_FILE');yield substr($f->getPathname(),strlen($root)+1)=>$f->getPathname();}}
    public static function lock() { $f=fopen(self::root().'/worker.lock','c'); if(!$f || !flock($f,LOCK_EX|LOCK_NB)){if($f)fclose($f);throw new \RuntimeException('WORKER_BUSY');} return $f; }
    public static function unlock($f):void{flock($f,LOCK_UN);fclose($f);}
    public static function zip(int $id): string {
        if(!class_exists('ZipArchive'))throw new \RuntimeException('ZIP_EXTENSION_MISSING');$dir=self::build($id);$out=$dir.'/site.zip';$tmp=$dir.'/site.zip.tmp';$z=new \ZipArchive();if($z->open($tmp,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('ZIP_OPEN_FAILED');
        try{foreach(self::files($dir.'/site') as $rel=>$p){if(!$z->addFile($p,$rel))throw new \RuntimeException('ZIP_ADD_FAILED');}if(!$z->close())throw new \RuntimeException('ZIP_CLOSE_FAILED');if(!rename($tmp,$out))throw new \RuntimeException('ZIP_WRITE_FAILED');}catch(\Throwable $e){@$z->close();@unlink($tmp);throw $e;}return $out;
    }
    public static function publish(int $id,string $dest): void {
        $dest=rtrim($dest,'/');if(!str_starts_with($dest,'/')||is_link($dest)||!is_dir(dirname($dest))||in_array(basename($dest),['.','..',''],true))throw new \RuntimeException('DESTINATION_INVALID');
        $dest=realpath(dirname($dest)).'/'.basename($dest);$wp=realpath(ABSPATH);$root=self::root();
        foreach([$wp,$root,realpath(WP_CONTENT_DIR)?:$wp] as $protected)if(self::within($dest,$protected)||self::within($protected,$dest))throw new \RuntimeException('DESTINATION_PROTECTED');
         $allowed=defined('WPR_STATIC_PUBLISH_ROOT')?realpath(WPR_STATIC_PUBLISH_ROOT):dirname($wp);if(!$allowed||$allowed==='/'||$dest===$allowed||!self::within($dest,$allowed))throw new \RuntimeException('DESTINATION_OUTSIDE_ALLOWED_ROOT');
        if(substr_count($dest,'/')<2 || (file_exists($dest)&&!is_dir($dest)))throw new \RuntimeException('DESTINATION_INVALID');
        $registry=get_option('wpr_static_destinations',[]);$previous=$registry[$dest]??[];
        if(is_dir($dest))foreach(self::files($dest) as $rel=>$p){if(!isset($previous[$rel])||!hash_equals($previous[$rel],hash_file('sha256',$p)))throw new \RuntimeException('DESTINATION_UNMANAGED_OR_MODIFIED: '.$rel);}
        $token=bin2hex(random_bytes(8));$stage=dirname($dest).'/.wpr-stage-'.$token;$backup=dirname($dest).'/.wpr-backup-'.$token;
        if(!mkdir($stage,0755))throw new \RuntimeException('STAGING_FAILED');$next=[];$moved=false;$activated=false;
        try{foreach(self::files(self::build($id).'/site') as $rel=>$src){$dst=self::path($stage,$rel);if(!is_dir(dirname($dst))&&!mkdir(dirname($dst),0755,true))throw new \RuntimeException('STAGING_MKDIR_FAILED');if(!copy($src,$dst))throw new \RuntimeException('STAGING_COPY_FAILED');chmod($dst,0644);$next[$rel]=hash_file('sha256',$src);if(!hash_equals($next[$rel],hash_file('sha256',$dst)))throw new \RuntimeException('STAGING_HASH_FAILED');}
            // Persist recovery information before touching the existing target.
            update_option('wpr_static_publish_journal',['dest'=>$dest,'stage'=>$stage,'backup'=>$backup,'phase'=>'prepared','next'=>$next],false);
            if(is_dir($dest)){if(!rename($dest,$backup))throw new \RuntimeException('BACKUP_RENAME_FAILED');$moved=true;}
            if(!rename($stage,$dest))throw new \RuntimeException('ACTIVATE_FAILED');$activated=true;
            $registry[$dest]=$next;update_option('wpr_static_destinations',$registry,false);
            delete_option('wpr_static_publish_journal');if($moved)self::remove($backup);
        }catch(\Throwable $e){if(!$activated&&$moved&&!file_exists($dest))@rename($backup,$dest);if(!$activated&&(!$moved||is_dir($dest))){delete_option('wpr_static_publish_journal');if(is_dir($stage))self::remove($stage);}throw $e;}
    }
}
