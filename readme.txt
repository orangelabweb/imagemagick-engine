=== ImageMagick Engine ===
Contributors: rickardw, orangelab
Tags: image, images, picture, imagemagick, gd
Requires at least: 3.0
Tested up to: 5.3.2
Stable tag: 1.6.2

Improve the quality of re-sized images by replacing standard GD library with ImageMagick.

== Description ==

Dramatically improve the quality of re-sized images by making WordPress use ImageMagick instead of standard GD image library.

Features

* Preserve embedded color profile in re-sized image
* Automatically recognize custom image sizes
* Allow regeneration of existing images (optionally for selected image sizes only)
* Configure image quality or use dynamically computed default value
* Optimize different image sizes for either quality or size

Lnguages: English, French, German, Swedish

Requires either ImageMagick binary or Imagick PHP module.

== Installation ==

1. Install either ImageMagick or the Imagick PHP module (see FAQ for more information).
2. Download and extract plugin files to a folder in your wp-content/plugin directory.
3. Activate the plugin through the WordPress admin interface.
4. Configure ImageMagick settings and enable it on plugin settings page.
5. Regenerate existing images to take advantage of the new features.

If you have any questions or problems please make write in the support forum.

== Frequently Asked Questions ==

= What difference does it make? =

ImageMagick can result in huge improvements in the quality of re-sized images.

Take a look at the supplied screenshot, or try it yourself.

Note that the new images tend to be slightly larger than those of the standard GD library, especially if you specify a very high image quality (95+).

= How do I know if I have ImageMagick installed? =

If you have the PHP module installed the plugin will find it. You can check yourself using the phpinfo() function. We also automatically check a common location for the ImageMagick executable.

If you have shell access to a Linux/UNIX server you can use "which convert" to look for the ImageMagick executable.

= How do I install ImageMagick? =

You'll need full access to your server and a bit of technical know-how. If you do not have access you'll have to ask the server administrator.

Don't do it yourself unless you know what you are doing.

Most Linux distributions have a package for "ImageMagick". Some have a package for "php5-imagick". It is possible to install the PHP module using PEAR.

You can also find binary releases at http://www.imagemagick.org including a Windows installer.

= I get a fatal error when activating plugin =

Some webhosts (1and1 for example) need to add a work-around to the .htaccess file.

You might have to add the following line to your .htaccess file:
AddType x-mapp-php5 .php

You'll probably have problems with various other plugins too unless you fix this.

== Screenshots ==

1. Administration interface

== Changelog ==

= 1.6.2 =
* Added medium_large image size by default
* Display version of ImageMagick CLI (thanks @marcissimus)

= 1.6.1 =
* Fixed deprecated use of gd_edit_image_support (thanks @chesio)

= 1.6.0 =
* Small bug fixes
* Small updates to admin UI

= 1.5.4 =
* Fixed a bug that could cause transparency errors with PNG

= 1.5.3 =
* Tested with WP 5.0

= 1.5.2 =
* Tested with WP 4.1

= 1.5.1 =
* Tested with WP 3.6
* Fix CSS problems with other users of jQuery dialogs

= 1.5.0 =
* Tested with WP 3.5-beta2
* Allow choosing between optimize for quality & size for each image size
* Fix resize UI bug in media pop-up and new attachment editor (post.php)
* Add "ime_after_resize" action after resize
* Catch Imagick exceptions
* Modified code now uses more of WP standard coding style
* Updated French translation, thanks to Damien Fabreguettes
* Updated Swedish translation for new strings

= 1.4.0 =
* Tested with WP 3.3.1
* Resize / Force resize button in media library
* Add more precision to resize % when large nr of images
* More sanity tests in ajax resize code
* Use WordPress version of jQuery UI progressbar if available
* Split plugin init into early and late part
* Fix PHP notice (in initial plugin configuration)
* Updated swedish translation for new strings
* French translation thanks to Damien Fabreguettes

= 1.3.1 =
* Tested with WP 3.2.1
* Bugfix: escape '^' character on Windows (thanks to alx359)
* clean up IM command line argument handling a bit

= 1.3.0 =
* Tested agains WP 3.2
* Fix JS to be compatible with jQuery 1.6
* Remove some PHP notices
* Change command line limit values to specifik byte amounts (instead of "mb") for compatability with really old IM versions
* Handle open_basename restrictions better
* Handle older versions (pre 6.3.1) of PHP Imagick class
* IM and WordPress compute aspect ratio slightly differently, force the WP values

= 1.2.3 =
* Fix bug in resize all images handling, also remove some PHP notices. Thanks to Andreas Kleinschmidt for the report
* Upgrade jQuery UI Progressbar to version 1.8.9, to match version of UI Core in WordPress

= 1.2.2 =
* Fixed filepath with spaces on Windows
* Tested with WordPress 3.1.2
* Added question to FAQ

= 1.2.1 =
* Fix deprecated warning
* Tested with WordPress 3.1

= 1.2.0 =
* Rewrite image cropping for Imagick PHP module to make sure we keep image profiles. Thanks to Christian Münch for report
* Improve test for IM executable
* Administration: AJAXify image resizing, clarify engine selection, only load css/js on actual plugin page
* Handle progressbar version incompatability for jQuery UI 1.8 (in WP 3.1) and jQuery UI 1.7 (in WP 3.0)
* Tested with WordPress 3.1-RC2

= 1.1.2 =
* Fix bug with forced resize of custom image sizes
* Fix warning with open_basedir restriction during path test
* German translation thanks to Dirk Rottig

= 1.1.1 =
* Fix search-and-replace error from 1.1 that made it impossible to change settings! Thanks to Marco M. Jaeger for report!

= 1.1 =
* Working localization
* Added Swedish translation

= 1.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Fixes plugin jQuery UI script incompatibility for WordPress 3.1
