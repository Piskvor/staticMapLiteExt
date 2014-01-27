<?php

class staticMapLiteEx {

	protected $tileSize = 256;
	protected $tileSrcUrl = array(
		'mapnik' => 'http://tile.openstreetmap.org/{Z}/{X}/{Y}.png'
	);

	protected $ua = 'PHP/staticMapLiteEx 0.03';
	
	protected $tileDefaultSrc = 'mapnik';
	protected $markerBaseDir = 'images/markers';
	protected $osmLogo = 'images/osm_logo.png';

	protected $useTileCache = true; // cache tiles instead of always loading from tile servers
	protected $tileCacheBaseDir = 'cache/tiles';

	protected $useMapCache = true; // cache resulting maps instead of always regenerating
	protected $mapCacheBaseDir = 'cache/maps';

	protected $useHTTPCache = true; // cache image in browser, using HTTP caching headers
	protected $expireDays = 14;

	protected $mapCacheID = '';
	protected $mapCacheFile = '';
	protected $mapCacheExtension = 'png';
	
	protected $zoom, $lat, $lon, $width, $height, $image, $maptype;

	protected $markers;
	protected $centerX, $centerY, $offsetX, $offsetY;

	public function __construct($config){
		$this->zoom = 0;
		$this->lat = 0;
		$this->lon = 0;
		$this->width = 500;
		$this->height = 350;
		$this->markers = array();
		$this->maptype = $this->tileDefaultSrc;
		$this->request = $config['request'];
		$this->requestHeaders = $config['headers'];
		if (array_key_exists('mapSources',$config)) {
			$this->tileSrcUrl = $config['mapSources'];
		}
		if (array_key_exists('ua',$config)) {
			$this->ua = $config['ua'];
		}
		if (array_key_exists('cache',$config)) {
			$cache = $config['cache'];
			if (array_key_exists('http',$cache)) {
				$this->useHTTPCache = (boolean) $cache['http'];
			}
			if (array_key_exists('tile',$cache)) {
				$this->useTileCache = (boolean) $cache['tile'];
			}
			if (array_key_exists('map',$cache)) {
				$this->useMapCache = (boolean) $cache['map'];
			}
		}
	}
	
	public function parseParams(){

		// get zoom from request
		$this->zoom = @$this->request['zoom']?intval($this->request['zoom']):0;
		if($this->zoom>18)$this->zoom = 18;
		
		// get lat and lon from request
		list($this->lat,$this->lon) = explode(',',$this->request['center']);
		$this->lat = floatval($this->lat);
		$this->lon = floatval($this->lon);
		
		// get zoom from request
		if(@$this->request['size']){
			list($this->width, $this->height) = explode('x',$this->request['size']);
			$this->width = intval($this->width);
			$this->height = intval($this->height);
		}
		if(@$this->request['markers']){
			$markers = preg_split('/%7C|\|/',$this->request['markers']);
			foreach($markers as $marker){
				list($markerLat, $markerLon, $markerImage) = explode(',',$marker);
				$markerLat = floatval($markerLat);
				$markerLon = floatval($markerLon);
				$markerImage = basename($markerImage);
				$this->markers[$markerLat . $markerLon .$markerImage] = array(
					'lat'=>$markerLat,
					'lon'=>$markerLon,
					'image'=>$markerImage
				);
			}
			krsort($this->markers);
		}
		if(@$this->request['maptype']){
			if(array_key_exists($this->request['maptype'],$this->tileSrcUrl)) $this->maptype = $this->request['maptype'];
		}
	}

	public function lonToTile($long, $zoom){
		return (($long + 180) / 360) * pow(2, $zoom);
	}

	public function latToTile($lat, $zoom){
		return (1 - log(tan($lat * pi()/180) + 1 / cos($lat* pi()/180)) / pi()) /2 * pow(2, $zoom);
	}

	public function initCoords(){
		$this->centerX = $this->lonToTile($this->lon, $this->zoom);
		$this->centerY = $this->latToTile($this->lat, $this->zoom);
		$this->offsetX = floor((floor($this->centerX)-$this->centerX)*$this->tileSize);
		$this->offsetY = floor((floor($this->centerY)-$this->centerY)*$this->tileSize);
	}

	public function createBaseMap(){
		$this->image = imagecreatetruecolor($this->width, $this->height);
		$startX = floor($this->centerX-($this->width/$this->tileSize)/2);
		$startY = floor($this->centerY-($this->height/$this->tileSize)/2);
		$endX = ceil($this->centerX+($this->width/$this->tileSize)/2);
		$endY = ceil($this->centerY+($this->height/$this->tileSize)/2);
		$this->offsetX = -floor(($this->centerX-floor($this->centerX))*$this->tileSize);
		$this->offsetY = -floor(($this->centerY-floor($this->centerY))*$this->tileSize);
		$this->offsetX += floor($this->width/2);
		$this->offsetY += floor($this->height/2);
		$this->offsetX += floor($startX-floor($this->centerX))*$this->tileSize;
		$this->offsetY += floor($startY-floor($this->centerY))*$this->tileSize;

		for($x=$startX; $x<=$endX; $x++){
			for($y=$startY; $y<=$endY; $y++){
				$url = str_replace(array('{Z}','{X}','{Y}'),array($this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
				$tileImage = imagecreatefromstring($this->fetchTile($url));
				$destX = ($x-$startX)*$this->tileSize+$this->offsetX;
				$destY = ($y-$startY)*$this->tileSize+$this->offsetY;
				imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
			}
		}
	}


	public function placeMarkers(){
		$markerIndex = 0;
		foreach($this->markers as $marker){
			$markerLat = $marker['lat'];
			$markerLon = $marker['lon'];
			$markerImage = $marker['image'];
			$markerIndex++;
			$markerFilename = $markerImage?(file_exists($this->markerBaseDir.'/'.$markerImage.".png")?$markerImage:'lightblue'.$markerIndex):'lightblue'.$markerIndex;
			if(file_exists($this->markerBaseDir.'/'.$markerFilename.".png")){
				$markerImg = imagecreatefrompng($this->markerBaseDir.'/'.$markerFilename.".png");
			} else {
				$markerImg = imagecreatefrompng($this->markerBaseDir.'/lightblue1.png');				
			}
			$destX = floor(($this->width/2)-$this->tileSize*($this->centerX-$this->lonToTile($markerLon, $this->zoom)));
			$destY = floor(($this->height/2)-$this->tileSize*($this->centerY-$this->latToTile($markerLat, $this->zoom)));
			$destY = $destY - imagesy($markerImg);
			$destX = $destX - (imagesx($markerImg) / 2);

			imagecopy($this->image, $markerImg, $destX, $destY, 0, 0, imagesx($markerImg), imagesy($markerImg));
		
	};
}



	public function tileUrlToFilename($url){
		return $this->tileCacheBaseDir."/".str_replace(array('http://'),'',$url);
	}

	public function checkTileCache($url){
		$filename = $this->tileUrlToFilename($url);
		if(file_exists($filename)){
			return file_get_contents($filename);
		} else {
			return '';
		}
	}
	
	public function checkMapCache(){
		$this->mapCacheID = md5($this->serializeParams());
		$filename = $this->mapCacheIDToFilename();
		return (file_exists($filename));
	}

	public function serializeParams(){		
		return join("&",array($this->zoom,$this->lat,$this->lon,$this->width,$this->height, serialize($this->markers),$this->maptype));
	}
	
	public function mapCacheIDToFilename(){
		if(!$this->mapCacheFile){
			$this->mapCacheFile = $this->mapCacheBaseDir."/".substr($this->mapCacheID,0,2)."/".substr($this->mapCacheID,2,2)."/".substr($this->mapCacheID,4);
		}
		return $this->mapCacheFile.".".$this->mapCacheExtension;
	}


	
	public function mkdir_recursive($pathname, $mode){
		is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);
		return is_dir($pathname) || @mkdir($pathname, $mode);
	}
	public function writeTileToCache($url, $data){
		$filename = $this->tileUrlToFilename($url);
		$this->mkdir_recursive(dirname($filename),0777);
		file_put_contents($filename, $data);
	}
	
	public function fetchTile($url){
		if($this->useTileCache && ($cached = $this->checkTileCache($url))) return $cached;
		$ch = curl_init(); 
		curl_setopt($ch, CURLOPT_TIMEOUT, 5); // time out faster
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // time out faster
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, $this->ua);
		curl_setopt($ch, CURLOPT_URL, $url); 
		$tile = curl_exec($ch); 
		curl_close($ch); 
		if($this->useTileCache){
			$this->writeTileToCache($url,$tile);
		}
		return $tile;

	}

	public function copyrightNotice(){
			$logoImg = imagecreatefrompng($this->osmLogo);
			imagecopy($this->image, $logoImg, imagesx($this->image)-imagesx($logoImg), imagesy($this->image)-imagesy($logoImg), 0, 0, imagesx($logoImg), imagesy($logoImg));
		
	}
	
	public function sendHeader($fname = null,$etag = null){
		header('Content-Type: image/png');
		$expires = (60*60*24)*$this->expireDays;
		header("Pragma: public");
		if ($this->useHTTPCache) {
			header("Cache-Control: maxage=".$expires);
			header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
			if ($fname != null && file_exists($fname)) {
				header('Last-Modified: ' . gmdate('D, d M Y H:i:s',filemtime($fname)) . ' GMT');
			}
			if ($etag != null) {
				header('ETag: ' . $etag);
			}
		}
	}

	public function makeMap(){
		$this->initCoords();		
		$this->createBaseMap();
		if(count($this->markers))$this->placeMarkers();
		if($this->osmLogo) $this->copyrightNotice();
	}

	public function showMap(){
		$etag = md5(var_export($this->request,true));
		if ($this->useHTTPCache && array_key_exists('HTTP_IF_NONE_MATCH',$this->requestHeaders)) {
			if ($etag == $this->requestHeaders['HTTP_IF_NONE_MATCH']) {
				header('HTTP/1.1 304 Not Modified');
				return '';
			}
		}
		$this->parseParams();
		if($this->useMapCache){
			// use map cache, so check cache for map
			if(!$this->checkMapCache()){
				// map is not in cache, needs to be built
				$this->makeMap();
				$this->mkdir_recursive(dirname($this->mapCacheIDToFilename()),0777);
				imagepng($this->image,$this->mapCacheIDToFilename(),9);
				if(file_exists($this->mapCacheIDToFilename())){
					$this->sendHeader($this->mapCacheIDToFilename());
					return file_get_contents($this->mapCacheIDToFilename());
				} else {
					$this->sendHeader(null,$etag);
					return imagepng($this->image);
				}
			} else {
				// map is in cache
				if ($this->useHTTPCache && array_key_exists('HTTP_IF_MODIFIED_SINCE',$this->requestHeaders)) {
					$request_time = strtotime($this->requestHeaders['HTTP_IF_MODIFIED_SINCE']);
					$file_time = filemtime($this->mapCacheIDToFilename());
					if ($request_time >= $file_time) {
						header('HTTP/1.1 304 Not Modified');
						return '';
					}
				}
				$this->sendHeader($this->mapCacheIDToFilename());
				return file_get_contents($this->mapCacheIDToFilename());
			}

		} else {
			// no cache, make map, send headers and deliver png
			$this->makeMap();
			$this->sendHeader(null,$etag);
			return imagepng($this->image);		
			
		}
	}

}
