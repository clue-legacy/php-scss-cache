<?php

class scss_cache{
    protected $debug = false;
    
    private $queryParam;
    
    private $source;
    private $name;
    
    /**
     * instanciate new cache for the given SCSS file path
     * 
     * @param string $path path to import via SCSS
     * @param string|null $name optional name used to reference cache, defaults to name derived from given file name
     * @return scss_cache
     */
    public static function file($path,$name=null){
        if($name === null){
            $name = basename($path).'-'.substr(md5($path),-5);
        }
        return new self('@import "'.$path.'";',$name);
    }
    
    /**
     * instanciate new cache for given SCSS input source
     * 
     * @param string      $source input SCSS source
     * @param string|null $name   name to use for caching this source
     */
    public function __construct($source,$name=null){
        $this->source = $source;
        
        if($name === null){
            $temp = debug_backtrace();
            $name = (isset($temp[0]['file'])) ? $temp[0]['file'].$temp[0]['line'] : 'scss_cache';
        }
        $this->name = $name;
    }
    
    private function httpDate($time){
        return gmdate('D, d M Y H:i:s',$time) . ' GMT';
    }
    
    private function httpPrepare($time){
        header('Content-Type: text/css');
        header('Last-Modified: '.$this->httpDate($time));
        if($this->queryParam !== null && !$this->debug){                                        // virtually "never" expire this resource if a new query param is added every time the resource changes
            header('Expires: '.$this->httpDate(strtotime('+1 year',$time)));    // expire in 1 year ("never" request this resource again, always keep in cache)
        }
        
        if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){                         // previous last-modified header received
            $ref = @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if($ref == $time){                                                 // same as cached time, so stop transfering actual content
                if($this->debug){
                    echo '/* HTTP not modified ('.$time.') */';
                }else{
                    header(' ',true,304); // not modified
                    exit();
                }
            }
        }
        
        // if nothing has been sent (or is to be sent due to parent output buffer)
        if(!headers_sent() && !ob_get_level()){
            // enable compression
            ob_start('ob_gzhandler');
        }
    }
    
    /**
     * set option query param to generate unique URLs whenever the source changes
     * 
     * once set, this allows to send HTTP headers to indicate this resorce
     * never expires. Browsers will therefor not try to access this resource
     * again. Once a change has been detected, the resource has to be requested
     * with a different URI (i.e. a new query parameter is added every time the
     * resource changes - aka cache busting)
     * 
     * @param string|NULL $queryParam parameter name to use or NULL=disable
     * @return scss_cache $this (chainable)
     */
    public function setQueryParam($queryParam){
        $this->queryParam = $queryParam;
        return $this;
    }
    
    /**
     * check whether the source is cache or needs to be recompiled
     * 
     * @return boolean
     */
    public function isCached(){
        return ($this->getCacheChecked() !== null);
    }
    
    /**
     * serve up-to-date resulting CSS via HTTP (recompile if neccessary)
     * 
     * @return void
     * @throws Exception on error
     * @uses self::getCacheChecked()
     * @uses self::update()
     */
    public function serve(){
        $cache = $this->getCacheChecked();
        if($cache !== null){
            if($this->queryParam !== null && (!isset($_GET[$this->queryParam]) || $_GET[$this->queryParam] != $cache['time'])){                     // old or no timestamp supplied
                header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301);                        // permanently moved
                return;
            }
            
            $this->httpPrepare($cache['time']);
            if($this->debug) echo '/* read from cache ('.$cache['time'].') */';
            readfile($cache['target']);
            return;
        }
        
        // TODO: lock and retry...
        // flock();
        // check cache again
        // otherwise continue:
        
        $cache = array();
        try{
            $content = $this->update($cache);
        }
        catch(Exception $e){
            header(' ',true,500); // server error
            echo '/* error: '.$e->getMessage().' */';
            return;
        }
        
        if($this->queryParam !== null){
            header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301); // redirect to new cached file (permanently moved)
        }else{
            if($this->debug) echo '/* new cache target created ('.$cache['time'].') */';
            $this->httpPrepare($cache['time']);
            echo $content;
        }
    }
    
    /**
     * purge (delete) cache files and cache meta data
     *
     * @return void
     */
    public function purge(){
        $cache = xcache_get($this->name);
        if($cache){
            if(is_writeable($cache['target'])){
                unlink($cache['target']);
            }
            xcache_unset($this->name);
        }
    }
    
    /**
     * refresh 
     * 
     * @param array $cache
     * @throws Exception
     * @return string
     */
    protected function update(&$cache=null){
    	$this->purge();
    	
    	$cache = array();
    	$cache['time']   = time();
    	$cache['target'] = $this->tempnam();
    	$cache['hash']   = md5($this->source);
    	$cache['files']  = array();
    	
    	$content = $this->compile($cache);
    	
    	if(file_put_contents($cache['target'],$content,LOCK_EX) === false){
    		throw new Exception('Unable to write compiled source to target cache file');
    	}
    	xcache_set($this->name,$cache);
    	
    	return $content;
    }
    
    /**
     * get up-to-date cache meta data (re-compile if neccessary)
     * 
     * @return array
     * @uses self::getCacheChecked()
     * @uses self::update()
     */
    public function getMeta(){
    	$cache = $this->getCacheChecked();
    	if(!$cache){
    		$this->update($cache);
    	}
    	return $cache;
    }
    
    /**
     * get up-to-date css output (re-compile if neccessary)
     * 
     * @return string
     * @uses self::getCacheChecked()
     * @uses self::update()
     */
    public function getOutput(){
    	$cache = $this->getCacheChecked();
    	if($cache){
    		return file_get_contents($cache['target']);
    	}
    	return $this->update($cache);
    }
    
    protected function getCacheChecked(){
        $cache = xcache_get($this->name);
        if(!$cache){
            return null;
        }
    
        // check cache file
        if(!is_file($cache['target'])){
            if($this->debug) echo '/* target '.$cache['target'].' ('.$cache['time'].') missing */';
            return null;
        }
    
        // check if scss input source has changed
        $hash = md5($this->source);
        if($cache['hash'] !== $hash){
            if($this->debug) echo '/* input source hash ('.$cache['time'].') changed */';
            return null;
        }
    
        // check all files from cache for changes ever since cache was created
        foreach($cache['files'] as $file){
            if(!is_file($file) || filemtime($file) > $cache['time']){
                if($this->debug) echo '/* updated '.$file.' (file:'.filemtime($file).' cache:'.$cache['time'].') */';
                return null;
            }
        }
        return $cache;
    }
    
    /**
     * create new temporary filename to write output cache to
     * 
     * @return string
     */
    protected function tempnam(){
        //'/var/run/'.$this->name.'.out.css';
        return tempnam(sys_get_temp_dir(),$this->name);
    }
    
    /**
     * create new SCSS compiler instance
     * 
     * can be overwritten in order to pass custom formatter options, custom
     * import paths, etc.
     * 
     * @return scssc
     */
    protected function scssc(){
        $formatter = new scss_formatter_compressed();
    
        $scssc = new scssc();
        $scssc->setFormatter($formatter);
    
        return $scssc;
    }
    
    /**
     * compile local SCSS source and return resulting CSS
     * 
     * @param array $cache
     * @return string
     * @throws Exception on error
     */
    protected function compile(&$cache){
        $scssc = $this->scssc();
        
        $content = $scssc->compile($this->source);
        
        foreach($scssc->getParsedFiles() as $file){
            $cache['files'][] = realpath($file);
        }
        
        return $content;
    }
}
