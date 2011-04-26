<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

$plugin_info = array('pi_name' => 'TeemThumb', 
    'pi_version' => '1.2',
    'pi_author' => 'Bjorn Borresen',
    'pi_author_url' => 'http://ee.bybjorn.com/teemthumb/',
    'pi_description' => 'Timthumb for EE',
    'pi_usage' => Teemthumb::usage());

define ('CACHE_SIZE', 1000);		// number of files to store before clearing cache
define ('CACHE_CLEAR', 5);		// maximum number of files to delete on each cache clear
define ('VERSION', '1.0');		// version number (to force a cache refresh)

// **************************************************

// Compare the file time of two files
// This needs to be here outside of "class Teemthumb"
function _filemtime_compare($a, $b)
{
	return filemtime($a) - filemtime($b);
}

class Teemthumb {

	var $lastModified;
    var $cache_dir = "./cache/";
    var $debug = FALSE;

	function Teemthumb()
	{


		$this->EE =& get_instance();
	}

	function size()
	{
		if (function_exists('imagefilter') && defined('IMG_FILTER_NEGATE'))
		{
			$imageFilters = array(
				"1" => array(IMG_FILTER_NEGATE, 0),
				"2" => array(IMG_FILTER_GRAYSCALE, 0),
				"3" => array(IMG_FILTER_BRIGHTNESS, 1),
				"4" => array(IMG_FILTER_CONTRAST, 1),
				"5" => array(IMG_FILTER_COLORIZE, 4),
				"6" => array(IMG_FILTER_EDGEDETECT, 0),
				"7" => array(IMG_FILTER_EMBOSS, 0),
				"8" => array(IMG_FILTER_GAUSSIAN_BLUR, 0),
				"9" => array(IMG_FILTER_SELECTIVE_BLUR, 0),
				"10" => array(IMG_FILTER_MEAN_REMOVAL, 0),
				"11" => array(IMG_FILTER_SMOOTH, 0),
			);
		}
        $do_debug = $this->_get_request('debug', FALSE);
		$this->debug = ($do_debug == 'yes' || $do_debug == 1);
		// sort out image source
		$src = $this->_get_request("src", "");
		if ($src == "" || strlen($src) <= 3)
		{
			$this->_displayError("no image specified");
			return;
		}
		
		if($src[0] == "{")
		{
			if($src[strlen($src)-1] == "}")
			{
				show_error("'src' specified for teemthumb was '".$src."' which looks like an EE tag. This might mean that the custom field $src does not exist in the field group for the channel, <strong>OR</strong> you might have forgotten an end tag {/exp:...}.");
			}
		}
		
		// clean params before use
		$src = $this->_cleanSource($src);
		
		// Check to see if the source image file being passed to this routine really exists
		if(!file_exists($src) || !is_file($src))
		{
			$this->_displayError("image not found");
			return;
		}

		// last modified time of the SOURCE file (for caching)
		$this->lastModified = filemtime($src);

		// get properties
		$new_width 		= preg_replace("/[^0-9]+/", "", $this->_get_request("w", 0));
		$new_height	 	= preg_replace("/[^0-9]+/", "", $this->_get_request("h", 0));
		$zoom_crop 		= preg_replace("/[^0-9]+/", "", $this->_get_request("zc", 0));
		$quality 		= preg_replace("/[^0-9]+/", "", $this->_get_request("q", 80));
		$filters		= $this->_get_request("f", "");

		if ($new_width == 0 && $new_height == 0)
		{
			$new_width = 100;
			$new_height = 100;
		}

		// get mime type of src
		$mime_type = $this->_mime_type($src);

		
		// check to see if this image is in the cache already
		$cache_file_name = $this->cache_dir . $this->_get_cache_file($src, $new_width, $new_height, $quality);

		if ( file_exists($cache_file_name) )
		{
            if($new_width == 0 || $new_height == 0) // if only one of the values were given we need to get the other
            {
                list($new_width, $new_height) = getimagesize($cache_file_name);
            }

			return $this->get_tagdata($cache_file_name, $new_width, $new_height);
		}


		// if not in cache then clear some space and generate a new file
		// DSN: Not sure why they are cleaning the Cache directory?  Problem is that they are
		// not checking to see if the source file was modified since the last cache file was created.
		// This will cause some problems in the future.
		$this->_cleanCache();

        $current_mem_limit = intval(ini_get('memory_limit'));
        if($current_mem_limit < 30)
        {
            ini_set('memory_limit', "30M");
        }

		// make sure that the src is gif/jpg/png
		if(!$this->_valid_src_mime_type($mime_type))
		{
			$this->_displayError("Invalid src mime type: " .$mime_type);
		}

		// check to see if GD function exist
		if(!function_exists('imagecreatetruecolor'))
		{
			$this->_displayError("GD Library Error: imagecreatetruecolor does not exist");
		}

		if(strlen($src) && file_exists($src))
		{
			// open the existing image
			$image = $this->_open_image($mime_type, $src);
			if($image === false)
			{
				$this->_displayError('Unable to open image : ' . $src);
			}
		
			// Get original width and height
			$width = imagesx($image);
			$height = imagesy($image);

			// don't allow new width or height to be greater than the original
			if( $new_width > $width )
			{
				$new_width = $width;
			}
			if( $new_height > $height )
			{
				$new_height = $height;
			}
		
			// generate new w/h if not provided
			if( $new_width && !$new_height )
			{
				$new_height = $height * ( $new_width / $width );
			}
			elseif($new_height && !$new_width)
			{
				$new_width = $width * ( $new_height / $height );
			}
			elseif(!$new_width && !$new_height)
			{
				$new_width = $width;
				$new_height = $height;
			}
			
			// create a new true color image
			$canvas = imagecreatetruecolor( $new_width, $new_height );
			imagealphablending($canvas, false);

			// Create a new transparent color for image
			$color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);

			// Completely fill the background of the new image with allocated color.
			imagefill($canvas, 0, 0, $color);

			// Restore transparency blending
			imagesavealpha($canvas, true);
		
			if( $zoom_crop )
			{
				$src_x = $src_y = 0;
				$src_w = $width;
				$src_h = $height;
				$cmp_x = $width  / $new_width;
				$cmp_y = $height / $new_height;
		
				// calculate x or y coordinate and width or height of source
				if ( $cmp_x > $cmp_y )
				{
					$src_w = round( ( $width / $cmp_x * $cmp_y ) );
					$src_x = round( ( $width - ( $width / $cmp_x * $cmp_y ) ) / 2 );
				}
				elseif ( $cmp_y > $cmp_x )
				{
					$src_h = round( ( $height / $cmp_y * $cmp_x ) );
					$src_y = round( ( $height - ( $height / $cmp_y * $cmp_x ) ) / 2 );
				}
				
				imagecopyresampled( $canvas, $image, 0, 0, $src_x, $src_y, $new_width, $new_height, $src_w, $src_h );

			}
			else
			{
				// copy and resize part of an image with resampling
				imagecopyresampled( $canvas, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
			}
			
			if ($filters != "")
			{
				// apply filters to image
				$filterList = explode("|", $filters);
				foreach($filterList as $fl)
				{
					$filterSettings = explode(",", $fl);
					if(isset($imageFilters[$filterSettings[0]]))
					{
						for($i = 0; $i < 4; $i ++)
						{
							if(!isset($filterSettings[$i]))
							{
								$filterSettings[$i] = null;
							}
						}
						switch($imageFilters[$filterSettings[0]][1])
						{
							case 1:
							
								imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1]);
								break;
							
							case 2:
							
								imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1], $filterSettings[2]);
								break;
							
							case 3:
							
								imagefilter($canvas, $imageFilters[$filterSettings[0]][0], $filterSettings[1], $filterSettings[2], $filterSettings[3]);
								break;
							
							default:
							
								imagefilter($canvas, $imageFilters[$filterSettings[0]][0]);
								break;
						}
					}
				}
			}
			
			
			// check to see if we can write to the cache directory
			if (touch($cache_file_name))
			{
			        // give 666 permissions so that the developer 
			        // can overwrite web server user
			        @chmod ($cache_file_name, 0666);
		        	$is_writable = 1;
			}
			else
			{
			    	$this->_displayError("Could not write to cache");
			}
	
			switch ($mime_type)
			{
			        case 'image/jpeg':
		        	    imagejpeg($canvas, $cache_file_name, $quality);
			            break;

			        default :
			            $quality = floor ($quality * 0.09);
			            imagepng($canvas, $cache_file_name, $quality);
			}
	    			
			// remove image from memory
			imagedestroy($canvas);
			return $this->get_tagdata($cache_file_name, $new_width, $new_height);
		}
		else
		{
			if(strlen($src))
			{
				$this->_displayError("image " . $src . " not found");
			}
			else
			{
				$this->_displayError("no source specified");
			}
		}
	}

    /**
     * Get tagdata to return
     *
     * @param  $sized
     * @param  $w
     * @param  $h
     * @return void
     */
    private function get_tagdata($sized, $w, $h)
    {
        $tagdata = $this->EE->TMPL->tagdata;
        $cache_file_url = $this->EE->config->item('site_url').str_replace("./", "", $sized);
        $tagdata = $this->EE->TMPL->swap_var_single('sized', $cache_file_url, $tagdata);
        $tagdata = $this->EE->TMPL->swap_var_single('w', $w, $tagdata);
        $tagdata = $this->EE->TMPL->swap_var_single('h', $h, $tagdata);
        return $tagdata;
    }

//*********************************************
// CALLED FUNCTIONS
//*********************************************

function _get_request( $property, $default = 0 ) 
{	
	$paramvalue = $this->EE->TMPL->fetch_param($property);
	if($paramvalue == '')
	{
		return $default;
	}
	else
	{
		return $paramvalue;
	}	
}


function _open_image($mime_type, $src)
{
	if(stristr($mime_type, 'gif'))
	{
		$image = imagecreatefromgif($src);	
	}
	elseif(stristr($mime_type, 'jpeg'))
	{
		@ini_set('gd.jpeg_ignore_warning', 1);
		$image = imagecreatefromjpeg($src);	
	}
	elseif( stristr($mime_type, 'png'))
	{
		$image = imagecreatefrompng($src);
	}
	return $image;
}

// Clean out old files from the cache.
// You can change the number of files to store and to delete per loop in the defines at the top of the code

function _cleanCache()
{
	$files = glob("cache/*", GLOB_BRACE);
	$yesterday = time() - (24 * 60 * 60);
	if (count($files) > 0)
	{
		usort($files, "_filemtime_compare");
		$i = 0;
		
		if (count($files) > CACHE_SIZE)
		{
			foreach ($files as $file)
			{
				$i ++;
				if ($i >= CACHE_CLEAR)
				{
					return;
				}
				
				if (filemtime($file) > $yesterday)
				{
					return;
				}
				
				unlink($file);
			}
		}
	}
}


/* determine the file mime type */
function _mime_type($file)
{
	if (stristr(PHP_OS, 'WIN'))
	{
		$os = 'WIN';
	}
	else
	{
		$os = PHP_OS;
	}

	$mime_type = '';

	if (function_exists('mime_content_type'))
	{
		$mime_type = mime_content_type($file);
	}
	
	// use PECL fileinfo to determine mime type
	if (!$this->_valid_src_mime_type($mime_type))
	{
		if (function_exists('finfo_open'))
		{
			$finfo = finfo_open(FILEINFO_MIME);
			$mime_type = finfo_file($finfo, $file);
			finfo_close($finfo);
		}
	}

	// try to determine mime type by using unix file command
	// this should not be executed on windows
	if (!$this->_valid_src_mime_type($mime_type) && $os != "WIN")
	{
		if (preg_match("/FREEBSD|LINUX/", $os))
		{
			$mime_type = trim(@shell_exec('file -bi "' . $file . '"'));
		}
	}

	// use file's extension to determine mime type
	if (!$this->_valid_src_mime_type($mime_type))
	{
		// set defaults
		$mime_type = 'image/png';

		// file details
		$fileDetails = pathinfo($file);

		$ext = strtolower($fileDetails["extension"]);

		// mime types
		$types = array(
 			'jpg'  => 'image/jpeg',
 			'jpeg' => 'image/jpeg',
 			'png'  => 'image/png',
 			'gif'  => 'image/gif'
 		);
		
		if (strlen($ext) && strlen($types[$ext]))
		{
			$mime_type = $types[$ext];
		}
	}
	return $mime_type;
}


function _valid_src_mime_type($mime_type)
{
	if (preg_match("/jpg|jpeg|gif|png/i", $mime_type))
	{
		return true;
	}
	return false;
}

// Create a special unique filename for the cache file
function _get_cache_file($src, $w, $h, $q) 
{
	$cachename = $src . VERSION . $this->lastModified . $w . $h . "q" . $q;
	$cache_file = md5($cachename) . '.png';
	return $cache_file;
}


/* tidy up the image source url */
function _cleanSource($src)
{
		// remove slash from start of string
		if(strpos($src, "/") == 0)
		{
			$src = substr($src, -(strlen($src) - 1));
		}
	
		// remove http/ https/ ftp
		$src = preg_replace("/^((ht|f)tp(s|):\/\/)/i", "", $src);
		$src_arr = explode("/", $src);
		unset($src_arr[0]);			// remove domain name from the source url
		$src = "/". implode($src_arr, "/" );
					
		// don't allow users the ability to use '../' 
		// in order to gain access to files below document root
	
		// src should be specified relative to document root like:
		// src=images/img.jpg or src=/images/img.jpg
		// not like:
		// src=../images/img.jpg
		$src = preg_replace("/\.\.+\//", "", $src);
		
		// get path to image on file system
		$src = $this->_get_document_root($src) . '/' . $src;	
	
		return $src;
}

function _get_document_root ($src)
{
	// check for unix servers
	if(@file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $src))
	{
		return $_SERVER['DOCUMENT_ROOT'];
	}
		
	// check from script filename (to get all directories to timthumb location)
	$parts = array_diff(explode('/', $_SERVER['SCRIPT_FILENAME']), explode('/', $_SERVER['DOCUMENT_ROOT']));
	$path = $_SERVER['DOCUMENT_ROOT'] . '/';
	foreach ($parts as $part)
	{
		$path .= $part . '/';
		if (file_exists($path . $src))
		{
			return $path;
		}
	}

	// The relative paths below are useful if timthumb is moved outside of document root
	// specifically if installed in wordpress themes like mimbo pro:
	// /wp-content/themes/mimbopro/scripts/timthumb.php
	$paths = array(
		".",
		"..",
		"../..",
		"../../..",
		"../../../..",
		"../../../../.."
	);
		
	foreach($paths as $path)
	{
		if(@file_exists($path . '/' . $src))
		{
			return $path;
		}
	}

	// special check for microsoft servers
	if(!isset($_SERVER['DOCUMENT_ROOT']))
	{
    		$path = str_replace("/", "\\", $_SERVER['ORIG_PATH_INFO']);
    		$path = str_replace($path, "", $_SERVER['SCRIPT_FILENAME']);
	    	
	    	if( @file_exists( $path . '/' . $src ) )
		{
    			return $path;
		}
	}

	$this->_displayError('file not found ' . $src);
}


/* generic error message */
function _displayError($errorString = '')
{
    if($this->debug)
    {
        $this->EE->output->show_user_error('general', array($errorString));
    }
    else
    {
	    $this->EE->TMPL->log_item("teemthumb ERROR: ".$errorString);
    }
}


/**
 * Usage
 *
 * Plugin Usage
 *
 * @access	public
 * @return	string
 */
function usage()
{
	ob_start(); 
	?>
    
	TeemThumb by Bjorn Borresen. Based on TimThumb script created by Tim McDaniels and Darren Hoyt with tweaks by Ben Gillbanks (http://code.google.com/p/timthumb/).

	MIT License: http://www.opensource.org/licenses/mit-license.php

	Paramters
	---------
	w: width
	h: height
	zc: zoom crop (0 or 1)
	q: quality (default is 75 and max is 100)
	
	Usage example:
	
{exp:teemthumb:size src="{article_image}" w="540" h="195" q="90"}
<img src="{sized}" alt="{title}" class="feat-image" width="{w}" height="{h}" />
{/exp:teemthumb:size}	
		
	<?php
	$buffer = ob_get_contents();
	ob_end_clean(); 
	return $buffer;
}
	

} // END class Teemthumb
