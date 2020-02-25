//Variables
var rt_images = "";
var rt_total = 1;
var rt_count = 1;
var rt_force = 0;
var rt_precision = 0;
var rt_sizes = "";

// Ajax test IM path
function imeTestPath () {
    jQuery('.cli_path_icon').hide();
    jQuery('#cli_path_progress').show();
    jQuery.get(ajaxurl, {
	action: "ime_test_im_path",
	cli_path: jQuery('#cli_path').val()
    }, function(data) {
	jQuery('#cli_path_progress').hide();
	r = parseInt(data);
	if (r > 0) {
	    jQuery('#cli_path_yes').show();
	    jQuery('#cli_path_no').hide();
	} else {
	    jQuery('#cli_path_yes').hide();
	    jQuery('#cli_path_no').show();
	}
    });
}

function imeStartResize() {
    rt_sizes = "";
    rt_force = 0;
    
    jQuery('#regenerate-images-metabox input').each(function(){
	var i = jQuery(this);
	var name = i.attr('name');

	if(i.is(':checked') && name && name.substring(0,11) == "regen-size-") {
	    rt_sizes = rt_sizes + name.substring(11) + "|";
	}
    });
    
    if (jQuery('#force').is(':checked'))
	rt_force = 1;
    
    if (rt_total > 20000)
	rt_precision = 3;
    else if (rt_total > 2000)
	rt_precision = 2;
    else if (rt_total > 200)
	rt_precision = 1;
    else
	rt_precision = 0;

    var rt_percent = 0;

    rt_count = 1;
    jQuery("#ime-regenbar").progressbar();
    jQuery("#ime-regenbar-percent").html( rt_percent.toFixed(rt_precision) + " %" );
    jQuery('#ime-regeneration').addClass( 'working' );

    imeRegenImages( rt_images.shift() );
}

//Regeneration of progressbar
function imeRegenImages( id ) {
    jQuery.post(ajaxurl, { action: "ime_process_image", id: id, sizes: rt_sizes, force: rt_force }, function(data) {
	var n = parseInt(data, 10);
	if (isNaN(n)) {
	    alert(data);
	}

	// todo: test and handle negative return

	if ( rt_images.length <= 0 ) {
	    jQuery('#regen-message').removeClass('hidden').html("<p><strong>" + ime_admin.done + "</strong> " + ime_admin.processed_fmt.replace('%d', rt_total) + ".</p>");
	    jQuery('#ime-regeneration').removeClass('working');
	    jQuery("#ime-regenbar").progressbar( "value", 0 );
	    return;
	}

	var next_id = rt_images.shift();
	var rt_percent = ( rt_count / rt_total ) * 100;
	jQuery("#ime-regenbar").progressbar( "value", rt_percent );
	jQuery("#ime-regenbar-percent").html( rt_percent.toFixed(rt_precision) + " %" );
	rt_count = rt_count + 1;

	// tail recursion
	imeRegenImages(next_id);
    });
}

// Regen single image on media pages
function imeRegenMediaImage( id, sizes, force ) {
    var link = jQuery('#ime-regen-link-' + id);

    if (link.hasClass('disabled'))
	return false;

    link.addClass('disabled');

    var spinner = jQuery('#ime-spinner-' + id).children('img');
    spinner.show();

    var message = jQuery('#ime-message-' + id).show();
    jQuery.post(ajaxurl, { action: "ime_process_image", id: id, sizes: sizes, force: force }, function(data) {
	spinner.hide();
	link.removeClass('disabled');

	var n = parseInt(data, 10);
	if (isNaN(n) || n < 0) {
	    message.html(ime_admin.failed);
	    if (isNaN(n))
		alert(data);
	} else {
	    message.html(ime_admin.resized);
	}
    });
}

function imeUpdateMode() {
    jQuery("#ime-select-mode option").each(function(i,e) {
	var o = jQuery(this);
	var mode = o.val();
	if (o.is(':selected'))
	    jQuery('#ime-row-' + mode).show();
	else
	    jQuery('#ime-row-' + mode).hide();
    });
}

jQuery(document).ready(function($) {
    jQuery('#ime_cli_path_test').click(imeTestPath);

    imeUpdateMode();
    jQuery('#ime-select-mode').change(imeUpdateMode);

    $('#regenerate-images').click(function(){
		$('#regenerate-images-metabox img.ajax-feedback').show();
		$.post(ajaxurl, { action: "ime_regeneration_get_images" }, function(data) {
		    jQuery('#regen-message').addClass('hidden');
		    rt_images = data.split(",");
		    rt_total = rt_images.length;
		    
		    if(rt_total > 0) {
			 imeStartResize();
		    } else {
			 alert(ime_admin.noimg);
		    }
		});
    });
});
