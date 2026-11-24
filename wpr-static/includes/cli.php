<?php
namespace WPR_Static;
if (!defined('ABSPATH')) exit;
final class CLI {
    private function run(callable $fn):void{try{$fn();}catch(\Throwable $e){\WP_CLI::error($e->getMessage());}}
    private function id(array $a):int{return isset($a['id'])?(int)$a['id']:Engine::latest();}
    private function out($v):void{\WP_CLI::line(wp_json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    /** Enable the same-domain static frontend and process the build. */
    public function enable($args,$assoc):void{$this->run(function(){$id=Frontend::enable();if($id)$this->process($id);$this->out(Frontend::get());});}
    /** Rebuild the same-domain frontend. */
    public function rebuild($args,$assoc):void{$this->run(function(){$id=Frontend::rebuild();if($id)$this->process($id);$this->out(Frontend::get());});}
    /** Immediately return to WordPress. */
    public function disable($args,$assoc):void{$this->run(fn()=>Frontend::disable());}
    /** Show same-domain delivery state. */
    public function frontend($args,$assoc):void{$this->run(fn()=>$this->out(Frontend::get()));}
    /** Check server readiness. */
    public function doctor($args,$assoc):void{$this->run(function(){$r=Engine::doctor();$this->out($r);foreach($r as $c)if($c['level']==='error')\WP_CLI::halt(1);});}
    /** Create a build and process its queue. */
    public function build($args,$assoc):void{$this->run(function(){$id=Engine::start();$this->process($id);});}
    private function process(int $id):void{do{Engine::tick($id,15);$b=Engine::build($id);\WP_CLI::log('Build #'.$id.' '.$b['status'].' '.$b['phase']);}while(in_array($b['status'],['pending','processing','validating'],true));$this->out(Engine::stats($id));if($b['status']!=='ready')\WP_CLI::halt(1);}
    /** Show current status. */
    public function status($args,$assoc):void{$this->run(fn()=>$this->out(Engine::stats($this->id($assoc))));}
    /** Resume a paused build or continue an active queue. */
    public function resume($args,$assoc):void{$this->run(function()use($assoc){$id=$this->id($assoc);if(Engine::build($id)['status']==='paused')Engine::control($id,'resume');$this->process($id);});}
    /** Cancel a build. */
    public function cancel($args,$assoc):void{$this->run(fn()=>Engine::control($this->id($assoc),'cancel'));}
    /** Verify a completed build against its manifest. */
    public function validate($args,$assoc):void{$this->run(fn()=>$this->out(['files'=>count(Engine::validate($this->id($assoc))['files']),'status'=>'ready']));}
    /** Create a ZIP file and return its path. */
    public function export($args,$assoc):void{$this->run(fn()=>\WP_CLI::success(Engine::export($this->id($assoc))));}
    /** Publish to configured directory. */
    public function publish($args,$assoc):void{$this->run(function()use($assoc){Engine::publish($this->id($assoc),$assoc['path']??null);\WP_CLI::success('Published.');});}
}
