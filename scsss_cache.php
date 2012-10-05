<?php

require_once dirname(__FILE__).'/../scssphp/scss.inc.php';

if(true){
    function httpdate($time){
        return gmdate('D, d M Y H:i:s',$time) . ' GMT';
    }
}

$refresh = false;
$debug = false;

$cache = xcache_get('newdesign.css.php');
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
        if($debug) echo '/* target '.$cache['target'].' missing */';
        $refresh = true;
        break;
    }
}

if(!$refresh){
    // search for new page-'newdesign.css' files not already in the cache
    foreach(glob(__DIR__.'/*/newdesign.css') as $file){
        if(!in_array($file,$cache['files'],true)){
            if($debug) echo '/* new '.$file.' */';
            $refresh = true;
            break;
        }
    }
}

if(!$refresh){
    // check all files from cache for changes ever since cache was created
    foreach($cache['files'] as $file){
        if(!is_file($file) || filemtime($file) > $cache['time']){
            if($debug) echo '/* updated '.$file.' */';
            $refresh = true;
            break;
        }
    }
}

if(!$refresh){
    if(!isset($_GET['m']) || $_GET['m'] != $cache['time']){                     // old or no timestamp supplied
        header('Location: ?m='.$cache['time'],true,301);                        // permanently moved
        exit();
    }

    header('Content-Type: text/css');
    header('Last-Modified: '.httpdate($cache['time']));
    header('Expires: '.httpdate(time()+3600*24*360));                           // expire in 1 year

    if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])){
        $ref = @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if($ref == $cache['time']){
            header(' ',true,304); // not modified
            exit();
        }
    }

    ob_start('ob_gzhandler'); // enable compression
    readfile($cache['target']);
    exit();
}

// TODO: lock and retry...
// flock();
// check cache again
// otherwise continue:

$oldcache = $cache;
$cache = array();
$cache['time'] = time();
$cache['target'] = '/var/run/newdesign.out.csss';


$source = '@import "newdesign.css";';

foreach(glob(ADMINPATH.'Page/*/newdesign.css') as $path){
    $page = basename(dirname($path));
    //$page = lcfirst($page);
    //$page = strtolower(substr($page,0,1)).substr($page,1);
    $page = strtolower($page);
    $source .= '
#page-user-'.$page.'{
     @import "'.$path.'";
}';
}

$formatter = new scss_formatter_compressed();

$scss = new scssc();
//$scss->setFormatter($formatter);

try{
    $content = $scss->compile($source);
}
catch(Exception $e){
    header(' ',true,500); // server error
    die('/* error: '.$e->getMessage().' */');
}

$cache['files'] = array();
foreach($scss->getParsedFiles() as $file){
    $cache['files'] []= realpath($file);
}

file_put_contents($cache['target'],$content);
xcache_set('newdesign.css.php',$cache);

if($debug){
    echo '/* goto ?m='.$cache['time'].' */';
}else{
    header('Location: ?m='.$cache['time'],true,301);                           // redirect to new cached file (permanently moved)
}