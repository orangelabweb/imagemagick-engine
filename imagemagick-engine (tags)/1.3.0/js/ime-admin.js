//Variables
var rt_images = "";
var rt_total = 1;
var rt_count = 1;
var rt_force = 0;
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
    
    if(jQuery('#force').is(':checked')) {
	rt_force = 1;
    }

    rt_count = 1;
    jQuery("#regenbar").progressbar();
    jQuery("#regenbar-percent").html( "0%" );
    jQuery('#regeneration').dialog('open');

    imeRegenImages( rt_images.shift() );
}

//Regeneration of progressbar
function imeRegenImages( id ) {
    jQuery.post(ajaxurl, { action: "ime_process_image", id: id, sizes: rt_sizes, force: rt_force }, function() {
	var rt_percent = ( rt_count / rt_total ) * 100;
	jQuery("#regenbar").progressbar( "value", rt_percent );
	jQuery("#regenbar-percent").html( Math.round(rt_percent) + "%" );
	rt_count = rt_count + 1;

	if ( rt_images.length <= 0 ) {
	    jQuery('#regen-message').removeClass('hidden').html("<p><strong>"+jQuery('#rt_message_done').val()+"</strong> "+jQuery('#rt_message_processed').val()+" "+rt_total+" "+jQuery('#rt_message_images').val()+".</p>");
	    jQuery('#regeneration').dialog('close');
	    jQuery("#regenbar").progressbar( "value", 0 );
	    return;
	}

	// tail recursion
	imeRegenImages( rt_images.shift() );
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

    $('#regeneration').dialog({
	height: 42,
	minHeight: 42,
	closeText: 'X',
	width: '75%',
	modal: true,
	autoOpen: false
    });

    $('#regenerate-images').click(function(){
	$('#regenerate-images-metabox img.ajax-feedback').css('visibility','visible');
	$.post(ajaxurl, { action: "ime_regeneration_get_images" }, function(data) {
	    jQuery('#regen-message').addClass('hidden');
	    $('#regenerate-images-metabox img.ajax-feedback').css('visibility','hidden');
	    rt_images = data.split(",");
	    rt_total = rt_images.length;
	    
	    if(rt_total > 0) {
		imeStartResize();
	    } else {
		alert($('#rt_message_noimg').val());
	    }
	});
    });
});
