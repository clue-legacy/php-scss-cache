<?php

error_reporting(E_ALL|E_STRICT);
require_once dirname(__FILE__).'/../scssphp/scss.inc.php';
require_once dirname(__FILE__).'/scss_cache.php';

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
