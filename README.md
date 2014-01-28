staticMapLiteExt
================

Further extension of the http://sourceforge.net/projects/staticmaplite/ project - since I have started using it, I have made some additions; figured it might be useful to someone else besides me.

The point of this script is generating your own map images, your own way, on your own server - I don't have the capacity to host a static generator for public use. The setup time should be close to zero - just check that the `curl` module is enabled.

Uses the same Apache License 2.0 as the original project: http://www.apache.org/licenses/LICENSE-2.0

New features:

    - auto-select viewport and zoom from given markers
    - HTTP caching
    - fix marker overlap and position (southernmost markers are now visible "above" others)
    - class is configurable on init
    - not dependent on globals any more ($_GET)

Installation
============

    - check that your server has PHP with cURL installed. If you have console access, `php --info | grep ^curl` should return `curl`
    - clone the repository into a directory that's web-accessible (e.g. /var/www): `git clone https://github.com/Piskvor/staticMapLiteExt.git`
	- set the map cache folders to be writable by your web server `chmod 777 staticMapLiteExt/cache/*`
	- Done! The maps should now be usable on your web server (see index.html for examples)
