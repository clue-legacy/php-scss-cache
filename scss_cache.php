<?php

class scss_cache{
    protected $debug = false;
    
    protected $queryParam;
    
    protected $source;
    protected $name;
    
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
    
    protected function httpDate($time){
        return gmdate('D, d M Y H:i:s',$time) . ' GMT';
    }
    
    protected function httpPrepare($time){
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
        return ($this->getMetaChecked() !== null);
    }
    
    /**
     * serve up-to-date resulting CSS via HTTP (recompile if neccessary)
     * 
     * @return void
     * @throws Exception on error
     * @uses self::getMetaChecked()
     * @uses self::update()
     */
    public function serve(){
        $meta = $this->getMetaChecked();
        if($meta !== null){
            if($this->queryParam !== null && (!isset($_GET[$this->queryParam]) || $_GET[$this->queryParam] != $meta['time'])){                     // old or no timestamp supplied
                header('Location: ?'.$this->queryParam.'='.$meta['time'],true,301);                        // permanently moved
                return;
            }
            
            $this->httpPrepare($meta['time']);
            if($this->debug) echo '/* read from cache ('.$meta['time'].') */';
            readfile($meta['target']);
            return;
        }
        
        // TODO: lock and retry...
        // flock();
        // check cache again
        // otherwise continue:
        
        $meta = array();
        try{
            $content = $this->update($meta);
        }
        catch(Exception $e){
            header(' ',true,500); // server error
            echo '/* error: '.$e->getMessage().' */';
            return;
        }
        
        if($this->queryParam !== null){
            header('Location: ?'.$this->queryParam.'='.$meta['time'],true,301); // redirect to new cached file (permanently moved)
        }else{
            if($this->debug) echo '/* new cache target created ('.$meta['time'].') */';
            $this->httpPrepare($meta['time']);
            echo $content;
        }
    }
    
    /**
     * purge (delete) cache files and cache meta data
     *
     * @return void
     */
    public function purge(){
        $meta = xcache_get($this->name);
        if($meta){
            if(is_writeable($meta['target'])){
                unlink($meta['target']);
            }
            xcache_unset($this->name);
        }
    }
    
    /**
     * refresh 
     * 
     * @param array $meta
     * @throws Exception
     * @return string
     */
    protected function update(&$meta=null){
    	$this->purge();
    	
    	$meta = array();
    	$meta['time']   = time();
    	$meta['target'] = $this->tempnam();
    	$meta['hash']   = md5($this->source);
    	$meta['files']  = array();
    	
    	$content = $this->compile($meta);
    	
    	if(file_put_contents($meta['target'],$content,LOCK_EX) === false){
    		throw new Exception('Unable to write compiled source to target cache file');
    	}
    	xcache_set($this->name,$meta);
    	
    	return $content;
    }
    
    /**
     * get up-to-date cache meta data (re-compile if neccessary)
     * 
     * @return array
     * @uses self::getMetaChecked()
     * @uses self::update()
     */
    public function getMeta(){
    	$meta = $this->getMetaChecked();
    	if(!$meta){
    		$this->update($meta);
    	}
    	return $meta;
    }
    
    /**
     * get up-to-date css output (re-compile if neccessary)
     * 
     * @return string
     * @uses self::getMetaChecked()
     * @uses self::update()
     */
    public function getOutput(){
    	$meta = $this->getMetaChecked();
    	if($meta){
    		return file_get_contents($meta['target']);
    	}
    	return $this->update($meta);
    }
    
    protected function getMetaChecked(){
        $meta = xcache_get($this->name);
        if(!$meta){
            return null;
        }
    
        // check cache file
        if(!is_file($meta['target'])){
            if($this->debug) echo '/* target '.$meta['target'].' ('.$meta['time'].') missing */';
            return null;
        }
    
        // check if scss input source has changed
        $hash = md5($this->source);
        if($meta['hash'] !== $hash){
            if($this->debug) echo '/* input source hash ('.$meta['time'].') changed */';
            return null;
        }
    
        // check all files from cache for changes ever since cache was created
        foreach($meta['files'] as $file){
            if(!is_file($file) || filemtime($file) > $meta['time']){
                if($this->debug) echo '/* updated '.$file.' (file:'.filemtime($file).' cache:'.$meta['time'].') */';
                return null;
            }
        }
        return $meta;
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
     * @param array $meta
     * @return string
     * @throws Exception on error
     */
    protected function compile(&$meta){
        $scssc = $this->scssc();
        
        $content = $scssc->compile($this->source);
        
        foreach($scssc->getParsedFiles() as $file){
            $meta['files'][] = realpath($file);
        }
        
        return $content;
    }
}
