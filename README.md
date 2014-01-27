staticMapLiteExt
================

Further extension of the http://sourceforge.net/projects/staticmaplite/ project - since I have started using it, I have made some additions; figured it might be useful to someone else besides me.

The point of this script is generating your own map images, your own way, on your own server - I don't have the capacity to host a static generator for public use. The setup time should be close to zero - just check that the `curl` module is enabled.

Uses the same Apache License 2.0 as the original project: http://www.apache.org/licenses/LICENSE-2.0

Additions:

    - HTTP caching
    - fix marker overlap and position (southernmost markers are now visible "above" others)
    - configurable on init
	- not dependent on globals any more ($_GET)