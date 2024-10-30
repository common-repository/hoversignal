( function($) {

	$(document).ready( function() {
		jQuery('.reconnect').on('click', function() {
			jQuery('.succefully').hide('fast');
			jQuery('.reconnect_form').show('fast');
		});
		
		jQuery('.preview-btn').on('click', function() {
			jQuery('.succefully').show('fast');
			jQuery('.reconnect_form').hide('fast');
		});
		
		var input = jQuery('.form-control').val();
		if(~input.indexOf('Неверный API-ключ!') || ~input.indexOf('Wrong API-Key!')) {
			jQuery('.form-control').addClass('error');
		}
	});
	
})( jQuery );