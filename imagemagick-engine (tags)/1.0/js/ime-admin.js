// Ajax test IM path
function ime_test_path () {
    jQuery('.cli_path_icon').hide();
    jQuery('#cli_path_progress').show();
    jQuery.get(jQuery('#ajax_url').val(), {
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

jQuery(document).ready(function($) {
    jQuery('#ime_cli_path_test').click(function(){
	ime_test_path();
    });
});
