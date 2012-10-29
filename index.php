<?php

(include_once dirname(__FILE__).'/vendor/autoload.php') OR die('ERROR: composer autoloader not found, run "composer install" or see README for instructions'.PHP_EOL);

$source = '@import "newdesign.scss";';
foreach(glob(dirname(__FILE__).'/Page/*/newdesign.scss') as $path){
    $page = strtolower(basename(dirname($path)));
    $source .= '
#page-user-'.$page.'{
     @import "'.$path.'";
}';
}

$cache = new scss_cache($source);
// $cache->setQueryParam(NULL);
$cache->serve();
