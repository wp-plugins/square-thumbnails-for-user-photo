// jQuery based stuff for the Square Thumbnails crop dialog

jQuery( document ).ready( crop_dialog_ready );

function crop_dialog_ready()
{
	var data = {
		_wpnonce: jQuery( '#_wpnonce' ).val(),
		action: 'crop_widget_html',
		user_id: jQuery( '#user_id' ).val()
	};
	jQuery.post( 'admin-ajax.php', data, make_crop_widget, 'json' );
}

function make_crop_widget( data )
{
	// Get rid of other messages
	jQuery( '#message' ).remove();
	// Add our HTML
	jQuery( '#profile-page' ).prepend( data.html_src );
	// Add the selection behaviour to the image
	var img_select_params = {
		aspectRatio: '10:10',
		minHeight: data.thumbnail_dimension,
		minWidth: data.thumbnail_dimension,
		onSelectChange: check_crop,
		onSelectEnd: remember_crop,
		outerColor: '#2583AD',
		outerOpacity: 0.5,
		selectionColor: '#ffffff', 
		selectionOpacity: 0
	};
	jQuery( '#st_wdgt .image img' ).imgAreaSelect( img_select_params );
	// Flash our message, just, you know, to be fancy
	jQuery( '#st_wdgt' ).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300);
	// Set the thumbnail dimension in the global context
	thumbnail_dimension = data.thumbnail_dimension;
	// Action for committing the crop
	jQuery( 'button#commit_crop' ).click( commit_crop );
}

function check_crop( img, crop )
{
	if ( crop.height >= thumbnail_dimension && crop.width >= thumbnail_dimension ) {
		jQuery( '#commit_crop' ).attr( 'disabled', false );
	} else {
		jQuery( '#commit_crop' ).attr( 'disabled', true );
	}
}

function remember_crop( img, crop )
{
	jQuery( '#commit_crop' ).attr( 'x1', crop.x1 ).attr( 'y1', crop.y1 ).attr( 'x2', crop.x2 ).attr( 'y2', crop.y2 ).attr( 'crop_height', crop.height ).attr( 'crop_width', crop.width );
}

function commit_crop()
{
	jQuery( 'button#commit_crop' ).text( 'Please wait...' ).attr( 'disabled', true );
	var data = {
		_wpnonce: jQuery( '#_wpnonce' ).val(),
		action: 'crop_commit',
		user_id: jQuery( '#user_id' ).val(),
		x1: jQuery( this ).attr( 'x1' ),
		y1: jQuery( this ).attr( 'y1' ),
		x2: jQuery( this ).attr( 'x2' ),
		y2: jQuery( this ).attr( 'y2' ),
		width: jQuery( this ).attr( 'crop_width' ),
		height: jQuery( this ).attr( 'crop_height' )
	};
	jQuery.post( 'admin-ajax.php', data, check_reply, 'json' );
}

function check_reply( data )
{
	// Whatever happens we want to remove the area select stuff
	jQuery( '.imgareaselect-outer' ).add( '.imgareaselect-selection' ).add( '.imgareaselect-border1' ).add( '.imgareaselect-border2' ).remove();
	// If it failed, we want to show a special message and flash it
	if ( ! data.success ) {
		jQuery( '#st_wdgt' ).empty().append( '<p><strong>Sorry.</strong> Something went wrong, please reload the page and try again.</p>' );
		jQuery( '#st_wdgt' ).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300);
		return;
	}
	// All OK? Get the user to check.
	jQuery( '#st_wdgt .message' ).html( 'Thank you. We\'ve saved your image, please check it below and if you are not happy you can reupload the image and try again.' );
	// Change the image to the newly cropped thumbnail
	jQuery( '#userphoto img' ).eq( 1 ).add( '#st_wdgt .image img' ).attr( 'src', data.thumbnail_src ).attr( 'height', thumbnail_dimension ).attr( 'width', thumbnail_dimension );
	// More flashing! Yay!
	jQuery( '#st_wdgt' ).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300).animate( { backgroundColor: '#ffffe0' }, 300).animate( { backgroundColor: '#fffbcc' }, 300);
}


