<?php

use PHPatr\Debug;

/**
 * Método para realizar o debug de código
 */
if(!function_exists('debug')){
    function debug($var, $exit = true, $save = false)
    {
        return Debug::dump($var, $exit, $save);
    }
}

if(!function_exists('build_phar')){
	function build_phar($name, array $dirs, $default, $dirSave)
	{
		$app = new Phar($dirSave . $name . '.phar', 0, $name . '.phar');
		$app->startBuffering();
		foreach($dirs as $dir){
			$app->buildFromDirectory($dir, '/\.php|json$/');
		}
		$app->setStub($app->createDefaultStub($default));
		$app->compress(Phar::GZ);
		$app->stopBuffering();
	}
}