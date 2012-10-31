# scss-cache

Automatically compile your SCSS (sassy CSS from SASS) to plain CSS whenever they actually change

## Quickstart examples

Once installed (see _Install_), you can serve your SCSS source like this:

````php
<?php
require_once 'vendor/autoload.php';

$scss = '$color: #fff;
body{
  background: darken($color,10%);
  color: invert($color);
}';

$cache = new scss_cache($scss);
$cache->serve();
````

If instead you want to serve your resulting CSS from SCSS files, use:

````php
$cache = scss_cache::file('source.scss');
$cache->serve();
````

Or if you want to get your resulting CSS for further processing:

````php
$scss = '...';

$cache = new scss_cache($scss);
$css = $cache->getOutput();
````

## Install

The recommended way to install this library is [through composer](http://getcomposer.org). [New to composer?](http://getcomposer.org/doc/00-intro.md)

```JSON
{
    "require": {
        "clue/scss-cache": "dev-master"
    }
}
```

