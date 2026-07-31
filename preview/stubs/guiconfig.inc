<?php
/*
 * Preview stub of pfSense guiconfig.inc
 *
 * Provides the page level helpers (print_input_errors, print_info_box,
 * print_apply_box, display_top_tabs, ...) and loads the Form_* classes.
 */

require_once('globals.inc');
require_once('config.inc');
require_once('util.inc');
require_once(WGEASY_PREVIEW_ROOT . '/stubs/classes/Form.class.php');

function print_input_errors($input_errors) {
	print('<div class="alert alert-danger input-errors" role="alert">');
	print('<p><strong>' . gettext('The following input errors were detected:') . '</strong></p><ul>');

	foreach ((array) $input_errors as $error) {
		print('<li>' . $error . '</li>');
	}

	print('</ul></div>');
}

function print_info_box($msg, $class = 'success', $btnname = 'close', $btntext = '', $btnicon = '', $btnclass = 'default') {
	$close = is_null($btnname)
			? ''
			: '<button type="button" class="close" data-dismiss="alert">&times;</button>';

	print("<div class=\"alert alert-{$class}\" role=\"alert\">{$close}{$msg}</div>");
}

function print_apply_box($msg) {
	print('<div class="alert alert-warning" role="alert">');
	print('<form method="post" style="display: inline;">');
	print("<span style=\"display: inline-block; margin-right: 12px;\">{$msg}</span>");
	print('<button type="submit" name="apply" value="apply" class="btn btn-success btn-sm">'
		. '<i class="fa-solid fa-check icon-embed-btn"></i>' . gettext('Apply Changes') . '</button>');
	print('</form>');
	print('</div>');
}

function print_apply_result_box($retval, $extra_text = '') {
	if ($retval == 0) {
		print_info_box(gettext('The changes have been applied successfully.') . $extra_text, 'success', null);
	} else {
		print_info_box(sprintf(gettext('There were problems applying the changes. Return code: %s'), $retval) . $extra_text, 'warning', null);
	}
}

function display_top_tabs($tab_array, $no_drop_down = false, $type = 'pills', $usepost = '') {
	print('<ul class="nav nav-' . $type . '">');

	foreach ((array) $tab_array as $tab) {
		[$name, $active, $url] = $tab;

		$class = $active ? ' class="active"' : '';

		$name = htmlspecialchars($name);

		$url = htmlspecialchars($url);

		print("<li role=\"presentation\"{$class}><a href=\"{$url}\">{$name}</a></li>");
	}

	print('</ul>');
}

function csrf_check($die = true) {
	return true;
}

function pfSenseHeader($text) {
	header("Location: {$text}");
}

?>
