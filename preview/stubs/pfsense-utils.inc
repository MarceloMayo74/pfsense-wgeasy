<?php
/* Preview stub of pfSense pfsense-utils.inc */

require_once('util.inc');

function where_is_ipaddr_configured($ipaddr, $ignore_if = '', $check_localip = false, $check_subnets = false, $cidrprefix = '') {
	// Nothing conflicts in the preview
	return array();
}

function get_uniqueid() {
	return bin2hex(random_bytes(8));
}

?>
