<?php

/**
 * staticMapLite 0.03
 * Copyright 2009 Gerhard Koch
 *
 * staticMapLiteEx 0.04
 * Copyright 2013 Jan Martinec
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 * @author Jan "Piskvor" Martinec <staticMapLiteEx AT martinec.name>
 *
 * USAGE:
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=mapnik&markers=40.702147,-74.015794,blues|40.711614,-74.012318,greeng|40.718217,-73.998284,redc
 *
 */

require_once __DIR__.'/staticmapliteexception.class.php';

class StaticMapLiteEx
{

    // most tile servers use 256; don't change this if you are not absolutely certain what it does
    protected $tileSize = 256;

    protected $tileSrcUrl = array(
        // the "usual" OpenStreetMap tiles
        'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png',
    );

    // default User-Agent
    protected $userAgent = 'PHP/staticMapLiteEx 0.04';

    protected $tileDefaultSrc;

    // directory containing markers
    protected $markerBaseDir = 'images/markers';

    // OSM logo overlay
    protected $osmLogo = 'images/osm_logo.png';

    protected $markerPrototypes = array(
        // found at http://www.mapito.net/map-marker-icons.html
        'lighblue' => array(
            'regex' => '/^lightblue([0-9]+)$/',
            'extension' => '.png',
            'shadow' => false,
            'offsetImage' => '0,-19',
            'offsetShadow' => false,
        ),
        // openlayers standard markers
        'ol-marker' => array(
            'regex' => '/^ol-marker(-red|-blue|-gold|-green)?$/',
            'extension' => '.png',
            'transparent' => true,
            'shadow' => '../marker_shadow.png',
            'offsetImage' => '-10,-25',
            'offsetShadow' => '-1,-13',
        ),
        // taken from http://www.visual-case.it/cgi-bin/vc/GMapsIcons.pl
        'ylw' => array(
            'regex' => '/^(pink|purple|red|ltblu|ylw)-pushpin$/',
            'extension' => '.png',
            'shadow' => '../marker_shadow.png',
            'offsetImage' => '-10,-32',
            'offsetShadow' => '-1,-13',
        ),

    );

    // cache tiles instead of always loading from tile servers - cached tiles might get stale
    protected $useTileCache = true;

    // tile cache main directory
    protected $tileCacheBaseDir = 'cache/tiles';

    // cache resulting maps (with markers!) instead of always regenerating from tiles
    protected $useMapCache = true;

    // maps cache main directory
    protected $mapCacheBaseDir = 'cache/maps';

    // cache image in browser, using HTTP caching headers
    protected $useHTTPCache = true;

    /**
     * @var int
     * Number of days to keep image as fresh, via Expires header
     */
    protected $expireDays = 14;

    protected $scale = 1;

    protected $format = 'png';

    protected $supportedFormats = array(
        'png' => 'png',
        'jpeg' => 'jpg',
        'gif' => 'gif',
    );

    protected $mapCacheID = '';

    protected $mapCacheFile = '';

    // currently the only supported filetype is PNG; .png is its usual file extension
    protected $mapCacheExtension = 'png';

    protected $zoom; // see http://wiki.openstreetmap.org/wiki/Zoom_levels

    protected $lat;

    protected $lon;

    protected $width;

    protected $height;

    /**
     * @var resource
     */
    protected $image;

    protected $maptype;

    protected $markers;

    protected $markerBox;

    protected $minZoom;

    protected $maxZoom;

    protected $centerX;

    protected $centerY;

    protected $offsetX;

    protected $offsetY;

    protected $request;

    protected $requestHeaders;

    /**
     * @param array|null $config
     * @throws StaticMapLiteException
     */
    public function __construct($config = null)
    {
        // "no config, just give me the defaults"
        if (! $config) {
            $config = array();
        }
        $this->checkDependencies();

        if (! array_key_exists('request', $config)) {
            $config['request'] = $_GET;
        }
        if (! array_key_exists('requestHeaders', $config)) {
            $config['requestHeaders'] = $_SERVER;
        }
        $this->setDefaults();
        $this->configure($config);

    }

    public function parseParams()
    {
        $this->determineMarkers();
        $this->determineFormat();
        $this->determinePixelSize();
        $this->determineCenter();
        $this->determineZoom();
        $this->determineMapType();
        $this->mapCacheID = md5($this->serializeParams());
    }

    protected function getCenterFromMarkers($markerBox, $width, $height, $maxZoom, $minZoom)
    {
        /*
        // DEBUG: uncomment the above to show marker box on map
        $this->markers[] = array(
            'lat' => $markerBox['lat']['center'],
            'lon' => $markerBox['lon']['center'],
            'image' => 'ol-marker-green',
        );

        $this->markers[] = array(
            'lat' => $markerBox['lat']['max'],
            'lon' => $markerBox['lon']['max'],
            'image' => 'ol-marker-green',
        );

        $this->markers[] = array(
            'lat' => $markerBox['lat']['min'],
            'lon' => $markerBox['lon']['min'],
            'image' => 'ol-marker-green',
        );
        // */

        // start from $maxZoom and work outwards from there
        $zoom = $maxZoom;
        // for latitude, we need to correct (otherwise calculation would be correct on the equator only)
        // see http://wiki.openstreetmap.org/wiki/File:Tissot_mercator.png
        $latCorrection = 360 * cos(deg2rad($this->markerBox['lat']['center']));

        for (; $zoom >= $minZoom; $zoom--) {
            // how many degrees wide is the image? - longitude doesn't need correction
            $degreesWidth = $width * 360 / (pow(2, ($zoom + 8)));

            // how many degrees high is the image? - apply the latitude correction from above
            $latDegreesPerPixel = $latCorrection / (pow(2, ($zoom + 8)));
            $degreesHeight = $latDegreesPerPixel * $height;

            /*
            // DEBUG: uncomment the above to show zoom levels on map
            $this->markers[] = array(
                'lat' => $markerBox['lat']['center'] + $degreesHeight,
                'lon' => $markerBox['lon']['center'] + $degreesWidth,
                'image' => 'ol-marker-gold',
            );
            $this->markers[] = array(
                'lat' => $markerBox['lat']['center'] - $degreesHeight,
                'lon' => $markerBox['lon']['center'] - $degreesWidth,
                'image' => 'ol-marker-gold',
            );
            // */

            if ($degreesWidth >= $markerBox['lon']['size'] && $degreesHeight >= $markerBox['lat']['size']) {
                // in this case, all markers will fit into the current zoom
                break;
            }
        }

        return array($markerBox['lat']['center'], $markerBox['lon']['center'], $zoom);
    }

    public function lonToTile($long, $zoom)
    {
        return (($long + 180) / 360) * pow(2, $zoom);
    }

    public function latToTile($lat, $zoom)
    {
        return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
    }

    public function initCoords()
    {
        $this->centerX = $this->lonToTile($this->lon, $this->zoom);
        $this->centerY = $this->latToTile($this->lat, $this->zoom);
        $this->offsetX = floor((floor($this->centerX) - $this->centerX) * $this->tileSize);
        $this->offsetY = floor((floor($this->centerY) - $this->centerY) * $this->tileSize);
    }

    public function renderBaseMap()
    {
        $image = imagecreatetruecolor($this->width, $this->height);
        if (! $image) {
            return;
        }
        $this->image = $image;
        $startX = floor($this->centerX - ($this->width / $this->tileSize) / 2);
        $startY = floor($this->centerY - ($this->height / $this->tileSize) / 2);
        $endX = ceil($this->centerX + ($this->width / $this->tileSize) / 2);
        $endY = ceil($this->centerY + ($this->height / $this->tileSize) / 2);
        $this->offsetX = -floor(($this->centerX - floor($this->centerX)) * $this->tileSize);
        $this->offsetY = -floor(($this->centerY - floor($this->centerY)) * $this->tileSize);
        $this->offsetX += floor($this->width / 2);
        $this->offsetY += floor($this->height / 2);
        $this->offsetX += floor($startX - floor($this->centerX)) * $this->tileSize;
        $this->offsetY += floor($startY - floor($this->centerY)) * $this->tileSize;

        for ($x = $startX; $x <= $endX; $x++) {
            for ($y = $startY; $y <= $endY; $y++) {
                $url = str_replace(
                    array('{Z}', '{X}', '{Y}'),
                    array($this->zoom, $x, $y),
                    $this->tileSrcUrl[$this->maptype]
                );
                $tileData = $this->fetchTile($url);
                if ($tileData) {
                    $tileImage = imagecreatefromstring($tileData);
                } else {
                    $tileImage = $this->fetchErrorTile();
                }
                if (! $tileImage) {
                    continue;
                }
                $destX = ($x - $startX) * $this->tileSize + $this->offsetX;
                $destY = ($y - $startY) * $this->tileSize + $this->offsetY;
                imagecopy($this->image, $tileImage, (int)$destX, (int)$destY, 0, 0, $this->tileSize, $this->tileSize);
            }
        }
    }


    public function renderMarkers()
    {
        $markerIndex = 0; // used for auto-numbering markers

        foreach ($this->markers as $marker) {
            $markerLat = $marker['lat'];
            $markerLon = $marker['lon'];
            $markerType = $marker['type'];
            $markerTransparency = $marker['transparent'];
            // clear variables from previous loops
            $markerFilename = '';
            $markerShadow = '';

            $markerImageOffsetX = 0;
            $markerImageOffsetY = 0;
            $markerShadowOffsetX = 0;
            $markerShadowOffsetY = 0;
            // check for marker type, get settings from markerPrototypes
            if ($markerType) {
                list($markerFilename, $markerImageOffsetX, $markerImageOffsetY, $markerShadow, $markerShadowOffsetX, $markerShadowOffsetY) = $this->getMarkerImageOptions(
                    $markerType,
                    $markerTransparency,
                    $markerFilename,
                    $markerImageOffsetX,
                    $markerImageOffsetY,
                    $markerShadow,
                    $markerShadowOffsetX,
                    $markerShadowOffsetY
                );
            }

            // check required files or set default
            if ($markerFilename == '' || ! file_exists($this->markerBaseDir.'/'.$markerFilename)) {
                $markerIndex++;
                $markerFilename = 'lightblue'.$markerIndex.'.png'; // auto-number markers
                $markerImageOffsetX = 0;
                $markerImageOffsetY = -19;
            }
            $markerImg = $this->getMarkerImageResource($markerFilename);

            if (! $markerImg) {
                continue;
            }

            // calculate pixel position from geographical location
            $destX = floor(
                ($this->width / 2) - $this->tileSize * ($this->centerX - $this->lonToTile($markerLon, $this->zoom))
            );
            $destY = floor(
                ($this->height / 2) - $this->tileSize * ($this->centerY - $this->latToTile($markerLat, $this->zoom))
            );

            // check for shadow + create shadow resource
            if ($markerShadow && file_exists($this->markerBaseDir.'/'.$markerShadow)) {
                $markerShadowImg = imagecreatefrompng($this->markerBaseDir.'/'.$markerShadow);
                if ($markerShadowImg) {
                    imagecopy(
                        $this->image,
                        $markerShadowImg,
                        (int)($destX + intval($markerShadowOffsetX)),
                        (int)($destY + intval($markerShadowOffsetY)),
                        0,
                        0,
                        imagesx($markerShadowImg),
                        imagesy($markerShadowImg)
                    );
                }
            }

            // copy marker to basemap above shadow
            imagecopy(
                $this->image,
                $markerImg,
                (int)($destX + intval($markerImageOffsetX)),
                (int)($destY + intval($markerImageOffsetY)),
                0,
                0,
                imagesx($markerImg),
                imagesy($markerImg)
            );
        };
    }

    public function tileUrlToFilename($url)
    {
        return $this->tileCacheBaseDir."/".preg_replace(array('~^https?://~i'), '', $url);
    }

    public function checkTileCache($url)
    {
        $filename = $this->tileUrlToFilename($url);
        if (file_exists($filename)) {
            return file_get_contents($filename);
        } else {
            return '';
        }
    }

    public function checkMapCache()
    {
        $filename = $this->mapCacheIDToFilename();

        return (file_exists($filename));
    }

    public function serializeParams()
    {
        return join(
            "&",
            array(
                $this->zoom,
                $this->lat,
                $this->lon,
                $this->width,
                $this->height,
                serialize($this->markers),
                $this->maptype,
                $this->scale,
                $this->format,
            )
        );
    }

    public function mapCacheIDToFilename()
    {
        if (! $this->mapCacheFile) {
            $this->mapCacheFile = $this->mapCacheBaseDir."/".$this->maptype."/".$this->zoom."/cache_".substr(
                    $this->mapCacheID,
                    0,
                    2
                )."/".substr($this->mapCacheID, 2, 2)."/".substr($this->mapCacheID, 4);
        }

        return $this->mapCacheFile.".".$this->mapCacheExtension;
    }

    public function mkdirRecursive($pathname, $mode)
    {
        is_dir(dirname($pathname)) || $this->mkdirRecursive(dirname($pathname), $mode);

        return is_dir($pathname) || @mkdir($pathname, $mode);
    }

    public function writeTileToCache($url, $data)
    {
        $filename = $this->tileUrlToFilename($url);
        $this->mkdirRecursive(dirname($filename), 0777);
        file_put_contents($filename, $data);
    }

    public function fetchTile($url)
    {
        if ($this->useTileCache && ($cached = $this->checkTileCache($url))) {
            return $cached;
        }
        $curlHandle = curl_init();
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 5); // time out faster
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10); // time out faster - but not too fast
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlHandle, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($curlHandle, CURLOPT_URL, $url);
        $tile = curl_exec($curlHandle);
        curl_close($curlHandle);
        if ($tile && $this->useTileCache) { // cache if result
            $this->writeTileToCache($url, $tile);
        }

        return $tile;
    }

    public function checkCurlFunctions()
    {
        return function_exists('curl_init')
            && function_exists('curl_setopt')
            && function_exists('curl_exec')
            && function_exists('curl_close');
    }

    public function checkGdFunctions()
    {
        return function_exists('imagecreatefrompng')
            && function_exists('imagecopy')
            && function_exists('imagepng');
    }

    // OSM requires attribution, use the predefined logo
    public function renderCopyrightNotice()
    {
        // add OSM logo
        $logoImg = imagecreatefrompng($this->osmLogo);
        if ($logoImg) {
            imagecopy(
                $this->image,
                $logoImg,
                (int)(imagesx($this->image) - imagesx($logoImg)),
                (int)(imagesy($this->image) - imagesy($logoImg)),
                0,
                0,
                imagesx($logoImg),
                imagesy($logoImg)
            );
        }
    }

    public function sendHeader($filename = null, $etag = null)
    {
        header('Content-Type: image/'.$this->format); // it's an image
        header("Pragma: public"); // ancient IE hack

        if ($this->useHTTPCache) {
            $expires = (60 * 60 * 24) * $this->expireDays;
            header("Cache-Control: maxage=".$expires);
            header('Expires: '.gmdate('D, d M Y H:i:s', time() + $expires).' GMT');
            if ($filename != null && file_exists($filename)) {
                $modifiedTime = filemtime($filename);
                if ($modifiedTime) {
                    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $modifiedTime).' GMT');
                }
            }
            if ($etag != null) {
                header('ETag: '.$etag);
            }
        }
    }

    public function makeMap()
    {
        $this->initCoords();
        $this->renderBaseMap();
        if (count($this->markers)) {
            $this->renderMarkers();
        }
        if ($this->osmLogo) {
            $this->renderCopyrightNotice();
        }
    }

    public function showMap()
    {
        // "same ETag" means "same parameters" (a hash collision is unlikely under these circumstances)
        $etag = $this->mapCacheID;

        // if we have sent an ETag to the browser previously, this is how we get it back
        if ($this->useHTTPCache && array_key_exists('HTTP_IF_NONE_MATCH', $this->requestHeaders)) {
            if ($etag == $this->requestHeaders['HTTP_IF_NONE_MATCH']) {
                // No changes, don't send anything - the browser already has it.
                header('HTTP/1.1 304 Not Modified');

                return '';
            }
        }
        $this->parseParams();
        if ($this->useMapCache) {
            return $this->showCachedMap($etag);
        } else {
            // no cache, make map, send headers and deliver png
            $this->makeMap();
            $this->sendHeader(null, $etag);

            return $this->applyOutputFilters($this->image);
        }
    }

    protected function applyOutputFilters($image_orig, $filename = null, $quality = null, $filters = null)
    {
        // scale if required
        if ($this->scale != 1) {
            $width = $this->scale * $this->width;
            $height = $this->scale * $this->height;
            $image = imagecreatetruecolor($width, $height);
            if (! $image) {
                return false;
            }
            imagecopyresampled($image, $image_orig, 0, 0, 0, 0, $width, $height, $this->width, $this->height);
        } else {
            $image = $image_orig;
        }
        unset($image_orig);

        // apply the required output format
        if ($this->format == 'jpeg') {
            // quality is PNG-derived (0-9), convert to something JPEG-worthy
            return imagejpeg($image, $filename, (int)(130 - ($quality * 10)));
        } else {
            if ($this->format == 'gif') {
                return imagegif($image, $filename);
            } else {
                return imagepng($image, $filename, $quality, $filters);
            }
        }
    }

    /**
     * @param array $config
     */
    private function configure($config)
    {
        $this->request = $config['request']; // this is usually $_GET
        $this->requestHeaders = $config['headers']; // this is usually $_SERVER

        // set map sources
        if (array_key_exists('mapSources', $config)) {
            $this->tileSrcUrl = $config['mapSources'];
        }
        // set User-Agent
        if (array_key_exists('ua', $config)) {
            $this->userAgent = $config['ua'];
        }

        // min/max zoom to use for auto-zooming
        if (array_key_exists('minZoom', $config)) {
            $this->minZoom = $config['minZoom'];
        } else {
            $this->minZoom = 1;
        }
        if (array_key_exists('maxZoom', $config)) {
            $this->maxZoom = $config['maxZoom'];
        } else {
            $this->maxZoom = 18;
        }
        // configure various caching options
        if (array_key_exists('cache', $config)) {
            $cache = $config['cache'];
            if (array_key_exists('http', $cache)) {
                $this->useHTTPCache = (boolean)$cache['http'];
            }
            if (array_key_exists('tile', $cache)) {
                $this->useTileCache = (boolean)$cache['tile'];
            }
            if (array_key_exists('map', $cache)) {
                $this->useMapCache = (boolean)$cache['map'];
            }
        }

        // set the first source to be the default
        $sources = array_keys($this->tileSrcUrl);
        $this->tileDefaultSrc = $sources[0];
        $this->maptype = $this->tileDefaultSrc;
    }

    private function setDefaults()
    {
        $this->zoom = 0;
        $this->lat = 0;
        $this->lon = 0;
        $this->width = 500;
        $this->height = 350;
        $this->markers = array();
    }

    /**
     * @throws StaticMapLiteException
     */
    private function checkDependencies()
    {
        // bail if we can't fetch HTTP resources
        if (! $this->checkCurlFunctions()) {
            throw new StaticMapLiteException('Required library not loaded: curl');
        }
        // bail if we can't work with images
        if (! $this->checkGdFunctions()) {
            throw new StaticMapLiteException('Required library not loaded: gd');
        }
    }

    /**
     * @param string $queryString
     * @param array $defaultSet
     * @return array|mixed
     */
    private function parseMarkers($queryString = '', $defaultSet = array())
    {
        /*
         *  using multiple keys with the same name in query string is permitted, but regrettable:
         *  we will only get the last one into $_GET
         *  so we need to parse QS manually
         *  (we need to follow this convention to have drop-in compatibility with GMaps' generator)
         */
        // first, a quick check
        $markersPosition1 = strpos(
            $queryString,
            'markers'
        ); // there needs to be one at least
        $markersPosition2 = strpos($queryString, 'markers', $markersPosition1 + 1);

        // if we have $markersPosition2, it means that we need to parse for multiple "marker=foobar" locations in QS
        $keyValuePairs = array();
        if ($markersPosition2 !== false) {
            $qsParts = explode('&', $queryString);
            foreach ($qsParts as $qsp) {
                list($key, $value) = explode('=', $qsp);
                if (! array_key_exists($key, $keyValuePairs)) {
                    $keyValuePairs[$key] = array();
                }
                $keyValuePairs[$key][] = $value;
            }
        }
        if (count($keyValuePairs) > 0 && count($keyValuePairs['markers']) > 1) {
            // multiple sets of markers
            $markerSets = $keyValuePairs['markers'];
        } else {
            // one set only, use the default from request
            $markerSets = array($defaultSet);
        }

        return $markerSets;
    }

    /**
     * @param array $markerSets
     * @return array
     */
    private function getBoundingBox($markerSets = array())
    {
        $markerBox = array(
            'lat' => array(
                'min' => PHP_INT_MAX,
                'max' => -PHP_INT_MAX,
            ),
            'lon' => array(
                'min' => PHP_INT_MAX,
                'max' => -PHP_INT_MAX,
            ),
        );

        foreach ($markerSets as $markerSet) {
            $markers = preg_split('/%7C|\|/', $markerSet);
            if (! $markers) {
                continue;
            }

            foreach ($markers as $marker) {
                $this->adjustMarkerBoxBounds($marker, $markerBox);
            }
        }
        // these are useful for determining auto-zoom
        $markerBox['lat']['center'] = ($markerBox['lat']['min'] + $markerBox['lat']['max']) / 2;
        $markerBox['lon']['center'] = ($markerBox['lon']['min'] + $markerBox['lon']['max']) / 2;
        $markerBox['lat']['size'] = $markerBox['lat']['max'] - $markerBox['lat']['min'];
        $markerBox['lon']['size'] = $markerBox['lon']['max'] - $markerBox['lon']['min'];

        return $markerBox;
    }

    /**
     * @param string $marker
     * @param array $markerBox
     */
    private function adjustMarkerBoxBounds($marker, array &$markerBox)
    {
        list($markerLat, $markerLon) = preg_split('/,|%2C/', $marker);
        $markerLat = floatval($markerLat);
        $markerLon = floatval($markerLon);
        if (($markerLat === $markerLon) && ($markerLat === 0.0)) {
            // bogus marker
            return;
        }
        // get minimum/maximum for all the markers
        if ($markerBox['lat']['min'] > $markerLat) {
            $markerBox['lat']['min'] = $markerLat;
        }
        if ($markerBox['lat']['max'] < $markerLat) {
            $markerBox['lat']['max'] = $markerLat;
        }
        if ($markerBox['lon']['min'] > $markerLon) {
            $markerBox['lon']['min'] = $markerLon;
        }
        if ($markerBox['lon']['max'] < $markerLon) {
            $markerBox['lon']['max'] = $markerLon;
        }
    }

    /**
     * @param string $marker
     * @param array $markerSetDisplay
     * @return array
     */
    private function getMarkerData($marker, array &$markerSetDisplay)
    {
        list($markerLat, $markerLon, $markerImage) = preg_split('/,|%2C/', $marker);
        $markerLat = floatval($markerLat);
        $markerLon = floatval($markerLon);
        if (($markerLat === $markerLon) && ($markerLat === 0.0)) {
            // this is not a marker at all, this sets other params (size/letter/color)
            list($param, $paramValue) = preg_split('/:|%3A/', $marker);
            if ($param && array_key_exists($param, $markerSetDisplay)) {
                $markerSetDisplay[$param] = $paramValue;
            };

            return array(null, null);
        }

        if ($markerImage) {
            $markerImage = basename($markerImage);
        }
        // set basic data
        $markerData = array(
            'lat' => $markerLat,
            'lon' => $markerLon,
            'type' => $markerImage,
        );
        // set data parsed from parameters
        if (! $markerImage) {
            $this->setMarkerDisplay($markerSetDisplay, $markerData);
        }

        // fixes the N/S and W/E marker overlap issues
        $markerKey = str_pad(
                str_pad((string)$markerLat, 11, '0', STR_PAD_RIGHT),
                12,
                '0',
                STR_PAD_LEFT
            ).(180 - $markerLon).$markerImage;

        return array($markerData, $markerKey);
    }

    /**
     * @param array $markerSetDisplay
     * @param array $markerData
     */
    private function setMarkerDisplay(array &$markerSetDisplay, array &$markerData)
    {
        if ($markerSetDisplay['color']) {
            $markerPrototype = $this->markerPrototypes['ol-marker'];
            $matches = array();
            if (preg_match($markerPrototype['regex'], 'ol-marker-'.$markerSetDisplay['color'], $matches)) {
                $markerData['type'] = $matches[0];
            }
            unset($matches);
        } else {
            if (@$this->request['visual_refresh']) {
                // use the default ol-marker for all non-claimed markers
                $markerData['type'] = 'ol-marker';
            }
        }
        if ($markerSetDisplay['transparent']) {
            $markerData['transparent'] = $markerSetDisplay['transparent'];
        }
    }

    private function determineCenter()
    {
        if (@$this->request['center']) {
            // get lat and lon from request
            list($this->lat, $this->lon) = explode(',', $this->request['center']);
            $this->lat = floatval($this->lat);
            $this->lon = floatval($this->lon);
        } else {
            if (count(
                    $this->markers
                ) && $this->minZoom !== null && $this->maxZoom !== null && $this->minZoom <= $this->maxZoom) {
                // if we have markers but not center, find the center and zoom from marker position(s)
                list($this->lat, $this->lon, $this->zoom) = $this->getCenterFromMarkers(
                    $this->markerBox,
                    $this->width - 20,
                    $this->height - 20,
                    $this->maxZoom,
                    $this->minZoom
                );
            }
        }
    }

    private function determineFormat()
    {
        if (@$this->request['format']) {
            $format = strtolower($this->request['format']);
            if ($format == 'jpg') {
                $format = 'jpeg';
            }
            if (array_key_exists($format, $this->supportedFormats)) {
                $this->format = $format;
                $this->mapCacheExtension = $this->supportedFormats[$format];
            }
        }
    }

    private function determinePixelSize()
    {
        // get size from request
        if (@$this->request['size']) {
            list($this->width, $this->height) = explode('x', $this->request['size']);
            $this->width = intval($this->width);
            $this->height = intval($this->height);
        }
        if (@$this->request['scale'] && ($this->request['scale'] == 2 || $this->request['scale'] == 4)) {
            $this->scale = (int)$this->request['scale'];
        }
    }

    private function determineZoom()
    {
        // get zoom from request
        $this->zoom = @$this->request['zoom'] ? intval($this->request['zoom']) : $this->zoom;
        // set maximum zoom
        if ($this->zoom > 18) {
            $this->zoom = 18;
        }
    }

    /**
     * @param string $markerType
     * @param bool $markerTransparency
     * @param string $markerFilename
     * @param int $markerImageOffsetX
     * @param int $markerImageOffsetY
     * @param string $markerShadow
     * @param int $markerShadowOffsetX
     * @param int $markerShadowOffsetY
     * @return array
     */
    private function getMarkerImageOptions(
        $markerType,
        $markerTransparency,
        $markerFilename,
        $markerImageOffsetX,
        $markerImageOffsetY,
        $markerShadow,
        $markerShadowOffsetX,
        $markerShadowOffsetY
    ) {
        foreach ($this->markerPrototypes as $markerPrototype) {
            $matches = array();
            if (preg_match($markerPrototype['regex'], $markerType, $matches)) {
                if ($markerTransparency && $markerPrototype['transparent']) {
                    // only if transparency requested and available
                    $markerFilename = $matches[0].'-transparent'.$markerPrototype['extension'];
                } else {
                    $markerFilename = $matches[0].$markerPrototype['extension'];
                }
                if ($markerPrototype['offsetImage']) {
                    list($markerImageOffsetX, $markerImageOffsetY) = explode(
                        ",",
                        $markerPrototype['offsetImage']
                    );
                }
                $markerShadow = $markerPrototype['shadow'];
                if ($markerShadow) {
                    list($markerShadowOffsetX, $markerShadowOffsetY) = explode(
                        ",",
                        $markerPrototype['offsetShadow']
                    );
                }
            }
        }

        return array(
            $markerFilename,
            $markerImageOffsetX,
            $markerImageOffsetY,
            $markerShadow,
            $markerShadowOffsetX,
            $markerShadowOffsetY,
        );
    }

    /**
     * @param string $markerFilename
     * @return false|resource
     */
    private function getMarkerImageResource($markerFilename)
    {
        // create img resource
        if (file_exists($this->markerBaseDir.'/'.$markerFilename)) {
            $markerImg = imagecreatefrompng($this->markerBaseDir.'/'.$markerFilename);
        } else {
            $markerImg = imagecreatefrompng($this->markerBaseDir.'/lightblue1.png');
        }

        return $markerImg;
    }

    /**
     * @param string $etag
     * @return bool|false|string
     */
    private function showCachedMap($etag = null)
    {
        // use map cache, so check cache for map
        if (! $this->checkMapCache()) {
            // map is not in cache, needs to be built..
            $this->makeMap();
            // ...and stored to disk, if possible
            $this->mkdirRecursive(dirname($this->mapCacheIDToFilename()), 0777);
            $this->applyOutputFilters($this->image, $this->mapCacheIDToFilename(), 9);
            if (file_exists($this->mapCacheIDToFilename())) {
                // we have a file, so we can check for its modification date later; but we also send the ETag
                $this->sendHeader($this->mapCacheIDToFilename(), $etag);

                return file_get_contents($this->mapCacheIDToFilename());
            } else {
                // map is not stored in disk cache, so we only send the ETag
                $this->sendHeader(null, $etag);

                return $this->applyOutputFilters($this->image);
            }
        } else {
            // map is in our disk cache
            if ($this->useHTTPCache && array_key_exists('HTTP_IF_MODIFIED_SINCE', $this->requestHeaders)) {
                $request_time = strtotime($this->requestHeaders['HTTP_IF_MODIFIED_SINCE']);
                $file_time = filemtime($this->mapCacheIDToFilename());
                if ($request_time >= $file_time) {
                    // the map is already in browser's cache, we don't need to send anything
                    header('HTTP/1.1 304 Not Modified');

                    return '';
                }
            }
            // we have a file, so we can check for its modification date later; but we also send the ETag
            $this->sendHeader($this->mapCacheIDToFilename(), $etag);

            return file_get_contents($this->mapCacheIDToFilename());
        }
    }

    private function determineMapType()
    {
        // set map type
        if (@$this->request['maptype']) {
            if (array_key_exists($this->request['maptype'], $this->tileSrcUrl)) {
                $this->maptype = $this->request['maptype'];
            }
        }
    }

    private function determineMarkers()
    {
        // get markers
        if (@$this->request['markers']) {
            $markerSets = $this->parseMarkers(
                @$this->requestHeaders['QUERY_STRING'],
                $this->request['markers']
            );

            $this->markerBox = $this->getBoundingBox($markerSets);

            foreach ($markerSets as $markerSet) {
                $markers = preg_split('/%7C|\|/', $markerSet);
                if (! $markers) {
                    continue;
                }

                // reset between marker sets
                $markerSetDisplay = array(
                    'color' => null,
                    'transparent' => false,
                    'size' => null,
                    'letter' => null,
                );
                // from now on, we can pretend there was always just one set of markers.
                foreach ($markers as $marker) {
                    list($markerKey, $markerData) = $this->getMarkerData(
                        $marker,
                        $markerSetDisplay
                    );
                    if ($markerKey) {
                        $this->markers[$markerKey] = $markerData;
                    }
                }
            }
            // together with the array keys, this ensures that southernmost keys are the last (therefore on top)
            krsort($this->markers);
            //var_dump($this->markers);
        }
    }

    /**
     * @return false|resource
     */
    private function fetchErrorTile()
    {
        $tileImage = imagecreate($this->tileSize, $this->tileSize);
        if ($tileImage) {
            $color = imagecolorallocate($tileImage, 255, 255, 255);
            @imagestring($tileImage, 1, 127, 127, 'err', $color);
        }

        return $tileImage;
    }
}
