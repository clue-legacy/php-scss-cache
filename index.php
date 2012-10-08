<?php

require_once dirname(__FILE__).'/../scssphp/scss.inc.php';
require_once dirname(__FILE__).'/scss_cache.php';

$source = '@import "newdesign.scss";';
foreach(glob(dirname(__FILE__).'/Page/*/newdesign.scss') as $path){
    $page = strtolower(basename(dirname($path)));
    $source .= '
#page-user-'.$page.'{
     @import "'.$path.'";
}';
}

$cache = new scsss_cache('newdesign.css');
// $cache->setQueryParam(NULL);
$cache->serve($source);
