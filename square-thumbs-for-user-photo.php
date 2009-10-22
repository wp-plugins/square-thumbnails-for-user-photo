<?php
/*
Plugin Name: Square Thumbnails for User Photo
Plugin URI: http://wordpress.org/extend/plugins/square-thumbnails-for-user-photo/
Description: Extends the <a href="http://wordpress.org/extend/plugins/user-photo/">User Photo plugin</a> to allow the generation of square thumbnails. (Requires the User Photo plugin, WILL NOT WORK otherwise.)
Author: Simon Wheatley
Version: 1.2.1
Author URI: http://simonwheatley.co.uk/wordpress/
*/

/*  Copyright 2008 Simon Wheatley

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

require_once( dirname (__FILE__) . '/plugin.php' );
require_once( ABSPATH . '/wp-includes/js/tinymce/plugins/spellchecker/classes/utils/JSON.php' );

define( 'ST_MAX_SIDE', 600 ); // The max side dimension we will present for cropping
define( 'ST_IMAGE_JPEG_QUALITY', 85 ); // The JPEG quality to use
define( 'ST_SQUARE_DIMENSION', 80 ); // The length of the sides of the square

/**
 *
 * @package default
 * @author Simon Wheatley
 **/
class UserPhotoSquareThumbnails extends UserPhotoSquareThumbnails_Plugin
{
	protected $admin_notices = array();
	
	function UserPhotoSquareThumbnails()
	{
		$this->register_plugin ( 'square-thumbs-for-user-photo', __FILE__);

		$this->add_action( 'init', null, 9 );
		$this->add_action( 'admin_notices' );
		// User Photo resizes the image in place in the tmp directory,
		// so we must get in there before that in the chain of actions.
		$this->add_action( 'profile_update' );
		// Check the thumbnail when on your profile, or other people's profiles
		$this->add_action( 'load-profile.php', 'maybe_add_crop_dialog' );
		$this->add_action( 'load-user-edit.php', 'maybe_add_crop_dialog' );
		// Custom action, use do_action( 'stfup_load_profile' ); BEFORE loading the admin header in any custom profile pages
		$this->add_action( 'stfup_load_profile', 'maybe_add_crop_dialog' );
		// Answer crop dialog AJAX calls
		$this->add_action( 'wp_ajax_crop_widget_html', 'crop_widget_html' );
		$this->add_action( 'wp_ajax_crop_commit', 'crop_commit' );
	}
	
	public function init()
	{
		$this->sanity_checks();
	}
	
	public function admin_notices()
	{
		foreach ( $this->admin_notices AS $msg ) {
			$this->print_admin_notice( $msg );
		}
	}
	
	protected function add_admin_notice( $msg )
	{
		$this->admin_notices[] = $msg;
	}
	
	protected function print_admin_notice( $msg )
	{
		echo '<div class="plugin-update">';
		echo $msg;
		echo '</div>';
	}
	
	public function profile_update( $user_id )
	{
		$this->process_image_upload( $user_id );
	}
	
	protected function process_image_upload( $user_id )
	{
		// Process the image upload.
		// We validate for sanity/security only, all messaging to the 
		// user is left to the User Photo plugin. We're purely here 
		// to get a copy of the original uploaded file before 
		// any resizing.
		
		$image_name = @ $_FILES['userphoto_image_file']['name'];
		// Check whether there appears to be an uploaded file
		if ( ! $image_name ) return;
		
		$image_tmp_name = @ $_FILES['userphoto_image_file']['tmp_name'];
		// Check this is a actual successfully uploaded file
		if ( ! is_uploaded_file( $image_tmp_name ) ) return;
		
		$image_error = @ $_FILES['userphoto_image_file']['error'];
		// If there's an upload error, we'll leave it to User Photo to deal with that
		if ( $image_error ) return;
		
		// All looking OK. Let's grab that file.
		$destination_dir = $this->userphoto_dir_path();
		
		// Attempt to make the dir if necessary
		if ( ! file_exists( $destination_dir ) && ! mkdir( $destination_dir, 0777 ) ) {
			// MKDIR failed :(
			// We can take advantage of the User Photo localisations here. Which is nice.
			$this->add_admin_notice( __("The userphoto upload content directory does not exist and could not be created. Please ensure that you have write permissions for the /wp-content/uploads/ directory.", 'user-photo') );
		}
		
		$userdata = get_userdata( $user_id );
		$destination_filename = preg_replace( '/^.+(?=\.\w+$)/', $userdata->user_nicename . '.original', $_FILES['userphoto_image_file']['name'] );
		$destination_path = $destination_dir . '/' . $destination_filename;
		
		// We've already checked that this is a non-dodgy uploaded file (see is_uploaded_file above) 
		// so it's safe to use copy rather than move_uploaded_file.
		copy( $image_tmp_name, $destination_path );
		
		// Resize the photo if necessary
		if ( $this->side_longer_than( ST_MAX_SIDE, $destination_path ) ) {
			// Scale the image.
			list($w, $h, $format) = getimagesize( $destination_path );
			$xratio = ST_MAX_SIDE / $w;
			$yratio = ST_MAX_SIDE / $h;
			$ratio = min( $xratio, $yratio );
			$targetw = (int) $w * $ratio;
			$targeth = (int) $h * $ratio;

			$src_gd = $this->image_create_from_file( $destination_path );
			assert( $src_gd );
			$target_gd = imagecreatetruecolor( $targetw, $targeth );
			imagecopyresampled ( $target_gd, $src_gd, 0, 0, 0, 0, $targetw, $targeth, $w, $h );
			// create the initial copy from the original file
			// also overwrite the filename (in case the extension isn't accurate)
			$destination_filename = preg_replace( '/^.+(?=\.\w+$)/', $userdata->user_nicename . '.original', $_FILES['userphoto_image_file']['name'] );
			$destination_filename = $this->strip_filename_extension( $destination_filename );
			if ( $format == IMAGETYPE_GIF ) {
				$destination_filename .= ".gif";
				$destination_path = $destination_dir . '/' . $destination_filename;
				imagegif( $target_gd, $destination_path );
			} elseif ( $format == IMAGETYPE_JPEG ) {
				$destination_filename .= ".jpg";
				$destination_path = $destination_dir . '/' . $destination_filename;
				imagejpeg( $target_gd, $destination_path, ST_IMAGE_JPEG_QUALITY );
			} elseif ( $format == IMAGETYPE_PNG ) {
				$destination_filename .= ".gif";
				$destination_path = $destination_dir . '/' . $destination_filename;
				imagepng( $target_gd, $destination_path );
			} else {
				wp_die( 'Unknown image type. Please upload a JPEG, GIF or PNG.' );
			}
		}

		// We won't store the whole path, as things might move around
		update_usermeta( $user_id, "squarethumbs_original_file", $destination_filename );
	}
		
	public function maybe_add_crop_dialog()
	{
		$profileuser = $this->get_profileuser();
		
		// Check if the thumbnail even exists
		// Construct the filename
		$thumbnail = $profileuser->userphoto_thumb_file;
		// If the location wasn't stored, then we need not continue
		if ( ! $thumbnail ) return;
		
		// Check if the existing thumbnail is square
		$filename = $this->userphoto_dir_path() . '/' . $thumbnail;
		
		// Check the file exists, and hasn't been deleted or moved for some reason
		if ( ! file_exists( $filename ) ) return;
		
		// If it's already square, then we can remove the image
		if ( $this->thumbnail_is_square( $filename ) ) {
			$this->remove_original_image();
			return;
		}

		// ...obviously not square.... hmmm
		
		// Does an original photo exist? Otherwise we've no chance.
		$original_file = get_usermeta( $profileuser->ID, 'squarethumbs_original_file' );
		$original_path = $this->userphoto_dir_path() . '/' . $original_file;
		if ( ! $original_file || ! file_exists( $original_path ) ) {
			// OK. This is awkward, we're going to have to ask the user to re-upload their pic
			$this->add_admin_notice( __("<strong>The user photo you have previously uploaded is not square.</strong> Please reupload your user photo below, and you will then be able to choose a square crop of it for the thumbnail.") );
			return;
		}
				
		// We'll add the crop dialog.
		// Add the JS. jQuery, imgAreaSelect and our own jQuery reliant script
		wp_enqueue_script( 'jquery' ); // Probably present, but let's be sure
		$image_area_select_js = $this->url() . '/js/jquery.imgareaselect-0.5.min.js';
		wp_enqueue_script( 'square_thumbs_img_area_select', $image_area_select_js );
		$main_js = $this->url() . '/js/crop-dialog.js';
		wp_enqueue_script( 'square_thumbs_crop_dialog', $main_js );
		
		// Add the CSS
		$main_css = $this->url() . '/css/crop-dialog.css';
		wp_enqueue_style( 'square_thumbs_crop_dialog', $main_css );
	}
	
	public function crop_commit()
	{
		$profileuser = $this->get_profileuser();
		$original_file = get_usermeta( $profileuser->ID, 'squarethumbs_original_file' );

		$img_src = $this->userphoto_dir_url() . '/' . $original_file;
		$img_path = $this->userphoto_dir_path() . '/' . $original_file;

		$x1 = (int) @ $_POST[ 'x1' ];
		$y1 = (int) @ $_POST[ 'y1' ];
		$x2 = (int) @ $_POST[ 'x2' ];
		$y2 = (int) @ $_POST[ 'y2' ];
		$width = (int) @ $_POST[ 'width' ];
		$height = (int) @ $_POST[ 'height' ];
		
		$thumbnail_dimension = get_option( 'userphoto_thumb_dimension' );

		$userdata = get_userdata( $profileuser->ID );
		$target_path = $this->userphoto_dir_path() . '/' . $userdata->userphoto_thumb_file;

		// Read into GD
		$success = true;
		$src_gd = $this->image_create_from_file( $img_path );
		$target_gd = imagecreatetruecolor( $thumbnail_dimension, $thumbnail_dimension );
		if( $success && ! $target_gd ) {
			$success = false;
		}
		if( ! imagecopyresampled ( $target_gd, $src_gd, 0, 0, $x1, $y1, $thumbnail_dimension, $thumbnail_dimension, $width, $height ) ) {
			$success = false;
		}

		// Add some uniqueness into the filename to defeat caching
		$thumb_filename = $this->strip_filename_extension( $userdata->userphoto_thumb_file );
		$thumb_filename_prefix = $thumb_filename; // We'll use this to delete old ones.
		$thumb_filename .= "." . uniqid();
		$thumb_filename .= ".jpg"; // We're always saving a JPG
		// Remove old thumbnail file
		unlink( $target_path );
		$this->unlink_files_prefixed_with( $thumb_filename_prefix );
		update_usermeta( $profileuser->ID, 'userphoto_thumb_file', $thumb_filename );
		// Overwrite target path
		$target_path = $this->userphoto_dir_path() . '/' . $thumb_filename;
		
		if( $success && ! imagejpeg( $target_gd, $target_path, ST_IMAGE_JPEG_QUALITY ) ) {
			$success = false;
		}
		
		// Setup User meta data for new thumbnail size
		update_usermeta( $profileuser->ID, 'userphoto_thumb_height', $thumbnail_dimension );
		update_usermeta( $profileuser->ID, 'userphoto_thumb_width', $thumbnail_dimension );

		// Data to send
		$data = array();
		$data['success'] = $success;
		$data['thumbnail_src'] = $this->userphoto_dir_url() . '/' . $thumb_filename;
		
		// Make JSON
		$json = new Moxiecode_JSON();
		echo $json->encode( $data );
		exit; // Don't care for anything else getting in on this action
	}
	
	protected function unlink_files_prefixed_with( $prefix )
	{
		$dir_path = $this->userphoto_dir_path();
		if ( $dir_handle = opendir( $dir_path ) ) {
		    while ( false !== ( $file = readdir( $dir_handle ) ) ) {
				if ( stripos( $file, $prefix ) === 0  ) {
					unlink( $dir_path . '/' . $file );
				}
		    }
		    closedir( $dir_handle );
		}
	}
	
	protected function strip_filename_extension( $filename )
	{
		$pos = strrpos($filename, '.');
		if ($pos > 0) {
			return substr($filename, 0, $pos);
		} else {
			return $filename;
		}
	}

	
	// The following was lifted from:
	// http://uk.php.net/manual/en/ref.image.php
	// With minor mods: ON error now returns false.
	// No longer accepts xbms (silly format)
	protected function image_create_from_file( $filename )
	{
		static $image_creators;

		if (!isset($image_creators)) {
			$image_creators = array(
				1  => "imagecreatefromgif",
				2  => "imagecreatefromjpeg",
				3  => "imagecreatefrompng"
			);
		}

		list( $w, $h, $file_type ) = getimagesize($filename);
		if ( isset( $image_creators[$file_type] ) ) {
			$image_creator = $image_creators[ $file_type ];
			if ( function_exists( $image_creator ) ) {
				return $image_creator( $filename );
			}
		}

		// Changed to return false on error
		return false;
	}
	
	protected function side_longer_than( $dimension, $filename )
	{
		list( $width, $height ) = getimagesize( $filename );
		return ( ( $width > $dimension ) || ( $height > $dimension ) );
	}
	
	protected function thumbnail_is_square( $filename )
	{
		list( $width, $height ) = getimagesize( $filename );
		return ( $width == $height );
	}
	
	protected function get_profileuser()
	{
		$user_id = (int) @ $_REQUEST[ 'user_id' ];
		
		if ( ! $user_id ) {
			$current_user = wp_get_current_user();
			$user_id = $current_user->ID;
		}
		return get_user_to_edit( $user_id );
	}
	
	public function crop_widget_html()
	{
		$profileuser = $this->get_profileuser();
		$original_file = get_usermeta( $profileuser->ID, 'squarethumbs_original_file' );

		$img_src = $this->userphoto_dir_url() . '/' . $original_file;

		// Get the image size
		$img_path = $this->userphoto_dir_path() . '/' . $original_file;
		$img_info = getimagesize( $img_path );
		list( $img_width, $img_height ) = $img_info;
		
		$thumbnail_dimension = get_option( 'userphoto_thumb_dimension' );

		$vars = array(
			'img_src' => $img_src,
			'img_height' => $img_height,
			'img_width' => $img_width,
			'thumbnail_dimension' => $thumbnail_dimension
		);
		$html_src = $this->capture_admin ( 'crop_widget_html', $vars );
		
		// Data to send
		$data = array();
		$data['html_src'] = $html_src;
		$data['thumbnail_dimension'] = $thumbnail_dimension;
		$data['img_height'] = $img_height;
		$data['img_width'] = $img_width;

		// Make JSON
		$json = new Moxiecode_JSON();
		echo $json->encode( $data );
		exit; // Don't care for anything else getting in on this action
	}

	protected function escape_for_js( $string )
	{
		return js_escape( $string );
	}
	
	protected function remove_original_image()
	{
		// SWTODO: Remove original image method
	}
	
	protected function userphoto_dir_path()
	{
		$upload_dir = wp_upload_dir();
		return $upload_dir[ 'basedir' ] . "/userphoto";
	}
	
	protected function userphoto_dir_url()
	{
		$upload_dir = wp_upload_dir();
		return $upload_dir[ 'baseurl' ] . "/userphoto";
	}
	
	protected function sanity_checks()
	{
		// Check that the User Photo plugin is present
		if ( ! function_exists( 'userphoto__get_userphoto' ) || ! defined( 'USERPHOTO_PLUGIN_IDENTIFIER' ) ) {
			$this->add_admin_notice( __( 'The <em>Square Thumbnails for User Photo</em> plugin requires the <a href="http://wordpress.org/extend/plugins/user-photo/"><em>User Photo plugin</em></a> to be installed.' ) );
		}
	}
}

/**
 * Instantiate the plugin
 *
 * @global
 **/

$UserPhotoSquareThumbnails = new UserPhotoSquareThumbnails();

?>