<?php
/* Preview stub of pfSense gwlb.inc */

function get_dnsavailable($ipprotocol = 'inet') {
	return true;
}

function return_gateways_array($disabled = false, $localhost = false, $inactive = false, $integer_index = false) {
	return array();
}

function gateway_set_enabled($gwname, $enabled = true) {
	return true;
}

?>
