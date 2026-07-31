<?php
/*
 * wgeasy.inc
 *
 * Client provisioning helpers for pfSense-pkg-WireGuard.
 *
 * This file lives entirely outside of the native pfSense-pkg-WireGuard tree and
 * only *consumes* the API that package already exposes (wg.inc, wg_api.inc,
 * wg_validate.inc, wg_guiconfig.inc). No native file is modified, so upgrades of
 * pfSense-pkg-WireGuard cannot conflict with it.
 *
 * Copyright (c) 2026 Marcelo Mayo <marcelomayo1974@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// pfSense includes
require_once('config.inc');
require_once('util.inc');
require_once('pfsense-utils.inc');
require_once('interfaces.inc');
require_once('notices.inc');

// WireGuard includes (native package, read-only consumers)
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_api.inc');
require_once('wireguard/includes/wg_guiconfig.inc');
require_once('wireguard/includes/wg_validate.inc');

global $wgeasyg;

/*
 * Sentinels for the DNS presets. Neither is ever a DNS server: the empty value
 * is left free so the select can start on a neutral prompt.
 *
 * WGEASY_DNS_TUNNEL is no longer offered as a preset, but stale forms may
 * still post it and is_hostname() would happily accept the literal string,
 * so the server side keeps translating it.
 */
define('WGEASY_DNS_TUNNEL', '__tunnel__');
define('WGEASY_DNS_NONE', '__none__');

/*
 * Everything this page needs to rebuild a client file later is kept in one
 * sub-element of the peer, clearly namespaced so it never collides with a
 * native field and can be removed wholesale.
 */
define('WGEASY_STORE', 'wgeasy');

// The element this package used before it was renamed from wgprov to wgeasy
define('WGEASY_STORE_LEGACY', 'wgprov');

$wgeasyg = array(
	'pkg_name'		=> 'pfSense-pkg-wgeasy',
	'page'			=> '/wgeasy/vpn_wg_easy.php',
	'edit_page'		=> '/wgeasy/vpn_wg_easy_edit.php',
	'www_root'		=> '/usr/local/www',
	// First existing file wins. The local copy is preferred so the page keeps
	// working even if any third party package that ships /wg_qrcode.js is removed.
	'qrcode_js'		=> array(
					'/wgeasy/js/wgeasy_qrcode.js',
					'/wg_qrcode.js'),
	/*
	 * On screen, and the pixel size of the downloaded PNG.
	 *
	 * A client file is around 450 bytes, which at error correction level L
	 * needs 73 modules plus the 4 module quiet zone. Phone cameras want
	 * roughly 4 to 5 pixels per module to lock on, hence 400 rather than
	 * something smaller. Level L is also what qrencode(1) defaults to, the
	 * tool the WireGuard app itself suggests for making these codes.
	 */
	'qr_display_size'	=> 400,
	'qr_size'		=> 512,
	'qr_level'		=> 'L',
	'qr_quiet_zone'		=> 4,
	'default_keepalive'	=> 25,
	'default_allowedips'	=> '0.0.0.0/0, ::/0',
	// Upper bound when probing a tunnel subnet for a free address
	'max_address_probe'	=> 4096);

/*
 * Returns the tunnels that can actually be provisioned against, formatted for
 * input selection boxes. Mirrors wg_get_tun_list() from wg_guiconfig.inc, minus
 * the 'unassigned' entry (a client config needs a real tunnel public key).
 */
function wgeasy_get_tun_list() {
	// Format tunnel description if one is configured
	$tunDescr = fn($tunnel) => !empty($tunnel['descr']) ? " ({$tunnel['descr']})" : null;

	$a_ret = array();

	foreach (config_get_path('installedpackages/wireguard/tunnels/item', []) as $tunnel) {
		// A tunnel without a valid public key cannot be handed to a client
		if (!wg_is_valid_key($tunnel['publickey'])) {
			continue;
		}

		$a_ret[$tunnel['name']] = "{$tunnel['name']}{$tunDescr($tunnel)}";
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Returns the address rows that actually apply to a tunnel.
 *
 * A tunnel normally carries its own Interface Addresses, but once it is
 * assigned as a pfSense interface the native package hides those fields and
 * the address lives on the assigned interface instead (see the address section
 * of vpn_wg_tunnels_edit.php). Read whichever place holds them.
 */
function wgeasy_tunnel_address_rows($tunnel_name) {
	$a_ret = array();

	if (!([$tun_idx, $tunnel, $is_new] = wg_tunnel_get_config_by_name($tunnel_name))) {
		return $a_ret;
	}

	foreach ((array) $tunnel['addresses']['row'] as $address) {
		if (is_ipaddr($address['address'] ?? '')) {
			$a_ret[] = array('address' => $address['address'], 'mask' => (string) $address['mask']);
		}
	}

	if (empty($a_ret)
	    && is_wg_tunnel_assigned($tunnel['name'])
	    && ($pfsense_if = wg_get_pfsense_interface_info($tunnel['name']))) {
		$if = $pfsense_if['name'];

		$address = get_interface_ip($if);

		$bits = get_interface_subnet($if);

		if (is_ipaddrv4($address) && is_numericint($bits)) {
			$a_ret[] = array('address' => $address, 'mask' => (string) $bits);
		}

		$address6 = get_interface_ipv6($if);

		$bits6 = get_interface_subnetv6($if);

		if (is_ipaddrv6($address6) && is_numericint($bits6)) {
			$a_ret[] = array('address' => $address6, 'mask' => (string) $bits6);
		}
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Returns the tunnel networks (as start boundaries) of a given tunnel, suitable
 * for a split tunnel AllowedIPs line
 */
function wgeasy_tunnel_networks($tunnel_name) {
	$a_ret = array();

	foreach (wgeasy_tunnel_address_rows($tunnel_name) as $address) {
		$masked_address = "{$address['address']}/{$address['mask']}";

		if (is_subnet($masked_address)) {
			$network = gen_subnet($address['address'], $address['mask']);

			$a_ret[] = "{$network}/{$address['mask']}";
		}
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Returns a sensible default DNS server for clients of a given tunnel, which is
 * the tunnel side address of this firewall. The native package already installs
 * an Unbound ACL for WireGuard networks (see wg_unbound_acl_install()).
 */
function wgeasy_tunnel_dns($tunnel_name) {
	$rows = wgeasy_tunnel_address_rows($tunnel_name);

	foreach ($rows as $address) {
		if (is_ipaddrv4($address['address'])) {
			return $address['address'];
		}
	}

	foreach ($rows as $address) {
		if (is_ipaddrv6($address['address'])) {
			return $address['address'];
		}
	}

	return null;
}

/*
 * DNS presets offered for the client file, formatted for an input selection
 * box. The tunnel address itself is not offered: full tunnel fills it in
 * automatically, and for split tunnel it can be typed by hand.
 */
function wgeasy_get_dns_presets() {
	$a_ret = array();

	// Whatever the firewall itself is configured to use
	$system_dns = array_filter((array) config_get_path('system/dnsserver', []), 'is_ipaddr');

	if (!empty($system_dns)) {
		$a_ret[implode(', ', $system_dns)] = sprintf(gettext('The DNS servers of this firewall (%s)'), implode(', ', $system_dns));
	}

	$public = array(
			'8.8.8.8, 8.8.4.4'	=> gettext('Google Public DNS'),
			'1.1.1.1, 1.0.0.1'	=> gettext('Cloudflare DNS'),
			'9.9.9.9, 149.112.112.112' => gettext('Quad9'));

	foreach ($public as $servers => $label) {
		if (!array_key_exists($servers, $a_ret)) {
			$a_ret[$servers] = "{$label} ({$servers})";
		}
	}

	$a_ret[WGEASY_DNS_NONE] = gettext('None, keep whatever DNS the client already uses');

	return $a_ret;
}

/*
 * Returns the listen port configured on a given tunnel
 */
function wgeasy_tunnel_port($tunnel_name) {
	global $wgg;

	if ([$tun_idx, $tunnel, $is_new] = wg_tunnel_get_config_by_name($tunnel_name)) {
		if (is_port($tunnel['listenport'])) {
			return $tunnel['listenport'];
		}
	}

	return $wgg['default_port'];
}

/*
 * Returns the public key configured on a given tunnel
 */
function wgeasy_tunnel_publickey($tunnel_name) {
	if ([$tun_idx, $tunnel, $is_new] = wg_tunnel_get_config_by_name($tunnel_name)) {
		if (wg_is_valid_key($tunnel['publickey'])) {
			return $tunnel['publickey'];
		}
	}

	return null;
}

/*
 * Returns the system FQDN, or null when no domain is configured
 */
function wgeasy_system_fqdn() {
	$hostname = trim((string) config_get_path('system/hostname'));

	$domain = trim((string) config_get_path('system/domain'));

	return (!empty($hostname) && !empty($domain)) ? "{$hostname}.{$domain}" : null;
}

/*
 * Reads the address a Dynamic DNS entry last registered.
 *
 * pfSense builds this file name by running the hostname through
 * escapeshellarg(), which leaves quotes in the name, so the file is matched
 * with a glob instead of rebuilding the name here.
 */
function wgeasy_dyndns_cached_ip($dyndns) {
	global $g;

	$conf_path = isset($g['conf_path']) ? $g['conf_path'] : '/conf';

	$pattern = "{$conf_path}/dyndns_{$dyndns['interface']}{$dyndns['type']}*{$dyndns['id']}.cache";

	foreach ((array) glob($pattern) as $file) {
		$cached = explode('|', (string) @file_get_contents($file));

		if (is_ipaddr(trim($cached[0]))) {
			return trim($cached[0]);
		}
	}

	return null;
}

/*
 * Returns the Dynamic DNS hostnames already configured on this firewall.
 *
 * Both lists are core pfSense configuration, not packages: DynDNS clients live
 * in dyndnses/dyndns and RFC 2136 clients in dnsupdates/dnsupdate. An entry is
 * enabled when the 'enable' key is present, see services_dyndns.php.
 */
/*
 * pfSense normally parses these tags into a list even for a single entry, but
 * upgraded or hand-edited configs have been seen carrying a lone associative
 * entry instead. Normalize both shapes into a list of arrays.
 */
function wgeasy_config_entries($path, $probe_key) {
	$entries = config_get_path($path, []);

	if (!is_array($entries)) {
		return array();
	}

	if (isset($entries[$probe_key])) {
		$entries = array($entries);
	}

	return array_values(array_filter($entries, 'is_array'));
}

/*
 * Turns whatever sits in a Dynamic DNS host field into a usable hostname:
 * '@' means the bare domain, '*.example.com' covers any name under it, and
 * trailing dots are DNS notation.
 */
function wgeasy_normalize_dyndns_host($hostname) {
	$hostname = rtrim(trim((string) $hostname), '.');

	// '@' and a bare '*' both leave only the domain part
	if (($hostname === '@') || ($hostname === '*')) {
		return '';
	}

	if (substr($hostname, 0, 2) === '*.') {
		$hostname = substr($hostname, 2);
	}

	return $hostname;
}

/*
 * Best effort extraction of the hostname(s) a "Custom" Dynamic DNS entry
 * updates. Custom entries keep the hostname inside the update URL, next to the
 * account token, so only the well known name parameters are read and the URL
 * itself is never surfaced anywhere.
 */
function wgeasy_hostnames_from_updateurl($updateurl) {
	$a_ret = array();

	$updateurl = trim((string) $updateurl);

	if (strlen($updateurl) == 0) {
		return $a_ret;
	}

	$query = parse_url($updateurl, PHP_URL_QUERY);

	$urlhost = strtolower((string) parse_url($updateurl, PHP_URL_HOST));

	if (empty($query)) {
		return $a_ret;
	}

	parse_str($query, $params);

	foreach (array('hostname', 'host', 'domains', 'domain', 'name') as $key) {
		if (empty($params[$key]) || !is_string($params[$key])) {
			continue;
		}

		// DuckDNS style services accept several comma separated names
		foreach (explode(',', $params[$key]) as $candidate) {
			$candidate = wgeasy_normalize_dyndns_host($candidate);

			// DuckDNS also accepts the bare label without its domain
			if ((strpos($candidate, '.') === false)
			    && (strpos($urlhost, 'duckdns.org') !== false)) {
				$candidate .= '.duckdns.org';
			}

			if ((strpos($candidate, '.') !== false) && is_hostname($candidate)) {
				$a_ret[] = $candidate;
			}
		}

		if (!empty($a_ret)) {
			break;
		}
	}

	return array_values(array_unique($a_ret));
}

function wgeasy_get_dyndns_hostnames() {
	global $dyndns_split_domain_types;

	$a_ret = array();

	foreach (wgeasy_config_entries('dyndnses/dyndns', 'type') as $dyndns) {
		if (!isset($dyndns['enable'])) {
			continue;
		}

		$hostname = wgeasy_normalize_dyndns_host($dyndns['host'] ?? '');

		$domainname = wgeasy_normalize_dyndns_host($dyndns['domainname'] ?? '');

		/*
		 * Only some providers split the record into host and domain, and
		 * pfSense keeps that list in a global. Fall back to a suffix check
		 * if the global is not available for some reason.
		 */
		$is_split = is_array($dyndns_split_domain_types)
				? in_array($dyndns['type'], $dyndns_split_domain_types)
				: !empty($domainname);

		if ($is_split
		    && !empty($domainname)
		    && (substr($hostname, -strlen($domainname)) !== $domainname)) {
			$hostname = empty($hostname) ? $domainname : "{$hostname}.{$domainname}";
		}

		/*
		 * "Custom" entries keep their hostname inside the update URL
		 * instead of the host field
		 */
		$hostnames = (empty($hostname) || !is_hostname($hostname))
				? wgeasy_hostnames_from_updateurl($dyndns['updateurl'] ?? '')
				: array($hostname);

		foreach ($hostnames as $hostname) {
			$a_ret[] = array(
					'hostname'	=> $hostname,
					'source'	=> 'dyndns',
					'type'		=> (string) ($dyndns['type'] ?? ''),
					'interface'	=> (string) ($dyndns['interface'] ?? ''),
					'descr'		=> (string) ($dyndns['descr'] ?? ''),
					'cached_ip'	=> wgeasy_dyndns_cached_ip($dyndns));
		}
	}

	foreach (wgeasy_config_entries('dnsupdates/dnsupdate', 'host') as $dnsupdate) {
		if (!isset($dnsupdate['enable'])) {
			continue;
		}

		$hostname = wgeasy_normalize_dyndns_host($dnsupdate['host'] ?? '');

		if (empty($hostname) || !is_hostname($hostname)) {
			continue;
		}

		$a_ret[] = array(
				'hostname'	=> $hostname,
				'source'	=> 'rfc2136',
				'type'		=> 'rfc2136',
				'interface'	=> (string) ($dnsupdate['interface'] ?? ''),
				'descr'		=> (string) ($dnsupdate['descr'] ?? ''),
				'cached_ip'	=> null);
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Returns the local networks behind this firewall, in CIDR notation.
 *
 * Only the interfaces the DHCP server hands addresses on count as local LANs:
 * statically configured helper links (the typical path to the modem, sync
 * interfaces, and the like) carry no DHCP service and do not belong in a
 * client's AllowedIPs. If no interface runs DHCP at all, every gateway-less
 * interface is offered instead so the list is never empty for that reason.
 */
function wgeasy_get_local_networks() {
	$a_ret = array();

	$dhcp_ifs = array();

	foreach (get_configured_interface_with_descr() as $if => $descr) {
		// DHCP enable is presence based, like most pfSense toggles
		if ((config_get_path("dhcpd/{$if}/enable") !== null)
		    || (config_get_path("dhcpdv6/{$if}/enable") !== null)) {
			$dhcp_ifs[] = $if;
		}
	}

	$fallback = empty($dhcp_ifs);

	foreach (get_configured_interface_with_descr() as $if => $descr) {
		// Anything with a gateway is a WAN type interface
		if (!empty(config_get_path("interfaces/{$if}/gateway"))) {
			continue;
		}

		if (!$fallback && !in_array($if, $dhcp_ifs)) {
			continue;
		}

		$address = get_interface_ip($if);

		$bits = get_interface_subnet($if);

		if (is_ipaddrv4($address) && is_numericint($bits) && ($bits > 0) && ($bits < 32)) {
			$a_ret[] = gen_subnet($address, $bits) . "/{$bits}";
		}

		$address6 = get_interface_ipv6($if);

		$bits6 = get_interface_subnetv6($if);

		if (is_ipaddrv6($address6) && is_numericint($bits6) && ($bits6 > 0) && ($bits6 < 128)) {
			$a_ret[] = gen_subnet($address6, $bits6) . "/{$bits6}";
		}
	}

	// Consumers of this function always expect an array type
	return array_values(array_unique($a_ret));
}

/*
 * The networks a road warrior usually needs: everything behind this firewall
 * plus the tunnel network itself
 */
function wgeasy_get_tunneled_networks($tunnel_name) {
	return array_values(array_unique(array_merge(
			wgeasy_tunnel_networks($tunnel_name),
			wgeasy_get_local_networks())));
}

/*
 * Returns the addresses currently assigned to the configured interfaces
 */
function wgeasy_get_interface_addresses() {
	$a_ret = array();

	foreach (get_configured_interface_with_descr() as $if => $descr) {
		$addresses = array(get_interface_ip($if), get_interface_ipv6($if));

		foreach ($addresses as $address) {
			if (!is_ipaddr($address)) {
				continue;
			}

			$a_ret[] = array(
					'address'	=> $address,
					'if'		=> $if,
					'descr'		=> $descr,
					'private'	=> (is_ipaddrv4($address)
								&& function_exists('is_private_ip')
								&& is_private_ip($address)));
		}
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Everything this firewall already knows that a client could dial back to,
 * formatted for an input selection box. Dynamic DNS names come first because
 * they survive a WAN address change.
 */
function wgeasy_get_endpoint_candidates() {
	$a_ret = array();

	/*
	 * The same hostname is commonly registered twice, once for A and once for
	 * AAAA (cloudflare + cloudflare-v6). That is one endpoint, so merge the
	 * entries instead of letting the last one win: either of them may be the
	 * one carrying the registered address.
	 */
	$hostnames = array();

	foreach (wgeasy_get_dyndns_hostnames() as $entry) {
		$key = $entry['hostname'];

		if (!isset($hostnames[$key])) {
			$hostnames[$key] = array(
						'types'		=> array(),
						'rfc2136'	=> false,
						'descr'		=> null,
						'cached_ip'	=> null);
		}

		if ($entry['source'] == 'rfc2136') {
			$hostnames[$key]['rfc2136'] = true;
		} else {
			$hostnames[$key]['types'][] = $entry['type'];
		}

		if (empty($hostnames[$key]['descr']) && !empty($entry['descr'])) {
			$hostnames[$key]['descr'] = $entry['descr'];
		}

		if (empty($hostnames[$key]['cached_ip']) && !empty($entry['cached_ip'])) {
			$hostnames[$key]['cached_ip'] = $entry['cached_ip'];
		}
	}

	foreach ($hostnames as $hostname => $info) {
		$notes = array();

		if (!empty($info['types'])) {
			$notes[] = sprintf(gettext('Dynamic DNS, %s'), implode(' + ', array_unique($info['types'])));
		}

		if ($info['rfc2136']) {
			$notes[] = gettext('RFC 2136');
		}

		if (!empty($info['descr'])) {
			$notes[] = $info['descr'];
		}

		if (!empty($info['cached_ip'])) {
			$notes[] = sprintf(gettext('currently %s'), $info['cached_ip']);
		}

		$a_ret[$hostname] = "{$hostname} (" . implode(', ', $notes) . ')';
	}

	foreach (wgeasy_get_interface_addresses() as $entry) {
		if (array_key_exists($entry['address'], $a_ret)) {
			continue;
		}

		$notes = array($entry['descr']);

		if ($entry['private']) {
			$notes[] = gettext('private address, needs a port forward upstream');
		}

		$a_ret[$entry['address']] = "{$entry['address']} (" . implode(', ', $notes) . ')';
	}

	$fqdn = wgeasy_system_fqdn();

	if (!empty($fqdn) && !array_key_exists($fqdn, $a_ret)) {
		$a_ret[$fqdn] = "{$fqdn} (" . gettext('system hostname') . ')';
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Best effort default for the endpoint remote clients dial back to
 */
function wgeasy_guess_endpoint() {
	$candidates = wgeasy_get_endpoint_candidates();

	// A Dynamic DNS hostname, if there is one, is always the best answer
	foreach (wgeasy_get_dyndns_hostnames() as $entry) {
		return $entry['hostname'];
	}

	// Otherwise the first routable interface address
	foreach (wgeasy_get_interface_addresses() as $entry) {
		if (!$entry['private']) {
			return $entry['address'];
		}
	}

	$fqdn = wgeasy_system_fqdn();

	if (!empty($fqdn)) {
		return $fqdn;
	}

	return empty($candidates) ? null : array_key_first($candidates);
}

/*
 * Formats an endpoint host and port pair, bracketing literal IPv6 addresses
 */
function wgeasy_format_endpoint($host, $port) {
	$host = trim((string) $host);

	if (strlen($host) == 0) {
		return null;
	}

	if (is_ipaddrv6($host)) {
		$host = "[{$host}]";
	}

	return is_port($port) ? "{$host}:{$port}" : $host;
}

/*
 * Parses a comma separated list of addresses into an array of
 * array('address' => ..., 'mask' => ...) entries.
 *
 * Validation is done with the same pfSense primitives wg_validate.inc uses
 * (is_subnet(), is_ipaddrv6()) instead of custom regular expressions.
 */
function wgeasy_parse_addresses($addresses, &$input_errors = array(), $label = 'Address') {
	$a_ret = array();

	foreach (explode(',', (string) $addresses) as $entry) {
		// Remove any accidental whitespace
		$entry = trim($entry);

		if (strlen($entry) == 0) {
			continue;
		}

		if (strpos($entry, '/') !== false) {
			[$address, $mask] = explode('/', $entry, 2);
		} else {
			$address = $entry;

			// Hosts default to a single address
			$mask = is_ipaddrv6($entry) ? '128' : '32';
		}

		$address = trim($address);

		$mask = trim($mask);

		$masked_address = "{$address}/{$mask}";

		if (!is_subnet($masked_address)) {
			$input_errors[] = "{$label} {$masked_address} is not a valid IPv4 or IPv6 CIDR address.";

			continue;
		}

		$a_ret[] = array('address' => $address, 'mask' => $mask);
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Transforms an array of addresses into the repeatable post fields that
 * wg_peer_allowedips() (wg.inc) expects
 */
function wgeasy_addresses_to_post($addresses, $descr = null) {
	$a_ret = array();

	foreach ((array) $addresses as $counter => $address) {
		$a_ret["address{$counter}"]		= $address['address'];
		$a_ret["address_subnet{$counter}"]	= $address['mask'];
		$a_ret["address_descr{$counter}"]	= $descr;
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Formats an array of addresses back into a comma separated line
 */
function wgeasy_addresses_to_line($addresses) {
	$a_ret = array();

	foreach ((array) $addresses as $address) {
		$a_ret[] = "{$address['address']}/{$address['mask']}";
	}

	return implode(', ', $a_ret);
}

/*
 * Returns the next unused IPv4 host address of a given tunnel, formatted as a
 * /32, or null when nothing is available.
 *
 * Addresses already assigned to the tunnel interface itself and to the
 * AllowedIPs of any peer on that tunnel are considered used.
 */
function wgeasy_next_free_address($tunnel_name) {
	global $wgeasyg;

	$rows = wgeasy_tunnel_address_rows($tunnel_name);

	if (empty($rows)) {
		return null;
	}

	$used = array();

	foreach ($rows as $address) {
		if (is_ipaddrv4($address['address'])) {
			$used[] = $address['address'];
		}
	}

	foreach (wg_tunnel_get_peers_config($tunnel_name) as [$peer_idx, $peer, $peer_is_new]) {
		foreach ((array) $peer['allowedips']['row'] as $row) {
			if (is_ipaddrv4($row['address'])) {
				$used[] = $row['address'];
			}
		}
	}

	foreach ($rows as $address) {
		$masked_address = "{$address['address']}/{$address['mask']}";

		if (!is_ipaddrv4($address['address'])
		    || !is_subnet($masked_address)
		    || ($address['mask'] >= 31)) {
			continue;
		}

		$network = gen_subnet($address['address'], $address['mask']);

		$last = gen_subnet_max($address['address'], $address['mask']);

		$candidate = ip_after($network);

		$probes = 0;

		while (is_ipaddrv4($candidate)
		    && ip_less_than($candidate, $last)
		    && ($probes++ < $wgeasyg['max_address_probe'])) {
			if (!in_array($candidate, $used)) {
				return "{$candidate}/32";
			}

			$candidate = ip_after($candidate);
		}
	}

	return null;
}

/*
 * Builds a sanitized .conf file name. WireGuard clients (Windows in particular)
 * only accept tunnel names matching [a-zA-Z0-9_=+.-]{1,15}
 */
function wgeasy_conf_filename($descr, $fallback = 'wgclient') {
	// Idempotent: feeding a built file name back in must not stack extensions
	$descr = preg_replace('/\.conf$/i', '', (string) $descr);

	$name = preg_replace('/[^a-zA-Z0-9_=+.-]/', '-', (string) $descr);

	$name = trim(preg_replace('/-+/', '-', $name), '-.');

	if (strlen($name) == 0) {
		$name = $fallback;
	}

	return substr($name, 0, 15) . '.conf';
}

/*
 * Assembles the client side .conf file. The very same file is valid for the
 * Windows, macOS, Linux, Android and iOS clients.
 */
function wgeasy_build_client_conf($conf) {
	$lines = array();

	if (!empty($conf['descr'])) {
		$lines[] = "# {$conf['descr']}";
	}

	$now = new DateTimeImmutable();

	$hostname = trim((string) config_get_path('system/hostname'));

	$lines[] = "# Generated by {$hostname} on {$now->format('Y-m-d H:i:s')}";
	$lines[] = '# This file contains a private key. Keep it secret.';
	$lines[] = null;

	$lines[] = '[Interface]';
	$lines[] = "PrivateKey = {$conf['privatekey']}";
	$lines[] = "Address = {$conf['address']}";

	if (!empty($conf['dns'])) {
		$lines[] = "DNS = {$conf['dns']}";
	}

	if (!empty($conf['mtu'])) {
		$lines[] = "MTU = {$conf['mtu']}";
	}

	$lines[] = null;

	$lines[] = '[Peer]';
	$lines[] = "PublicKey = {$conf['publickey']}";

	if (!empty($conf['presharedkey'])) {
		$lines[] = "PresharedKey = {$conf['presharedkey']}";
	}

	$lines[] = "AllowedIPs = {$conf['allowedips']}";

	if (!empty($conf['endpoint'])) {
		$lines[] = "Endpoint = {$conf['endpoint']}";
	}

	if (is_numericint($conf['persistentkeepalive']) && ($conf['persistentkeepalive'] > 0)) {
		$lines[] = "PersistentKeepalive = {$conf['persistentkeepalive']}";
	}

	$lines[] = null;

	return implode("\n", $lines);
}

/*
 * Reads the client side settings this page stored on a peer.
 *
 * Peers created by hand under Peers > Edit have none, which is why every
 * consumer has to cope with an empty array.
 */
function wgeasy_peer_store($peer) {
	if (!is_array($peer)) {
		return array();
	}

	if (isset($peer[WGEASY_STORE]) && is_array($peer[WGEASY_STORE])) {
		return $peer[WGEASY_STORE];
	}

	// Peers created before the package was renamed still carry the old element
	if (isset($peer[WGEASY_STORE_LEGACY]) && is_array($peer[WGEASY_STORE_LEGACY])) {
		return $peer[WGEASY_STORE_LEGACY];
	}

	return array();
}

/*
 * True when a peer carries everything needed to rebuild its client file
 */
function wgeasy_peer_is_exportable($peer) {
	$store = wgeasy_peer_store($peer);

	return (!empty($store['privatekey']) && wg_is_valid_key($store['privatekey']));
}

/*
 * Writes the client side settings onto a peer.
 *
 * This runs after wg_do_peer_post() has already saved the peer, so the native
 * validation and sync path is untouched; only this one sub-element is added.
 */
function wgeasy_save_peer_store($peer_idx, $store) {
	if (!is_numericint($peer_idx)
	    || !is_array(config_get_path("installedpackages/wireguard/peers/item/{$peer_idx}"))) {
		return false;
	}

	config_set_path("installedpackages/wireguard/peers/item/{$peer_idx}/" . WGEASY_STORE, $store);

	// Saving migrates a peer off the pre-rename element
	config_del_path("installedpackages/wireguard/peers/item/{$peer_idx}/" . WGEASY_STORE_LEGACY);

	wg_write_config("Saved client settings for peer {$peer_idx}.", true);

	return true;
}

/*
 * Builds the form state for an existing peer.
 *
 * Anything the peer does not carry (a peer created by hand, or one from before
 * this page existed) falls back to the same defaults a new client would get.
 */
function wgeasy_pconfig_from_peer($peer_idx) {
	if (!([$peer_idx, $peer, $is_new] = wg_peer_get_config($peer_idx, false))) {
		return null;
	}

	$store = wgeasy_peer_store($peer);

	$pconfig = wgeasy_default_pconfig($peer['tun']);

	$pconfig['index']	= $peer_idx;
	$pconfig['enabled']	= (isset($peer['enabled']) && ($peer['enabled'] == 'yes')) ? 'yes' : 'no';
	$pconfig['tun']		= $peer['tun'];
	$pconfig['descr']	= $peer['descr'];
	$pconfig['presharedkey']= (string) $peer['presharedkey'];
	$pconfig['address']	= wgeasy_addresses_to_line((array) $peer['allowedips']['row']);

	// The key pair only exists here for peers this page created
	if (!empty($store['privatekey'])) {
		$keypair = wg_gen_publickey($store['privatekey']);

		$pconfig['privatekey']	= $store['privatekey'];
		$pconfig['publickey']	= $keypair['pubkey'];
	} else {
		$pconfig['privatekey']	= '';
		$pconfig['publickey']	= (string) $peer['publickey'];
	}

	foreach (array('dns', 'mtu', 'endpoint', 'port', 'routing', 'persistentkeepalive') as $key) {
		if (isset($store[$key]) && (strlen((string) $store[$key]) > 0)) {
			$pconfig[$key] = $store[$key];
		}
	}

	// A peer created by hand carries its endpoint in the native fields
	if (empty($store['endpoint']) && !empty($peer['endpoint'])) {
		$pconfig['endpoint']	= $peer['endpoint'];
		$pconfig['port']	= $peer['port'];
	}

	if (!empty($store['allowedips'])) {
		$pconfig['client_allowedips'] = $store['allowedips'];
	}

	// An existing peer keeps its DNS as stored, blank included
	if (isset($store['dns'])) {
		$pconfig['dns'] = $store['dns'];
	}

	return $pconfig;
}

/*
 * Rebuilds the client .conf of an existing peer from what was stored on it.
 * Returns array($client_conf, $conf_filename), both null when the peer has no
 * stored private key.
 */
function wgeasy_conf_from_peer($peer_idx) {
	if (!([$peer_idx, $peer, $is_new] = wg_peer_get_config($peer_idx, false))
	    || !wgeasy_peer_is_exportable($peer)) {
		return array(null, null);
	}

	$store = wgeasy_peer_store($peer);

	$client_conf = wgeasy_build_client_conf(array(
				'descr'			=> $peer['descr'],
				'privatekey'		=> $store['privatekey'],
				'address'		=> wgeasy_addresses_to_line((array) $peer['allowedips']['row']),
				'dns'			=> $store['dns'] ?? '',
				'mtu'			=> $store['mtu'] ?? '',
				'publickey'		=> wgeasy_tunnel_publickey($peer['tun']),
				'presharedkey'		=> $peer['presharedkey'],
				'allowedips'		=> $store['allowedips'] ?? '',
				'endpoint'		=> wgeasy_format_endpoint($store['endpoint'] ?? '', $store['port'] ?? ''),
				'persistentkeepalive'	=> $store['persistentkeepalive'] ?? ''));

	return array($client_conf, wgeasy_conf_filename($peer['descr']));
}

/*
 * Returns the default form state, optionally seeded from a tunnel name
 */
function wgeasy_default_pconfig($tun_name = null) {
	global $wgeasyg;

	$tun_list = wgeasy_get_tun_list();

	if (is_null($tun_name) || !array_key_exists($tun_name, $tun_list)) {
		$tun_name = (count($tun_list) > 0) ? array_key_first($tun_list) : null;
	}

	// A fresh client key pair, shown on the form before anything is saved
	$keypair = wg_gen_keypair(false);

	$networks = wgeasy_get_tunneled_networks($tun_name);

	$pconfig = array(
		// Empty means a new peer; an existing one carries its config index
		'index'			=> null,
		'enabled'		=> 'yes',
		'applynow'		=> 'yes',
		'privatekey'		=> $keypair['privkey'],
		'publickey'		=> $keypair['pubkey'],
		// Offer a fresh key, the operator can clear the field to skip it
		'presharedkey'		=> wg_gen_psk(),
		'tun'			=> $tun_name,
		'descr'			=> null,
		'address'		=> wgeasy_next_free_address($tun_name),
		'dns_preset'		=> '',
		/*
		 * Full tunnel routes everything here, so this firewall should also
		 * resolve names; split tunnel keeps the client's own DNS unless the
		 * operator picks one
		 */
		'dns'			=> empty($networks) ? wgeasy_tunnel_dns($tun_name) : null,
		'mtu'			=> null,
		'routing'		=> empty($networks) ? 'full' : 'split',
		'client_allowedips'	=> empty($networks) ? $wgeasyg['default_allowedips'] : implode(', ', $networks),
		'endpoint'		=> wgeasy_guess_endpoint(),
		'port'			=> wgeasy_tunnel_port($tun_name),
		'persistentkeepalive'	=> $wgeasyg['default_keepalive']);

	return $pconfig;
}

/*
 * Per tunnel hints handed to the browser so the form can update itself without
 * a round trip
 */
function wgeasy_tunnel_hints() {
	$a_ret = array();

	foreach (wgeasy_get_tun_list() as $tun_name => $tun_descr) {
		$a_ret[$tun_name] = array(
					'port'		=> wgeasy_tunnel_port($tun_name),
					'dns'		=> wgeasy_tunnel_dns($tun_name),
					'address'	=> wgeasy_next_free_address($tun_name),
					'networks'	=> implode(', ', wgeasy_get_tunneled_networks($tun_name)));
	}

	// Consumers of this function always expect an array type
	return $a_ret;
}

/*
 * Returns the URL of the first available QR code library, or null
 */
function wgeasy_qrcode_js_url() {
	global $wgeasyg;

	foreach ($wgeasyg['qrcode_js'] as $url) {
		$path = "{$wgeasyg['www_root']}{$url}";

		if (file_exists($path)) {
			return "{$url}?v=" . filemtime($path);
		}
	}

	return null;
}

/*
 * Applies any pending WireGuard changes.
 *
 * This is exactly what the "Apply Changes" button of the native pages does, see
 * vpn_wg_peers.php. It intentionally applies every pending tunnel, not just
 * ours, so pending changes made elsewhere are not silently dropped.
 */
function wgeasy_apply_changes() {
	global $wgg;

	$ret_code = 0;

	if (is_subsystem_dirty($wgg['subsystems']['wg'])) {
		if (wg_is_service_running()) {
			$tunnels_to_apply = wg_apply_list_get('tunnels');

			$sync_status = wg_tunnel_sync($tunnels_to_apply, true, true);

			$ret_code |= $sync_status['ret_code'];
		}

		if ($ret_code == 0) {
			clear_subsystem_dirty($wgg['subsystems']['wg']);
		}
	}

	return $ret_code;
}

/*
 * Takes a raw provisioning post, validates it, derives the client public key
 * from the posted private key, saves the peer through the native
 * wg_do_peer_post() and assembles the client .conf file.
 *
 * The client private key is never written to config.xml nor to disk. It only
 * exists in this request and in whatever the operator does with the result.
 */
function wgeasy_do_provision_post($post) {
	global $wgg, $wgeasyg;

	$input_errors = array();

	// Assume no changes will be made...
	$changes = false;

	$tuns_to_sync = array();

	$client_conf = $conf_filename = $peer_idx = null;

	$pconfig = wgeasy_default_pconfig($post['tun'] ?? null);

	// A numeric index means an existing peer is being updated
	$edit_idx = is_numericint($post['index'] ?? null) ? $post['index'] : null;

	$pconfig['index']		= $edit_idx;
	$pconfig['enabled']		= empty($post['enabled']) ? 'no' : 'yes';
	$pconfig['applynow']		= empty($post['applynow']) ? 'no' : 'yes';
	$pconfig['privatekey']		= trim((string) ($post['privatekey'] ?? ''));
	$pconfig['presharedkey']	= trim((string) ($post['presharedkey'] ?? ''));
	$pconfig['tun']			= trim((string) ($post['tun'] ?? ''));
	$pconfig['descr']		= trim((string) ($post['descr'] ?? ''));
	$pconfig['address']		= trim((string) ($post['address'] ?? ''));
	$pconfig['dns']			= trim((string) ($post['dns'] ?? ''));
	$pconfig['dns_preset']		= (string) ($post['dns_preset'] ?? '');
	$pconfig['mtu']			= trim((string) ($post['mtu'] ?? ''));
	$pconfig['routing']		= trim((string) ($post['routing'] ?? 'full'));
	$pconfig['client_allowedips']	= trim((string) ($post['client_allowedips'] ?? ''));
	$pconfig['endpoint']		= trim((string) ($post['endpoint'] ?? ''));
	$pconfig['port']		= trim((string) ($post['port'] ?? ''));
	$pconfig['persistentkeepalive']	= trim((string) ($post['persistentkeepalive'] ?? ''));

	// The preset sentinels are form artefacts, never DNS servers
	if ($pconfig['dns'] === WGEASY_DNS_TUNNEL) {
		$pconfig['dns'] = (string) wgeasy_tunnel_dns($pconfig['tun']);
	}

	if ($pconfig['dns'] === WGEASY_DNS_NONE) {
		$pconfig['dns'] = '';
	}

	// Check the target tunnel
	if (!array_key_exists($pconfig['tun'], wgeasy_get_tun_list())) {
		$input_errors[] = gettext('Select a valid WireGuard tunnel. The tunnel must exist and have a valid key pair.');
	}

	// Check the client name, it is also used to name the .conf file
	if (strlen($pconfig['descr']) == 0) {
		$input_errors[] = gettext('A description must be specified.');
	}

	/*
	 * The key pair comes from the form, so the operator sees it before saving
	 * and can paste one of their own. Same checks wg_validate_tunnel_post()
	 * makes on a tunnel private key.
	 */
	if (strlen($pconfig['privatekey']) == 0) {
		$input_errors[] = gettext('A client private key must be specified.');
	} elseif (!wg_is_valid_key($pconfig['privatekey'])) {
		$input_errors[] = gettext('The client private key specified is not a valid WireGuard private key.');
	} else {
		$keypair = wg_gen_publickey($pconfig['privatekey']);

		$pconfig['publickey'] = $keypair['pubkey'];

		if (!wg_is_valid_key($pconfig['publickey'])) {
			$input_errors[] = gettext('Unable to derive a public key from the client private key.');
		}
	}

	// Check the addresses assigned to the client
	$addresses = wgeasy_parse_addresses($pconfig['address'], $input_errors, 'Client address');

	if (empty($addresses)) {
		$input_errors[] = gettext('At least one client address must be specified.');
	}

	/*
	 * wg_validate_peer_post() only rejects duplicate public keys, not duplicate
	 * addresses, so a provisioning page has to check this itself. Two peers
	 * sharing a tunnel address means traffic only reaches the last one.
	 */
	foreach ($addresses as $address) {
		// The peer is stored as a subnet start boundary, see wg_peer_allowedips()
		$masked_address = gen_subnet($address['address'], $address['mask']) . "/{$address['mask']}";

		foreach (wg_tunnel_get_peers_config($pconfig['tun']) as [$other_idx, $other, $other_is_new]) {
			// A peer keeping its own address while being edited is fine
			if (!is_null($edit_idx) && ($other_idx == $edit_idx)) {
				continue;
			}

			foreach ((array) $other['allowedips']['row'] as $row) {
				if ("{$row['address']}/{$row['mask']}" == $masked_address) {
					$input_errors[] = sprintf(
								gettext('Address %1$s is already assigned to peer "%2$s" on tunnel %3$s.'),
								$masked_address,
								$other['descr'],
								$pconfig['tun']);
				}
			}
		}
	}

	// Check the traffic the client should route into the tunnel
	$client_allowedips = wgeasy_parse_addresses($pconfig['client_allowedips'], $input_errors, 'Allowed IP');

	if (empty($client_allowedips)) {
		$input_errors[] = gettext('At least one allowed IP must be specified for the client.');
	}

	// Check the endpoint this client will connect back to
	if (strlen($pconfig['endpoint']) == 0) {
		$input_errors[] = gettext('An endpoint must be specified.');
	} elseif (!is_hostname($pconfig['endpoint']) && !is_ipaddr($pconfig['endpoint'])) {
		$input_errors[] = "Endpoint {$pconfig['endpoint']} must be a valid IPv4 or IPv6 address or hostname.";
	}

	// Check the endpoint port
	if (!empty($pconfig['port']) && (!ctype_digit($pconfig['port']) || !is_port($pconfig['port']))) {
		$input_errors[] = "Invalid endpoint port ({$pconfig['port']}).";
	}

	// Check the DNS servers, empty is a valid option
	foreach (explode(',', $pconfig['dns']) as $dns) {
		$dns = trim($dns);

		if ((strlen($dns) > 0) && !is_ipaddr($dns) && !is_hostname($dns)) {
			$input_errors[] = "Invalid DNS entry ({$dns}).";
		}
	}

	// Check the MTU, empty is a valid option
	if (!empty($pconfig['mtu'])) {
		if (!is_numericint($pconfig['mtu'])) {
			$input_errors[] = "Invalid MTU ({$pconfig['mtu']}).";
		} elseif ((intval($pconfig['mtu']) < 1280) || (intval($pconfig['mtu']) > 9000)) {
			$input_errors[] = "MTU must be in range 1280-9000 ({$pconfig['mtu']}).";
		}
	}

	// Check persistent keep alive, same rules as wg_validate_peer_post()
	if (!empty($pconfig['persistentkeepalive'])) {
		if (!is_numericint($pconfig['persistentkeepalive'])) {
			$input_errors[] = "Invalid keep alive interval ({$pconfig['persistentkeepalive']}).";
		} elseif ((intval($pconfig['persistentkeepalive']) < 0) || (intval($pconfig['persistentkeepalive']) > 65535)) {
			$input_errors[] = "Keep alive interval must be in range 0-65535 ({$pconfig['persistentkeepalive']}).";
		}
	}

	// Nothing below is safe to run with a broken form
	if (!empty($input_errors)) {
		return array(
			'input_errors'	=> $input_errors,
			'changes'	=> $changes,
			'tuns_to_sync'	=> $tuns_to_sync,
			'pconfig'	=> $pconfig,
			'client_conf'	=> $client_conf,
			'conf_filename'	=> $conf_filename,
			'peer_idx'	=> $peer_idx);
	}

	$presharedkey = empty($pconfig['presharedkey']) ? null : $pconfig['presharedkey'];

	/*
	 * Build a peer post that is indistinguishable from what
	 * vpn_wg_peers_edit.php submits, then let the native package validate and
	 * save it. The keep alive belongs in the client file, not here.
	 *
	 * The endpoint is always stored on the peer, which is what the native page
	 * does with its Dynamic Endpoint box unticked, so the peer list shows the
	 * address the client dials instead of "Dynamic".
	 */
	$peer_post = array(
			'index'			=> $edit_idx,
			'enabled'		=> $pconfig['enabled'],
			'tun'			=> $pconfig['tun'],
			'descr'			=> $pconfig['descr'],
			'endpoint'		=> $pconfig['endpoint'],
			'port'			=> $pconfig['port'],
			'persistentkeepalive'	=> null,
			'publickey'		=> $pconfig['publickey'],
			'presharedkey'		=> $presharedkey);

	$peer_post = array_merge($peer_post, wgeasy_addresses_to_post($addresses, $pconfig['descr']));

	$res = wg_do_peer_post($peer_post);

	$input_errors = array_merge($input_errors, (array) $res['input_errors']);

	if (empty($input_errors)) {
		$changes	= $res['changes'];
		$tuns_to_sync	= $res['tuns_to_sync'];
		$peer_idx	= wg_peer_get_array_idx($pconfig['publickey'], $pconfig['tun']);
		$conf_filename	= wgeasy_conf_filename($pconfig['descr']);

		$pconfig['index'] = $peer_idx;

		/*
		 * Keep everything needed to rebuild this client file later, so the
		 * peer can be edited and re-exported without re-keying the client.
		 * This is the one place a client private key is persisted.
		 */
		wgeasy_save_peer_store($peer_idx, array(
					'privatekey'		=> $pconfig['privatekey'],
					'dns'			=> $pconfig['dns'],
					'mtu'			=> $pconfig['mtu'],
					'endpoint'		=> $pconfig['endpoint'],
					'port'			=> $pconfig['port'],
					'routing'		=> $pconfig['routing'],
					'allowedips'		=> wgeasy_addresses_to_line($client_allowedips),
					'persistentkeepalive'	=> $pconfig['persistentkeepalive']));

		$client_conf = wgeasy_build_client_conf(array(
					'descr'			=> $pconfig['descr'],
					'privatekey'		=> $pconfig['privatekey'],
					'address'		=> wgeasy_addresses_to_line($addresses),
					'dns'			=> $pconfig['dns'],
					'mtu'			=> $pconfig['mtu'],
					'publickey'		=> wgeasy_tunnel_publickey($pconfig['tun']),
					'presharedkey'		=> $presharedkey,
					'allowedips'		=> wgeasy_addresses_to_line($client_allowedips),
					'endpoint'		=> wgeasy_format_endpoint($pconfig['endpoint'], $pconfig['port']),
					'persistentkeepalive'	=> $pconfig['persistentkeepalive']));
	}

	return array(
		'input_errors'	=> $input_errors,
		'changes'	=> $changes,
		'tuns_to_sync'	=> $tuns_to_sync,
		'pconfig'	=> $pconfig,
		'client_conf'	=> $client_conf,
		'conf_filename'	=> $conf_filename,
		'peer_idx'	=> $peer_idx);
}

/*
 * Recovers a client .conf that was handed back by the browser.
 *
 * The payload is base64 encoded on the way out so newlines survive the round
 * trip untouched, and it is sanity checked on the way back in.
 */
function wgeasy_get_posted_conf($post) {
	$client_conf = base64_decode((string) ($post['confdata'] ?? ''), true);

	if (($client_conf === false)
	    || (strpos($client_conf, '[Interface]') === false)
	    || (strpos($client_conf, '[Peer]') === false)) {
		return array(null, null);
	}

	$conf_filename = wgeasy_conf_filename(basename((string) ($post['confname'] ?? '')));

	return array($client_conf, $conf_filename);
}

/*
 * Reads the SMTP notification settings configured under
 * System > Advanced > Notifications
 */
function wgeasy_smtp_settings() {
	$smtp = config_get_path('notifications/smtp', []);

	$settings = array(
			'disabled'	=> isset($smtp['disable']),
			'host'		=> trim((string) ($smtp['ipaddress'] ?? '')),
			'port'		=> (int) ($smtp['port'] ?? 25),
			'timeout'	=> (int) ($smtp['timeout'] ?? 20),
			'ssl'		=> isset($smtp['ssl']),
			'tls'		=> isset($smtp['tls']),
			'validate'	=> isset($smtp['ssl_validate']),
			'authmech'	=> (string) ($smtp['authentication_mech'] ?? 'none'),
			'username'	=> (string) ($smtp['username'] ?? ''),
			'password'	=> (string) ($smtp['password'] ?? ''),
			'from'		=> trim((string) ($smtp['fromaddress'] ?? '')),
			'notify'	=> trim((string) ($smtp['notifyemailaddress'] ?? '')));

	if (empty($settings['from'])) {
		$hostname = trim((string) config_get_path('system/hostname'));

		$domain = trim((string) config_get_path('system/domain'));

		$settings['from'] = "pfsense@{$hostname}.{$domain}";
	}

	if ($settings['port'] <= 0) {
		$settings['port'] = 25;
	}

	return $settings;
}

/*
 * Sends the .conf as a real file attachment using the PHPMailer class pfSense
 * ships for its own notification system.
 *
 * Returns false when PHPMailer is unavailable or the send fails, so the caller
 * can fall back to the plain send_smtp_message() path.
 */
function wgeasy_send_via_phpmailer($to, $subject, $body, $filename, $attachment, &$error = null) {
	$class = null;

	foreach (array('PHPMailer\\PHPMailer\\PHPMailer', 'PHPMailer') as $candidate) {
		if (class_exists($candidate)) {
			$class = $candidate;

			break;
		}
	}

	if (is_null($class)) {
		$error = gettext('PHPMailer is not available.');

		return false;
	}

	$smtp = wgeasy_smtp_settings();

	if (empty($smtp['host'])) {
		$error = gettext('No SMTP server is configured.');

		return false;
	}

	try {
		$mail = new $class(true);

		$mail->isSMTP();
		$mail->Host		= $smtp['host'];
		$mail->Port		= $smtp['port'];
		$mail->Timeout		= ($smtp['timeout'] > 0) ? $smtp['timeout'] : 20;
		$mail->CharSet		= 'UTF-8';

		if ($smtp['tls']) {
			$mail->SMTPSecure = 'tls';
		} elseif ($smtp['ssl']) {
			$mail->SMTPSecure = 'ssl';
		}

		if (!$smtp['validate']) {
			$mail->SMTPOptions = array('ssl' => array(
						'verify_peer'		=> false,
						'verify_peer_name'	=> false,
						'allow_self_signed'	=> true));
		}

		if (($smtp['authmech'] != 'none') && !empty($smtp['username'])) {
			$mail->SMTPAuth	= true;
			$mail->Username	= $smtp['username'];
			$mail->Password	= $smtp['password'];
		}

		$mail->setFrom($smtp['from']);
		$mail->addAddress($to);
		$mail->Subject = $subject;
		$mail->Body = $body;
		$mail->addStringAttachment($attachment, $filename, 'base64', 'text/plain');

		return (bool) $mail->send();
	} catch (Throwable $e) {
		$error = $e->getMessage();

		return false;
	}
}

/*
 * Sends the .conf inline in the message body using the native pfSense
 * send_smtp_message(), which has no attachment support.
 *
 * send_smtp_message() always delivers to the address configured under
 * System > Advanced > Notifications, so the recipient is swapped in the
 * in-memory configuration for the duration of the call only. Nothing is
 * written to config.xml.
 */
function wgeasy_send_via_notices($to, $subject, $body, $filename, $attachment, &$error = null) {
	if (!function_exists('send_smtp_message')) {
		@require_once('notices.inc');
	}

	if (!function_exists('send_smtp_message')) {
		$error = gettext('send_smtp_message() is not available on this system.');

		return false;
	}

	$lines = array(
			$body,
			null,
			"----- BEGIN {$filename} -----",
			$attachment,
			"----- END {$filename} -----",
			null);

	$message = implode("\n", $lines);

	$original = config_get_path('notifications/smtp/notifyemailaddress', '');

	config_set_path('notifications/smtp/notifyemailaddress', $to);

	try {
		$res = send_smtp_message($message, $subject);
	} catch (Throwable $e) {
		$error = $e->getMessage();

		$res = false;
	} finally {
		// Always restore the system notification address
		config_set_path('notifications/smtp/notifyemailaddress', $original);
	}

	return ($res !== false);
}

/*
 * Emails a client configuration.
 *
 * A real file attachment is used when possible, otherwise the configuration is
 * delivered inline in the message body through the native notification helper.
 */
function wgeasy_send_conf_email($to, $client_conf, $conf_filename, $descr = null) {
	$to = trim((string) $to);

	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
		return array('success' => false, 'attached' => false, 'message' => gettext('A valid email address must be specified.'));
	}

	if (empty($client_conf)) {
		return array('success' => false, 'attached' => false, 'message' => gettext('There is no client configuration to send.'));
	}

	$smtp = wgeasy_smtp_settings();

	if (empty($smtp['host'])) {
		return array('success' => false, 'attached' => false, 'message' => gettext('SMTP is not configured. Configure it under System > Advanced > Notifications.'));
	}

	$hostname = trim((string) config_get_path('system/hostname'));

	$descr = str_replace(array("\r", "\n"), ' ', (string) $descr);

	$subject = empty($descr) ? "WireGuard client configuration ({$hostname})" : "WireGuard client configuration: {$descr}";

	$lines = array(
			'Hello,',
			null,
			"The WireGuard client configuration \"{$conf_filename}\" generated on {$hostname} is",
			'attached to this message, or included at the end of it.',
			null,
			'How to use it:',
			'  Windows / macOS : WireGuard > Add Tunnel > Import tunnel(s) from file',
			'  Android / iOS   : WireGuard > + > Import from file or archive, or scan the QR code',
			"  Linux           : save it as /usr/local/etc/wireguard/{$conf_filename} and run wg-quick up",
			null,
			'This file contains the private key of the client. Treat it as a secret and',
			'delete this message once the tunnel has been imported.',
			null);

	$body = implode("\n", $lines);

	$error = null;

	// A real attachment is preferred...
	if (wgeasy_send_via_phpmailer($to, $subject, $body, $conf_filename, $client_conf, $error)) {
		return array('success' => true, 'attached' => true, 'message' => sprintf(gettext('Configuration sent to %s as an attachment.'), $to));
	}

	// ...but the native notification helper always works
	if (wgeasy_send_via_notices($to, $subject, $body, $conf_filename, $client_conf, $error)) {
		return array('success' => true, 'attached' => false, 'message' => sprintf(gettext('Configuration sent to %s. The file was included in the message body because the native pfSense mailer does not support attachments.'), $to));
	}

	$hint = empty($error) ? gettext('Verify the SMTP settings under System > Advanced > Notifications.') : $error;

	return array('success' => false, 'attached' => false, 'message' => sprintf(gettext('Unable to send the configuration to %1$s. %2$s'), $to, $hint));
}

?>
