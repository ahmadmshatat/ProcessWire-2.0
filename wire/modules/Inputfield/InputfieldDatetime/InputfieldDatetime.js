jQuery(document).ready(function($) {

	$("input.InputfieldDatetimeDatepicker").datepicker({
		changeMonth: true,
		changeYear: true,
		showOn: 'button',
		buttonText: "Choose",
		showAnim: 'fadeIn'
		// buttonImage: config.urls.admin_images + 'icons/calendar.gif',
		// dateFormat: config.date_format
	});

}); 
