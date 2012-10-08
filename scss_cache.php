<?php

class scss_cache{
    private $debug = true;
    
    private $queryParam = 'm';
    
    private $source;
    private $name;
    
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
        if($this->queryParam !== NULL){                                        // virtually "never" expire this resource if a new query param is added every time the resource changes
            header('Expires: '.$this->httpDate(strtotime('+1 year',$time)));    // expire in 1 year ("never" request this resource again, always keep in cache)
        }
        
        if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){                         // previous last-modified header received
            $ref = @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if($ref == $time){                                                 // same as cached time, so stop transfering actual content
                header(' ',true,304); // not modified
                exit();
            }
        }
        
        ob_start('ob_gzhandler'); // enable compression
    }
    
    public function setQueryParam($queryParam){
        $this->queryParam = $queryParam;
        return $this;
    }
    
    public function serve(){
        $refresh = false;
        
        $cache = xcache_get($this->name);
        if(!$cache){
            $refresh = true;
        }
        /*
        $cache = array();
        $cache['time'] = time() - 3600;
        $cache['files'] = array();
        */
        
        if(!$refresh){
            // check cache file
            if(!is_file($cache['target'])){
                if($this->debug) echo '/* target '.$cache['target'].' missing */';
                $refresh = true;
            }
        }
        
        if(!$refresh){
            // check if scss input source has changed
            $hash = md5($this->source);
            if($cache['hash'] !== $hash){
                if($this->debug) echo '/* input source hash changed */';
                $refresh = true;
            }
        }
        
        if(!$refresh){
            // check all files from cache for changes ever since cache was created
            foreach($cache['files'] as $file){
                if(!is_file($file) || filemtime($file) > $cache['time']){
                    if($this->debug) echo '/* updated '.$file.' */';
                    $refresh = true;
                    break;
                }
            }
        }
        
        if(!$refresh){
            if($this->queryParam !== null && (!isset($_GET[$this->queryParam]) || $_GET[$this->queryParam] != $cache['time'])){                     // old or no timestamp supplied
                header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301);                        // permanently moved
                return;
            }
            
            $this->httpPrepare($cache['time']);
            if($this->debug) echo '/* read from cache */';
            readfile($cache['target']);
            return;
        }
        
        // TODO: lock and retry...
        // flock();
        // check cache again
        // otherwise continue:
        
        $cache = array();
        $cache['time']   = time();
        $cache['target'] = $this->tempnam($this->name);
        $cache['hash']   = md5($this->source);
        $cache['files']  = array();
        
        try{
            $content = $this->compile($cache);
        }
        catch(Exception $e){
            header(' ',true,500); // server error
            echo '/* error: '.$e->getMessage().' */';
            return;
        }
        
        if(file_put_contents($cache['target'],$content,LOCK_EX) === false){
            throw new Exception('Unable to write compiled source to target cache file');
        }
        xcache_set($this->name,$cache);
        
        if($this->queryParam !== null){
            header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301); // redirect to new cached file (permanently moved)
        }else{
            if($this->debug) echo '/* new cache target created */';
            $this->httpPrepare($cache['time']);
            echo $content;
        }
    }
    
    protected function tempnam($name){
        //'/var/run/'.$name.'.out.css';
        return tempnam(sys_get_temp_dir(),$name);
    }
    
    protected function scssc(){
        $formatter = new scss_formatter_compressed();
    
        $scssc = new scssc();
        $scssc->setFormatter($formatter);
    
        return $scssc;
    }
    
    protected function compile(&$cache){
        $scssc = $this->scssc();
        
        $content = $scssc->compile($this->source);
        
        foreach($scssc->getParsedFiles() as $file){
            $cache['files'][] = realpath($file);
        }
        
        return $content;
    }
}
