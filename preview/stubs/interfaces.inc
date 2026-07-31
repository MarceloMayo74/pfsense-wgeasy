<?php
/* Preview stub of pfSense interfaces.inc */

require_once('util.inc');

function get_interface_ip($interface = 'wan') {
	// Routable WAN address from the RFC 5737 documentation range, private LANs.
	// opt2 is the pfSense interface the assigned tunnel tun_wg2 is mapped to.
	$addresses = array(
			'wan'	=> '203.0.113.10',
			'lan'	=> '192.168.1.1',
			'opt1'	=> '192.168.20.1',
			'opt2'	=> '10.9.0.1',
			// Static helper link into the modem, no DHCP service on it
			'opt3'	=> '192.168.0.2');

	return $addresses[$interface] ?? null;
}

function get_interface_ipv6($interface = 'wan') {
	$addresses = array(
			'wan'	=> '2001:db8:1::10',
			'lan'	=> 'fd00:1::1');

	return $addresses[$interface] ?? null;
}

function get_interface_subnet($interface = 'wan') {
	$bits = array(
			'wan'	=> 24,
			'lan'	=> 24,
			'opt1'	=> 24,
			'opt2'	=> 24,
			'opt3'	=> 24);

	return $bits[$interface] ?? null;
}

function get_interface_subnetv6($interface = 'wan') {
	$bits = array(
			'wan'	=> 64,
			'lan'	=> 64);

	return $bits[$interface] ?? null;
}

function get_configured_interface_list_by_realif($disabled = false) {
	// tun_wg2 is assigned as the pfSense interface opt2 (Netgate recipe style)
	return array('tun_wg2' => 'opt2');
}

function get_configured_interface_with_descr($withdisabled = false) {
	return array('wan' => 'WAN', 'lan' => 'LAN', 'opt1' => 'IOT', 'opt2' => 'VPNRW', 'opt3' => 'MODEM');
}

function get_configured_interface_list($withdisabled = false) {
	return array('wan' => 'wan', 'lan' => 'lan', 'opt1' => 'opt1', 'opt2' => 'opt2', 'opt3' => 'opt3');
}

function interface_reconfigure($interface, $reloadall = false) {
	return true;
}

function system_routing_configure($interface = '') {
	return true;
}

function convert_real_interface_to_friendly_interface_name($interface) {
	return $interface;
}

?>
