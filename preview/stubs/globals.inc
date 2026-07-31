<?php
/* Preview stub of pfSense globals.inc */

global $g, $dyndns_split_domain_types;

$g = array(
	'product_label'	=> 'pfSense',
	'product_name'	=> 'pfsense',
	'tmp_path'	=> WGEASY_PREVIEW_VAR . '/tmp',
	'varrun_path'	=> WGEASY_PREVIEW_VAR . '/run',
	// Stands in for /conf, where the Dynamic DNS cache files live
	'conf_path'	=> WGEASY_PREVIEW_VAR . '/pfconf',
	'www_path'	=> WGEASY_PREVIEW_ROOT,
	'theme'		=> 'pfsense-ng-fullscreen');

/*
 * Verbatim from pfSense globals.inc. Providers that store the record as
 * host + domainname instead of a single FQDN.
 */
$dyndns_split_domain_types = [
	'cloudflare', 'cloudflare-v6',
	'cloudns',
	"digitalocean", "digitalocean-v6",
	"gandi-livedns", "gandi-livedns-v6",
	'godaddy', 'godaddy-v6',
	'gratisdns',
	'linode', 'linode-v6',
	'luadns', 'luadns-v6',
	"mythicbeasts", "mythicbeasts-v6",
	'namecheap',
	"name.com", "name.com-v6",
	"onecom", "onecom-v6",
	'porkbun', 'porkbun-v6',
	"yandex", "yandex-v6",
];

$GLOBALS['g'] = $g;
$GLOBALS['dyndns_split_domain_types'] = $dyndns_split_domain_types;

?>
