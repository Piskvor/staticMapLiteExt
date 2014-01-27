<?php

require_once(__DIR__ . '/staticmapliteex.class.php');

$map = new staticMapLiteEx(array(
                            'request' => $_GET,
                            'headers' => $_SERVER,

                            /* add your own sources as you see fit */
                            'mapSources' => array(
	                            'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',

	                            /* this is an example map source - {Z} is zoom level,
	                            {X} corresponds to longitude (East to West), {Y} corresponds to latitude (North to South)
	                            @see http://wiki.openstreetmap.org/wiki/Slippy_map_tilenames#X_and_Y
	                            */
	                            'example' => 'http://example.com/some/path/{Z}/{X}/{Y}.png',
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
