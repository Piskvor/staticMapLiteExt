<?php

require_once(__DIR__ . '/staticmapliteex.class.php');

$map = new staticMapLiteEx(array(
                            'request' => $_GET,
                            'headers' => $_SERVER,

                            /* add your own sources as you see fit */
                            'mapSources' => array(
	                            'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',
	                            'osmarenderer' => 'http://c.tah.openstreetmap.org/Tiles/tile/{Z}/{X}/{Y}.png',
                            ),

                            'cache' => array(
	                            'http' => true,
	                            'tile' => true,
	                            'map' => true
                            ),

                            /* user agent to send in map tile requests */
                            'ua' => 'staticMapLiteEx/0.03',

                            /* max and min zoom only needed for "autozoom to markers" */
                            'minZoom' => 12,
                            'maxZoom' => 18
                           ));
echo $map->showMap();
