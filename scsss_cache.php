<?php

require_once dirname(__FILE__).'/../scssphp/scss.inc.php';

class scsss_cache{
    private $debug = false;
    
    private $queryParam = 'm';
    
    private $name = 'newdesign.css.php';
    
    public function __construct($name){
        $this->name = $name;
    }
    
    private function httpDate($time){
        return gmdate('D, d M Y H:i:s',$time) . ' GMT';
    }
    
    private function httpPrepare($time){
        header('Content-Type: text/css');
        header('Last-Modified: '.$this->httpDate($time));
        header('Expires: '.$this->httpDate(strtotime('+1 year',$time)));        // expire in 1 year
        
        if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){
            $ref = @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if($ref == $time){
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
    
    public function serve($source){
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
                break;
            }
        }
        
        if(!$refresh){
            // check if scss input source has changed
            $hash = md5($source);
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
            if($this->queryParam !== null && !isset($_GET[$this->queryParam]) || $_GET[$this->queryParam] != $cache['time']){                     // old or no timestamp supplied
                header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301);                        // permanently moved
                exit();
            }
            
            $this->httpPrepare($cache['time']);
            readfile($cache['target']);
            exit();
        }
        
        // TODO: lock and retry...
        // flock();
        // check cache again
        // otherwise continue:
        
        $cache = array();
        $cache['time']   = time();
        $cache['target'] = '/var/run/'.$this->name.'.out.css';
        $cache['hash']   = md5($source);
        $cache['files']  = array();
        
        try{
            $content = $this->compile($source,$cache);
        }
        catch(Exception $e){
            header(' ',true,500); // server error
            die('/* error: '.$e->getMessage().' */');
        }
        
        file_put_contents($cache['target'],$content);
        xcache_set($this->name,$cache);
        
        if($this->queryParam !== null){
            if($this->debug){
                echo '/* goto ?'.$this->queryParam.'='.$cache['time'].' */';
            }else{
                header('Location: ?'.$this->queryParam.'='.$cache['time'],true,301);                           // redirect to new cached file (permanently moved)
            }
        }else{
            $this->httpPrepare($cache['time']);
            echo $content;
        }
    }
    
    protected function compile($source,&$cache){
        $formatter = new scss_formatter_compressed();
        
        $scss = new scssc();
        $scss->setFormatter($formatter);
        
        $content = $scss->compile($source);
        
        foreach($scss->getParsedFiles() as $file){
            $cache['files'][] = realpath($file);
        }
        
        return $content;
    }
}

$source = '@import "newdesign.css";';
foreach(glob(ADMINPATH.'Page/*/newdesign.css') as $path){
    $page = strtolower(basename(dirname($path)));
    $source .= '
#page-user-'.$page.'{
     @import "'.$path.'";
}';
}

$cache = new scsss_cache('newdesign.css');
$cache->setQueryParam(NULL);
$cache->serve($source);
