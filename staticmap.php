<?php

require_once(__DIR__ . '/staticmapliteex.class.php');

$map = new staticMapLiteEx(array(
                            'request' => $_GET,
                            'headers' => $_SERVER,
                            'mapSources' => array(
	                            'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',
	                            'osmarenderer' => 'http://c.tah.openstreetmap.org/Tiles/tile/{Z}/{X}/{Y}.png',
	                            'cycle' => 'http://c.andy.sandbox.cloudmade.com/tiles/cycle/{Z}/{X}/{Y}.png'
                            ),
                            'cache' => array(
	                            'http' => true,
	                            'tile' => true,
	                            'map' => true
                            ),
                            'ua' => 'staticMapLiteEx/0.03'
                           ));
echo $map->showMap();
