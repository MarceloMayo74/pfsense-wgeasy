<?php
/*
 * End to end verification, no browser needed.
 *
 *   php -f preview/verify.php
 *
 * Provisions a client through the real wg_do_peer_post(), then checks that the
 * artefacts actually agree with each other:
 *
 *   1. the private key in the client .conf derives to the public key stored on
 *      the peer in the configuration
 *   2. the [Peer] PublicKey in the client .conf is the tunnel public key
 *   3. the peer really landed in the tunnel .conf written by wgconfig.class.php
 *   4. a second client cannot take the same tunnel address
 *   5. the email path produces a message
 */

$_SERVER['REQUEST_METHOD']	= 'POST';
$_SERVER['REQUEST_URI']		= '/wgeasy/vpn_wg_easy_edit.php';
$_SERVER['SCRIPT_NAME']		= '/wgeasy/vpn_wg_easy_edit.php';
$_SERVER['HTTP_HOST']		= 'localhost';

require_once(__DIR__ . '/bootstrap.php');

$page = WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/vpn_wg_easy_edit.php';

$pass = $fail = 0;

function check($label, $condition, $detail = '') {
	global $pass, $fail;

	if ($condition) {
		$pass++;

		fwrite(STDOUT, "  PASS  {$label}\n");
	} else {
		$fail++;

		fwrite(STDOUT, "  FAIL  {$label}" . (strlen($detail) ? "  ({$detail})" : '') . "\n");
	}
}

function render($post) {
	global $page;

	/*
	 * The key pair now comes from the form. Each provisioning render gets a
	 * fresh one unless the test supplies its own, since duplicate public keys
	 * on one tunnel are rejected by the native validation.
	 */
	if ((($post['act'] ?? '') == 'provision') && !isset($post['privatekey'])) {
		$post['privatekey'] = wg_gen_keypair(false)['privkey'];
	}

	$_POST = $post;
	$_REQUEST = $post;

	ob_start();

	include($page);

	return ob_get_clean();
}

function conf_from_html($html) {
	if (preg_match('#<pre id="wgeasy_conf"[^>]*>(.*?)</pre>#s', $html, $m)) {
		return html_entity_decode($m[1], ENT_QUOTES);
	}

	return null;
}

function conf_value($conf, $attr, $section = null) {
	$in_section = is_null($section);

	foreach (explode("\n", (string) $conf) as $line) {
		$line = trim($line);

		if (preg_match('/^\[(\w+)\]$/', $line, $m)) {
			$in_section = is_null($section) ? true : (strcasecmp($m[1], $section) == 0);

			continue;
		}

		if ($in_section && preg_match('/^' . preg_quote($attr, '/') . '\s*=\s*(.+)$/i', $line, $m)) {
			return trim($m[1]);
		}
	}

	return null;
}

function derive_pubkey($privkey_b64) {
	$raw = base64_decode($privkey_b64, true);

	if (($raw === false) || (strlen($raw) != 32)) {
		return null;
	}

	return base64_encode(sodium_crypto_box_publickey_from_secretkey($raw));
}

// Start from a clean state
foreach (array('/config.json', '/changelog.txt', '/fakebin.log') as $file) {
	@unlink(WGEASY_PREVIEW_VAR . $file);
}

foreach (array_merge(glob(WGEASY_PREVIEW_VAR . '/conf/*'), glob(WGEASY_PREVIEW_VAR . '/mail/*'), glob(WGEASY_PREVIEW_VAR . '/tmp/*')) as $file) {
	@unlink($file);
}

wgeasy_preview_seed();

config_read_file();

$base_post = array(
	'act'			=> 'provision',
	'enabled'		=> 'yes',
	'applynow'		=> 'yes',
	'presharedkey'		=> wg_gen_psk(),
	'tun'			=> 'tun_wg0',
	'descr'			=> 'celular-marcelo',
	'address'		=> '10.6.0.3/32',
	'dns'			=> '10.6.0.1',
	'mtu'			=> '',
	'routing'		=> 'full',
	'client_allowedips'	=> '0.0.0.0/0, ::/0',
	'endpoint'		=> 'vpn.casa.example.com',
	'port'			=> '51820',
	'persistentkeepalive'	=> '25');

fwrite(STDOUT, "\n== 1. provision a client ==\n");

$html = render($base_post);

$conf = conf_from_html($html);

check('the page produced a client configuration', !empty($conf));

check('the page reports success', strpos($html, 'alert-success') !== false);

check('no input errors', strpos($html, 'input-errors') === false);

if (empty($conf)) {
	fwrite(STDOUT, "\nAborting, nothing else can be checked.\n");
	exit(1);
}

$client_privkey	= conf_value($conf, 'PrivateKey', 'Interface');
$client_address	= conf_value($conf, 'Address', 'Interface');
$server_pubkey	= conf_value($conf, 'PublicKey', 'Peer');
$psk		= conf_value($conf, 'PresharedKey', 'Peer');
$endpoint	= conf_value($conf, 'Endpoint', 'Peer');

config_read_file();

$peers = config_get_path('installedpackages/wireguard/peers/item', []);

$saved = null;

foreach ($peers as $idx => $peer) {
	if ($peer['descr'] == 'celular-marcelo') {
		$saved = $peer;

		$saved_idx = $idx;
	}
}

fwrite(STDOUT, "\n== 2. key material agrees ==\n");

check('the peer was saved to the configuration', !is_null($saved));

check('the client private key derives to the public key stored on the peer',
	!is_null($saved) && (derive_pubkey($client_privkey) === $saved['publickey']),
	'derived ' . derive_pubkey($client_privkey) . ' vs stored ' . ($saved['publickey'] ?? 'none'));

check('the [Peer] PublicKey is the tunnel public key',
	$server_pubkey === config_get_path('installedpackages/wireguard/tunnels/item/0/publickey'));

/*
 * Re-export was chosen over never persisting the key, so the private key is
 * stored deliberately, but only inside this page's own sub-element and never
 * on a native peer field.
 */
check('the client private key is stored for re-export',
	!is_null($saved) && (($saved[WGEASY_STORE]['privatekey'] ?? null) === $client_privkey));

check('it is stored only under the namespaced element, not as a peer field',
	!is_null($saved) && !isset($saved['privatekey']));

check('the peer is flagged as exportable', wgeasy_peer_is_exportable($saved));

// Peers written before the wgprov -> wgeasy rename must keep working
$legacy = $saved;

unset($legacy[WGEASY_STORE]);

$legacy[WGEASY_STORE_LEGACY] = $saved[WGEASY_STORE];

check('a peer stored under the pre-rename element is still readable',
	wgeasy_peer_store($legacy)['privatekey'] === $client_privkey);

check('and is still exportable', wgeasy_peer_is_exportable($legacy));

check('the client settings needed to rebuild the file are stored too',
	!is_null($saved)
	&& (($saved[WGEASY_STORE]['endpoint'] ?? null) === 'vpn.casa.example.com')
	&& (($saved[WGEASY_STORE]['port'] ?? null) === '51820')
	&& (($saved[WGEASY_STORE]['allowedips'] ?? null) === '0.0.0.0/0, ::/0'));

check('the pre-shared key on the peer matches the one in the client file',
	!is_null($saved) && ($psk === $saved['presharedkey']));

// The form below the result panel must offer FRESH keys, not the used ones
check('after saving, the form no longer shows the used private key',
	preg_match('/name="privatekey"[^>]*value="([^"]*)"/', $html, $mfresh)
	&& wg_is_valid_key($mfresh[1])
	&& ($mfresh[1] !== $client_privkey));

check('after saving, the form address moved past the assigned one',
	preg_match('/name="address"[^>]*value="([^"]*)"/', $html, $mfaddr)
	&& ($mfaddr[1] !== '10.6.0.3/32'),
	$mfaddr[1] ?? 'none');

// The panel's hidden descr keeps the saved name; the form's text input clears
check('after saving, the description is cleared for the next client',
	preg_match('/type="text" name="descr"[^>]*value="([^"]*)"/', $html, $mfdescr)
	&& ($mfdescr[1] === ''));

check('the email form still carries the saved client name',
	preg_match('/type="hidden" name="descr" value="([^"]*)"/', $html, $mhdescr)
	&& ($mhdescr[1] === 'celular-marcelo'));

fwrite(STDOUT, "\n== 3. addresses and endpoint ==\n");

check('the client Address is what was requested', $client_address === '10.6.0.3/32');

check('the peer AllowedIPs on the firewall is the client address',
	!is_null($saved) && ($saved['allowedips']['row'][0]['address'] === '10.6.0.3')
	&& ($saved['allowedips']['row'][0]['mask'] === '32'));

check('the endpoint carries host and port', $endpoint === 'vpn.casa.example.com:51820');

// The endpoint is always stored on the peer, so the list column shows it
check('the endpoint is stored on the peer',
	!is_null($saved) && ($saved['endpoint'] === 'vpn.casa.example.com')
	&& ($saved['port'] === '51820'),
	($saved['endpoint'] ?? 'none') . ':' . ($saved['port'] ?? 'none'));

check('the peer list shows it instead of Dynamic',
	wg_format_endpoint(false, $saved) === 'vpn.casa.example.com:51820');

check('reopening the peer brings the endpoint back to the form',
	wgeasy_pconfig_from_peer($saved_idx)['endpoint'] === 'vpn.casa.example.com');

// A peer made by hand with a native endpoint must load it too
$native_ep = array(
	'enabled'	=> 'yes',
	'tun'		=> 'tun_wg0',
	'descr'		=> 'con-endpoint-nativo',
	'endpoint'	=> 'sucursal.example.com',
	'port'		=> '51821',
	'publickey'	=> wg_gen_keypair(false)['pubkey'],
	'presharedkey'	=> '');

$native_ep['allowedips']['row'] = array(array('address' => '10.6.0.88', 'mask' => '32', 'descr' => ''));

$native_idx = max(array_keys(config_get_path('installedpackages/wireguard/peers/item', []))) + 1;

config_set_path("installedpackages/wireguard/peers/item/{$native_idx}", $native_ep);

write_config('Preview: peer with a native endpoint.');

$native_pconfig = wgeasy_pconfig_from_peer($native_idx);

check('a hand made peer loads its own endpoint, not the guessed one',
	($native_pconfig['endpoint'] === 'sucursal.example.com')
	&& ($native_pconfig['port'] === '51821'),
	$native_pconfig['endpoint'] . ':' . $native_pconfig['port']);

config_del_path("installedpackages/wireguard/peers/item/{$native_idx}");

write_config('Preview: drop the native endpoint peer.');

fwrite(STDOUT, "\n== 4. the tunnel .conf written by wgconfig.class.php ==\n");

$tunnel_conf = @file_get_contents(WGEASY_PREVIEW_VAR . '/conf/tun_wg0.conf');

check('tun_wg0.conf was written', !empty($tunnel_conf));

check('it contains the new peer public key',
	!is_null($saved) && (strpos((string) $tunnel_conf, $saved['publickey']) !== false));

check('it does not contain the client private key',
	strpos((string) $tunnel_conf, $client_privkey) === false);

fwrite(STDOUT, "\n== 5. a duplicate address is rejected ==\n");

$before_dup = count(config_get_path('installedpackages/wireguard/peers/item', []));

$html2 = render(array_merge($base_post, array('descr' => 'otro-cliente')));

check('the second client with the same address is rejected', strpos($html2, 'input-errors') !== false);

check('the error names the address', strpos($html2, '10.6.0.3/32') !== false);

config_read_file();

check('no second peer was created',
	count(config_get_path('installedpackages/wireguard/peers/item', [])) == $before_dup,
	'before ' . $before_dup . ', after ' . count(config_get_path('installedpackages/wireguard/peers/item', [])));

fwrite(STDOUT, "\n== 6. a different address is accepted ==\n");

$html3 = render(array_merge($base_post, array('descr' => 'tablet', 'address' => '10.6.0.4/32')));

check('the third client is accepted', strpos($html3, 'input-errors') === false);

check('it produced its own configuration', !empty(conf_from_html($html3)));

check('its private key differs from the first client',
	conf_value(conf_from_html($html3), 'PrivateKey', 'Interface') !== $client_privkey);

fwrite(STDOUT, "\n== 7. email delivery ==\n");

$html4 = render(array(
		'act'		=> 'email',
		'confdata'	=> base64_encode($conf),
		'confname'	=> 'celular-marcel.conf',
		'descr'		=> 'celular-marcelo',
		'tun'		=> 'tun_wg0',
		'email'		=> 'marcelo@example.com'));

$mails = glob(WGEASY_PREVIEW_VAR . '/mail/*.eml');

check('a message was produced', count($mails) == 1);

$eml = count($mails) ? file_get_contents($mails[0]) : '';

check('it is addressed to the recipient typed in the form', strpos($eml, 'To: marcelo@example.com') !== false);

check('it carries the client configuration', strpos($eml, $client_privkey) !== false);

check('the system notification address was restored afterwards',
	config_get_path('notifications/smtp/notifyemailaddress') === 'admin@example.com');

check('the page reports the send', strpos($html4, 'alert-success') !== false);

fwrite(STDOUT, "\n== 8. a bad address is rejected ==\n");

$html5 = render(array_merge($base_post, array('descr' => 'roto', 'address' => '10.6.0.999/32')));

check('an invalid client address is rejected', strpos($html5, 'input-errors') !== false);

$html6 = render(array_merge($base_post, array('descr' => 'roto2', 'address' => '10.6.0.5/32', 'endpoint' => 'no es un host')));

check('an invalid endpoint is rejected', strpos($html6, 'input-errors') !== false);

fwrite(STDOUT, "\n== 9. dual stack client on a split tunnel ==\n");

$html7 = render(array_merge($base_post, array(
		'descr'			=> 'sucursal-norte',
		'tun'			=> 'tun_wg1',
		'address'		=> '10.7.0.10/32, fd00:7::10/128',
		'dns'			=> '10.7.0.1',
		'mtu'			=> '1380',
		'port'			=> '51821',
		'routing'		=> 'split',
		'client_allowedips'	=> '10.7.0.0/24, fd00:7::/64')));

$conf7 = conf_from_html($html7);

check('the dual stack client was accepted', !empty($conf7), 'page reported errors');

check('both addresses are in the client file',
	conf_value($conf7, 'Address', 'Interface') === '10.7.0.10/32, fd00:7::10/128');

check('AllowedIPs carries both tunnel networks',
	conf_value($conf7, 'AllowedIPs', 'Peer') === '10.7.0.0/24, fd00:7::/64');

check('the MTU is written', conf_value($conf7, 'MTU', 'Interface') === '1380');

check('the endpoint uses the tunnel listen port',
	conf_value($conf7, 'Endpoint', 'Peer') === 'vpn.casa.example.com:51821');

check('the [Peer] PublicKey is the second tunnel public key',
	conf_value($conf7, 'PublicKey', 'Peer') === config_get_path('installedpackages/wireguard/tunnels/item/1/publickey'));

config_read_file();

$saved7 = null;

foreach (config_get_path('installedpackages/wireguard/peers/item', []) as $peer) {
	if ($peer['descr'] == 'sucursal-norte') {
		$saved7 = $peer;
	}
}

check('the peer stores both AllowedIPs rows',
	!is_null($saved7) && (count($saved7['allowedips']['row']) == 2));

check('the peer landed on the right tunnel', !is_null($saved7) && ($saved7['tun'] == 'tun_wg1'));

$tunnel1_conf = @file_get_contents(WGEASY_PREVIEW_VAR . '/conf/tun_wg1.conf');

check('tun_wg1.conf lists both allowed IPs',
	(strpos((string) $tunnel1_conf, '10.7.0.10/32') !== false)
	&& (strpos((string) $tunnel1_conf, 'fd00:7::10/128') !== false));

fwrite(STDOUT, "\n== 10. no tunnels configured ==\n");

$saved_tunnels = config_get_path('installedpackages/wireguard/tunnels/item', []);

config_set_path('installedpackages/wireguard/tunnels/item', array());

write_config('Preview: temporarily remove all tunnels.');

$_SERVER['REQUEST_METHOD'] = 'GET';

$_POST = array();

ob_start();

include($page);

$html8 = ob_get_clean();

check('the page still renders without tunnels', strlen($html8) > 1000);

check('it tells the operator to create a tunnel first', strpos($html8, 'no WireGuard tunnels available') !== false);

config_set_path('installedpackages/wireguard/tunnels/item', $saved_tunnels);

write_config('Preview: restore tunnels.');

$_SERVER['REQUEST_METHOD'] = 'POST';

fwrite(STDOUT, "\n== 11. what the firewall already knows ==\n");

$ddns = wgeasy_get_dyndns_hostnames();

$ddns_names = array_map(fn($x) => $x['hostname'], $ddns);

check('a split domain provider is joined into an FQDN',
	in_array('vpn.casa.example.com', $ddns_names), implode(', ', $ddns_names));

check('a non split provider keeps its hostname as stored',
	in_array('marcelo.ddns.net', $ddns_names));

check('a disabled Dynamic DNS entry is ignored',
	!in_array('viejo.duckdns.org', $ddns_names));

check('an RFC 2136 entry is picked up',
	in_array('wg.interna.example.com', $ddns_names));

check('the address the Dynamic DNS entry last registered is read from its cache file',
	$ddns[0]['cached_ip'] === '203.0.113.10', 'got ' . var_export($ddns[0]['cached_ip'], true));

$candidates = wgeasy_get_endpoint_candidates();

check('the WAN address is offered as an endpoint', array_key_exists('203.0.113.10', $candidates));

check('the WAN IPv6 address is offered too', array_key_exists('2001:db8:1::10', $candidates));

check('the private LAN address is offered but flagged',
	array_key_exists('192.168.1.1', $candidates)
	&& (strpos($candidates['192.168.1.1'], 'port forward') !== false));

check('a Dynamic DNS name is the default endpoint, not a literal address',
	wgeasy_guess_endpoint() === 'vpn.casa.example.com', 'got ' . wgeasy_guess_endpoint());

// Host field shapes seen in the wild: apex '@', wildcard, trailing dot
$saved_ddns = config_get_path('dyndnses/dyndns', []);

config_set_path('dyndnses/dyndns', array(
	array('type' => 'cloudflare', 'host' => '@', 'domainname' => 'apex.example.com',
		'interface' => 'wan', 'enable' => '', 'descr' => 'Apex', 'id' => '9'),
	array('type' => 'namecheap', 'host' => '*', 'domainname' => 'wild.example.com',
		'interface' => 'wan', 'enable' => '', 'descr' => 'Wild', 'id' => '10'),
	array('type' => 'duckdns', 'host' => 'punto.duckdns.org.',
		'interface' => 'wan', 'enable' => '', 'descr' => 'Trailing dot', 'id' => '11')));

$odd = array_map(fn($x) => $x['hostname'], wgeasy_get_dyndns_hostnames());

check('an apex @ entry resolves to the bare domain',
	in_array('apex.example.com', $odd), implode(', ', $odd));

check('a bare * wildcard resolves to the domain', in_array('wild.example.com', $odd));

check('a trailing dot is stripped', in_array('punto.duckdns.org', $odd));

// "Custom" entries: empty host field, hostname buried in the update URL
config_set_path('dyndnses/dyndns', array(
	array('type' => 'custom', 'host' => '',
		'updateurl' => 'https://www.duckdns.org/update?domains=milabel&token=SECRETO123&ip=%IP%',
		'interface' => 'wan', 'enable' => '', 'descr' => 'DuckDNS custom', 'id' => '12'),
	array('type' => 'custom', 'host' => '',
		'updateurl' => 'https://user:clave@members.dyndns.example/nic/update?hostname=casa.dyndns.example&myip=%IP%',
		'interface' => 'wan', 'enable' => '', 'descr' => 'dyndns2 custom', 'id' => '13'),
	array('type' => 'custom', 'host' => '',
		'updateurl' => 'https://freedns.afraid.org/dynamic/update.php?U0VDUkVUVE9LRU4',
		'interface' => 'wan', 'enable' => '', 'descr' => 'Solo token', 'id' => '14')));

// Only the dyndns sourced entries; the seed also carries an RFC 2136 one
$custom = array_map(fn($x) => $x['hostname'],
		array_filter(wgeasy_get_dyndns_hostnames(), fn($x) => $x['source'] == 'dyndns'));

check('a DuckDNS custom URL yields the full hostname',
	in_array('milabel.duckdns.org', $custom), implode(', ', $custom));

check('a dyndns2 custom URL yields its hostname param',
	in_array('casa.dyndns.example', $custom));

check('a token-only custom URL yields nothing instead of garbage',
	count($custom) == 2, implode(', ', $custom));

check('the secret token never reaches the endpoint labels',
	strpos(implode(' ', array_values(wgeasy_get_endpoint_candidates())), 'SECRETO123') === false);

// A lone associative entry (no list wrapper) must still be read
config_set_path('dyndnses/dyndns', array(
	'type' => 'noip', 'host' => 'solo.ddns.net',
	'interface' => 'wan', 'enable' => '', 'descr' => 'Sola', 'id' => ''));

$lone = array_map(fn($x) => $x['hostname'], wgeasy_get_dyndns_hostnames());

check('a single associative Dynamic DNS entry is still detected',
	in_array('solo.ddns.net', $lone), implode(', ', $lone));

config_set_path('dnsupdates/dnsupdate', array(
	'host' => 'unico.interna.example.com', 'server' => '192.168.1.5',
	'interface' => 'wan', 'enable' => '', 'descr' => 'RFC solo'));

$lone2 = array_map(fn($x) => $x['hostname'], wgeasy_get_dyndns_hostnames());

check('a single associative RFC 2136 entry is still detected',
	in_array('unico.interna.example.com', $lone2), implode(', ', $lone2));

config_set_path('dyndnses/dyndns', $saved_ddns);

config_set_path('dnsupdates/dnsupdate', array(array(
	'host' => 'wg.interna.example.com', 'server' => '192.168.1.5',
	'interface' => 'wan', 'enable' => '', 'descr' => 'BIND interno')));

// The same hostname registered on both A and AAAA is one endpoint, not two
$with_v6 = config_get_path('dyndnses/dyndns', []);

$with_v6[] = array('type' => 'cloudflare-v6', 'host' => 'vpn', 'domainname' => 'casa.example.com',
			'interface' => 'wan', 'enable' => '', 'descr' => 'VPN IPv6', 'id' => '3');

config_set_path('dyndnses/dyndns', $with_v6);

$merged = wgeasy_get_endpoint_candidates();

check('a hostname registered on both A and AAAA appears once',
	count(array_keys($merged, $merged['vpn.casa.example.com'])) == 1);

check('the merged option lists both providers',
	strpos($merged['vpn.casa.example.com'], 'cloudflare + cloudflare-v6') !== false,
	$merged['vpn.casa.example.com']);

check('the merged option keeps the registered address from whichever entry has it',
	strpos($merged['vpn.casa.example.com'], 'currently 203.0.113.10') !== false,
	$merged['vpn.casa.example.com']);

config_set_path('dyndnses/dyndns', array_slice($with_v6, 0, -1));

$dns_presets = wgeasy_get_dns_presets();

check('Google Public DNS is offered', array_key_exists('8.8.8.8, 8.8.4.4', $dns_presets));

check('the tunnel address preset is no longer offered',
	!array_key_exists(WGEASY_DNS_TUNNEL, $dns_presets));

// Split routing is the default, so the client keeps its own DNS until chosen
check('with the split default the DNS field starts empty',
	is_null(wgeasy_default_pconfig('tun_wg0')['dns'])
	&& (wgeasy_default_pconfig('tun_wg0')['dns_preset'] === ''),
	var_export(wgeasy_default_pconfig('tun_wg0')['dns'], true));

check('the DNS servers of the firewall itself are offered',
	array_key_exists('192.168.1.5, 1.1.1.1', $dns_presets));

check('a "none" option is offered under its own sentinel',
	array_key_exists(WGEASY_DNS_NONE, $dns_presets));

check('no preset uses the empty value, which belongs to the prompt',
	!array_key_exists('', $dns_presets));

$html_none = render(array_merge($base_post, array(
		'descr'		=> 'sin-dns',
		'address'	=> '10.6.0.40/32',
		'dns_preset'	=> WGEASY_DNS_NONE,
		'dns'		=> WGEASY_DNS_NONE)));

check('choosing none leaves no DNS line in the client file',
	is_null(conf_value(conf_from_html($html_none), 'DNS', 'Interface')));

$html9 = render(array_merge($base_post, array(
		'descr'		=> 'cliente-google',
		'address'	=> '10.6.0.30/32',
		'dns_preset'	=> '8.8.8.8, 8.8.4.4',
		'dns'		=> '8.8.8.8, 8.8.4.4')));

check('a client with Google DNS is accepted', strpos($html9, 'input-errors') === false);

check('the client file carries the Google resolvers',
	conf_value(conf_from_html($html9), 'DNS', 'Interface') === '8.8.8.8, 8.8.4.4');

$html10 = render(array_merge($base_post, array(
		'descr'		=> 'cliente-sentinela',
		'address'	=> '10.6.0.31/32',
		'dns_preset'	=> WGEASY_DNS_TUNNEL,
		'dns'		=> WGEASY_DNS_TUNNEL)));

// A stale form may still post the retired sentinel; it must translate, never
// end up written literally as a DNS server
check('a stale form posting the old tunnel sentinel still gets the tunnel address',
	conf_value(conf_from_html($html10), 'DNS', 'Interface') === '10.6.0.1',
	'got ' . var_export(conf_value(conf_from_html($html10), 'DNS', 'Interface'), true));

fwrite(STDOUT, "\n== 12. the form mirrors the native Peers page ==\n");

$_SERVER['REQUEST_METHOD'] = 'GET';

$_POST = array();

ob_start();

include($page);

$form_html = ob_get_clean();

$_SERVER['REQUEST_METHOD'] = 'POST';

foreach (array('Peer Configuration', 'Address Configuration', 'Client Configuration') as $panel) {
	check("the {$panel} panel is present", strpos($form_html, $panel) !== false);
}

check('the removed readiness panel is gone',
	strpos($form_html, 'already provides') === false);

foreach (array('enabled', 'tun', 'descr', 'persistentkeepalive', 'presharedkey', 'address') as $field) {
	check("the native field '{$field}' is present",
		strpos($form_html, "name=\"{$field}\"") !== false);
}

check('the Generate button for the pre-shared key is present',
	strpos($form_html, 'id="genpsk"') !== false);

check('the pre-shared key is pre-filled with a valid key',
	preg_match('/name="presharedkey"[^>]*value="([^"]+)"/', $form_html, $m) && wg_is_valid_key($m[1]),
	$m[1] ?? 'none');

check('the Save Peer button is inside the form',
	preg_match('/<button[^>]*id="saveform".*?<\/form>/s', $form_html) === 1);

// The client file panel sits after the form, below the Save button
check('the result panel is rendered below the Save button',
	strpos($html, 'id="wgeasy_conf"') > strpos($html, 'id="saveform"'),
	'conf at ' . strpos($html, 'id="wgeasy_conf"') . ', save at ' . strpos($html, 'id="saveform"'));

check('the result panel carries a Download button that posts the file back',
	preg_match('/<input type="hidden" name="act" value="download"[^>]*>.*?<button type="submit"/s', $html) === 1);

check('the Download button comes before the Copy and QR buttons',
	strpos($html, 'value="download"') < strpos($html, 'id="wgeasy_copy"'));

foreach (array('celular-marcelo', 'juan perez', 'un nombre bastante largo', 'x') as $descr) {
	$once = wgeasy_conf_filename($descr);

	check("building the file name for '{$descr}' is idempotent",
		wgeasy_conf_filename($once) === $once, "{$once} then " . wgeasy_conf_filename($once));

	check("'{$descr}' produces a single .conf extension",
		substr_count($once, '.conf') === 1, $once);
}

check('a name with no usable characters falls back',
	wgeasy_conf_filename('@@@') === 'wgclient.conf');

fwrite(STDOUT, "\n== 13. client keys on the form ==\n");

preg_match('/name="privatekey"[^>]*value="([^"]*)"/', $form_html, $mpriv);

preg_match('/name="publickey"[^>]*value="([^"]*)"/', $form_html, $mpub);

check('the client private key is shown', wg_is_valid_key($mpriv[1] ?? ''));

check('the client public key is shown', wg_is_valid_key($mpub[1] ?? ''));

check('the public key shown derives from the private key shown',
	derive_pubkey($mpriv[1] ?? '') === ($mpub[1] ?? null));

check('the public key field is read only',
	preg_match('/name="publickey"[^>]*readonly/', $form_html) === 1);

check('the keys are above the pre-shared key',
	strpos($form_html, 'name="privatekey"') < strpos($form_html, 'name="presharedkey"'));

check('a Generate button for the key pair is present',
	strpos($form_html, 'id="genkeys"') !== false);

fwrite(STDOUT, "\n== 14. endpoint field and its detected list ==\n");

check('the endpoint field is pre-filled with a Dynamic DNS hostname',
	preg_match('/name="endpoint"[^>]*value="([^"]*)"/', $form_html, $mep) && ($mep[1] === 'vpn.casa.example.com'),
	$mep[1] ?? 'none');

check('no datalist is used, since it would filter against the pre-filled value',
	strpos($form_html, 'datalist') === false);

check('the detected list sits in the same row as the endpoint',
	preg_match('/name="endpoint".*?name="endpoint_detected".*?name="port"/s', $form_html) === 1);

// The endpoint now lives in Peer Configuration, where the native page has it
check('the endpoint row is inside the Peer Configuration panel',
	preg_match('/Peer Configuration.*?name="endpoint".*?Address Configuration/s', $form_html) === 1);

check('it sits between the description and the keep alive, like the native page',
	preg_match('/name="descr".*?name="endpoint".*?name="persistentkeepalive"/s', $form_html) === 1);

check('there is no Dynamic Endpoint checkbox', strpos($form_html, 'name="dynamic"') === false);

check('the detected list starts on a neutral prompt so nothing is pre-selected',
	preg_match('/<select name="endpoint_detected".*?<option value=""[^>]*>([^<]*)</s', $form_html, $mprompt)
	&& (strpos($mprompt[1], 'Detected') !== false));

preg_match('/<select name="endpoint_detected".*?<\/select>/s', $form_html, $msel);

$options = array();

preg_match_all('/<option value="([^"]*)"[^>]*>([^<]*)</', $msel[0] ?? '', $mopts, PREG_SET_ORDER);

foreach ($mopts as $opt) {
	$options[$opt[1]] = $opt[2];
}

check('every detected endpoint is listed', count($options) == (count(wgeasy_get_endpoint_candidates()) + 1),
	count($options) . ' options');

check('the Dynamic DNS entry keeps its description',
	strpos($options['vpn.casa.example.com'] ?? '', 'currently 203.0.113.10') !== false,
	$options['vpn.casa.example.com'] ?? 'missing');

check('the WAN address is listed', array_key_exists('203.0.113.10', $options));

check('the DNS preset select sits in the same row as the DNS field',
	preg_match('/name="dns" id="dns".*?name="dns_preset"/s', $form_html) === 1);

check('the DNS preset select starts on a neutral prompt',
	preg_match('/<select name="dns_preset".*?<option value=""[^>]*>([^<]*)</s', $form_html, $mdns)
	&& (strpos($mdns[1], 'Presets') !== false));

check('Google is still one of the options',
	preg_match('/<select name="dns_preset".*?<\/select>/s', $form_html, $mdnssel)
	&& (strpos($mdnssel[0], '8.8.8.8, 8.8.4.4') !== false));

fwrite(STDOUT, "\n== 15. tunneled networks default to the local subnets ==\n");

$networks = wgeasy_get_local_networks();

check('the LAN subnet is listed', in_array('192.168.1.0/24', $networks), implode(', ', $networks));

check('a second local interface is listed', in_array('192.168.20.0/24', $networks));

check('the LAN IPv6 subnet is listed', in_array('fd00:1::/64', $networks));

check('the WAN subnet is NOT listed', !in_array('203.0.113.0/24', $networks));

check('a static link without DHCP (the modem path) is NOT listed',
	!in_array('192.168.0.0/24', $networks), implode(', ', $networks));

check('an interface with a DHCP block but no enable is NOT listed either',
	!in_array('192.168.0.0/24', $networks));

// Belt and suspenders: with no DHCP anywhere, gateway-less interfaces return
$saved_dhcpd = config_get_path('dhcpd', []);

config_set_path('dhcpd', array());

check('without any DHCP service the list falls back instead of going empty',
	in_array('192.168.1.0/24', wgeasy_get_local_networks()));

config_set_path('dhcpd', $saved_dhcpd);

$tunneled = wgeasy_get_tunneled_networks('tun_wg0');

check('the tunnel network itself is included', in_array('10.6.0.0/24', $tunneled));

$defaults = wgeasy_default_pconfig('tun_wg0');

check('the form defaults to split routing', $defaults['routing'] === 'split');

check('the Tunneled Networks field is pre-filled with them',
	strpos($defaults['client_allowedips'], '192.168.1.0/24') !== false
	&& strpos($defaults['client_allowedips'], '10.6.0.0/24') !== false,
	$defaults['client_allowedips']);

// The genpsk ajax action calls exit(), so it is exercised over HTTP instead

fwrite(STDOUT, "\n== 16. a tunnel assigned as a pfSense interface ==\n");

check('the assigned tunnel has no address rows of its own',
	empty(config_get_path('installedpackages/wireguard/tunnels/item/2/addresses/row')));

check('its addresses are read from the assigned interface',
	wgeasy_tunnel_address_rows('tun_wg2') === array(array('address' => '10.9.0.1', 'mask' => '24')),
	json_encode(wgeasy_tunnel_address_rows('tun_wg2')));

check('the next free address comes from the interface subnet',
	wgeasy_next_free_address('tun_wg2') === '10.9.0.2/32',
	var_export(wgeasy_next_free_address('tun_wg2'), true));

check('the DNS default is the assigned interface address',
	wgeasy_tunnel_dns('tun_wg2') === '10.9.0.1');

check('the tunnel network is derived from the interface subnet',
	in_array('10.9.0.0/24', wgeasy_tunnel_networks('tun_wg2')));

check('the form pre-fills for the assigned tunnel too',
	wgeasy_default_pconfig('tun_wg2')['address'] === '10.9.0.2/32');

$html11 = render(array_merge($base_post, array(
		'descr'		=> 'peer-asignado',
		'tun'		=> 'tun_wg2',
		'address'	=> '10.9.0.2/32',
		'dns'		=> '10.9.0.1',
		'port'		=> '51822')));

check('a client provisions cleanly on the assigned tunnel',
	strpos($html11, 'input-errors') === false);

check('its file carries the assigned tunnel endpoint port',
	conf_value(conf_from_html($html11), 'Endpoint', 'Peer') === 'vpn.casa.example.com:51822');

fwrite(STDOUT, "\n== 17. re-export and edit an existing peer ==\n");

config_read_file();

// Locate the first client provisioned in this run
$export_idx = null;

foreach (config_get_path('installedpackages/wireguard/peers/item', []) as $idx => $peer) {
	if ($peer['descr'] == 'celular-marcelo') {
		$export_idx = $idx;
	}
}

check('the provisioned peer can be found again', !is_null($export_idx));

[$again_conf, $again_name] = wgeasy_conf_from_peer($export_idx);

check('its client file can be rebuilt later', !empty($again_conf));

check('the rebuilt file carries the same private key',
	conf_value($again_conf, 'PrivateKey', 'Interface') === $client_privkey);

// The header comment carries a generation timestamp, so compare the settings
$strip_comments = fn($text) => implode("\n", array_filter(
			array_map('trim', explode("\n", (string) $text)),
			fn($line) => (strlen($line) > 0) && ($line[0] !== '#')));

check('the rebuilt file matches what was handed out originally',
	$strip_comments($again_conf) === $strip_comments($conf),
	$strip_comments($again_conf) . ' vs ' . $strip_comments($conf));

$edit_pconfig = wgeasy_pconfig_from_peer($export_idx);

check('the edit form loads the stored settings',
	($edit_pconfig['descr'] === 'celular-marcelo')
	&& ($edit_pconfig['address'] === '10.6.0.3/32')
	&& ($edit_pconfig['endpoint'] === 'vpn.casa.example.com'));

check('the edit form carries the peer index', $edit_pconfig['index'] === $export_idx);

// Editing must not trip the duplicate address check on the peer's own address
$before_edit = count(config_get_path('installedpackages/wireguard/peers/item', []));

$html_edit = render(array_merge($base_post, array(
		'index'		=> $export_idx,
		'descr'		=> 'celular-marcelo-v2',
		'privatekey'	=> $client_privkey)));

check('editing a peer keeping its own address is accepted',
	strpos($html_edit, 'input-errors') === false);

config_read_file();

$edited = config_get_path("installedpackages/wireguard/peers/item/{$export_idx}");

check('the edit updated the same peer instead of creating one',
	($edited['descr'] === 'celular-marcelo-v2')
	&& (count(config_get_path('installedpackages/wireguard/peers/item', [])) == $before_edit),
	'peers before ' . $before_edit . ', after ' . count(config_get_path('installedpackages/wireguard/peers/item', [])));

check('the stored private key survived the edit',
	($edited[WGEASY_STORE]['privatekey'] ?? null) === $client_privkey);

check('the form stays on the edited peer instead of resetting',
	preg_match('/name="index"[^>]*value="([^"]*)"/', $html_edit, $midx)
	&& ($midx[1] == $export_idx), $midx[1] ?? 'none');

// A peer created by hand has no stored key and must degrade gracefully
$hand_made = array(
	'enabled'	=> 'yes',
	'tun'		=> 'tun_wg0',
	'descr'		=> 'hecho-a-mano',
	'publickey'	=> wg_gen_keypair(false)['pubkey'],
	'presharedkey'	=> '');

$hand_made['allowedips']['row'] = array(array('address' => '10.6.0.77', 'mask' => '32', 'descr' => ''));

$all_peers = config_get_path('installedpackages/wireguard/peers/item', []);

$hand_idx = max(array_keys($all_peers)) + 1;

config_set_path("installedpackages/wireguard/peers/item/{$hand_idx}", $hand_made);

write_config('Preview: add a hand made peer.');

check('a hand made peer is not exportable',
	!wgeasy_peer_is_exportable(config_get_path("installedpackages/wireguard/peers/item/{$hand_idx}")));

[$no_conf, $no_name] = wgeasy_conf_from_peer($hand_idx);

check('no client file is invented for it', is_null($no_conf));

$hand_pconfig = wgeasy_pconfig_from_peer($hand_idx);

check('it still opens in the edit form', $hand_pconfig['descr'] === 'hecho-a-mano');

check('with an empty private key and its real public key',
	($hand_pconfig['privatekey'] === '')
	&& ($hand_pconfig['publickey'] === $hand_made['publickey']));

fwrite(STDOUT, "\n== 18. the Easy Peers list ==\n");

$list_page = WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/vpn_wg_easy.php';

$_SERVER['REQUEST_METHOD'] = 'GET';

$_POST = array();

$_REQUEST = array();

ob_start();

include($list_page);

$list_html = ob_get_clean();

$_SERVER['REQUEST_METHOD'] = 'POST';

check('the list renders', strlen($list_html) > 1000);

/*
 * Only the tab bar matters here: the surrounding chrome legitimately links to
 * the native package, since VPN > WireGuard still exists in the menu.
 */
preg_match('#<ul class="nav[^"]*">.*?</ul>#s', $list_html, $mnav);

$nav_html = $mnav[0] ?? '';

preg_match_all('#<li[^>]*>.*?</li>#s', $nav_html, $mtabs);

check('the tab bar holds exactly one tab', count($mtabs[0] ?? array()) === 1,
	count($mtabs[0] ?? array()) . ' tabs');

check('that tab is Easy Peers and it is the active one',
	(strpos($nav_html, 'Easy Peers') !== false) && (strpos($nav_html, 'active') !== false));

check('the tab bar links to no native WireGuard page',
	(strpos($nav_html, '/wg/') === false), $nav_html);

check('it lists the provisioned peer', strpos($list_html, 'celular-marcelo-v2') !== false);

check('it lists the hand made peer too', strpos($list_html, 'hecho-a-mano') !== false);

check('it shows a tunnel column value', strpos($list_html, 'tun_wg0') !== false);

// The column order has to match the native peer list exactly
preg_match('#<thead>.*?</thead>#s', $list_html, $mhead);

preg_match_all('#<th>(.*?)</th>#s', $mhead[0] ?? '', $mcols);

$columns = array_map('trim', $mcols[1] ?? array());

check('the columns match the native peer list, in order',
	$columns === array('Description', 'Public key', 'Tunnel', 'Allowed IPs', 'Endpoint : Port', 'Actions'),
	implode(' | ', $columns));

check('there is no separate client file column',
	strpos($list_html, 'Client file') === false);

// Delivery actions live in the Actions column, and only for exportable peers
preg_match('#<tr[^>]*>(?:(?!</tr>).)*celular-marcelo-v2(?:(?!</tr>).)*</tr>#s', $list_html, $mrow);

$export_row = $mrow[0] ?? '';

preg_match('#<tr[^>]*>(?:(?!</tr>).)*hecho-a-mano(?:(?!</tr>).)*</tr>#s', $list_html, $mrow2);

$hand_row = $mrow2[0] ?? '';

check('the exportable row offers a download action',
	strpos($export_row, 'act=download') !== false);

check('the exportable row offers a QR action', strpos($export_row, 'wgeasy-qr') !== false);

check('the exportable row offers an email action', strpos($export_row, 'wgeasy-mail') !== false);

check('the hand made row offers none of them',
	(strpos($hand_row, 'act=download') === false)
	&& (strpos($hand_row, 'wgeasy-qr') === false)
	&& (strpos($hand_row, 'wgeasy-mail') === false));

check('every action icon uses the same fa-solid family as the native ones',
	preg_match_all('#<a class="(fa-solid [^"]*)"#', $export_row) >= 5);

check('the email modal is present', strpos($list_html, 'id="wgeasy_email_modal"') !== false);

check('the QR download size is a fixed pixel size',
	preg_match('/wgeasyQrSize = (\d+)/', $list_html, $mqr) && ((int) $mqr[1] === 512),
	$mqr[1] ?? 'none');

check('there is an Add Peer button pointing at the edit page',
	preg_match('#href="/wgeasy/vpn_wg_easy_edit\.php"[^>]*class="btn btn-success#', $list_html) === 1);

check('each row links to its own edit page',
	strpos($list_html, "vpn_wg_easy_edit.php?peer={$export_idx}") !== false);

check('rows carry delete and toggle actions',
	(strpos($list_html, 'act=delete&amp;peer=') !== false || strpos($list_html, 'act=delete&peer=') !== false)
	&& (strpos($list_html, 'act=toggle') !== false));

fwrite(STDOUT, "\n== 19. the client file is delivered zipped ==\n");

$zip_name = wgeasy_zip_filename('celular-marcel.conf');

check('the archive name replaces the extension', $zip_name === 'celular-marcel.zip', $zip_name);

$zip = wgeasy_build_zip(array('celular-marcel.conf' => $conf));

check('the archive starts with the local file header signature',
	substr($zip, 0, 4) === "PK\x03\x04");

check('it ends with the end of central directory record',
	substr($zip, -22, 4) === "PK\x05\x06");

check('it is larger than the file it carries', strlen($zip) > strlen($conf));

check('the entry name is stored in it', strpos($zip, 'celular-marcel.conf') !== false);

/*
 * Writing it out and unpacking it with a real tool is the only check that
 * proves a phone will be able to open it. PHP's own reader is used when the
 * zip extension is present, otherwise the file is left for the shell to test.
 */
$zip_path = WGEASY_PREVIEW_VAR . '/tmp/test.zip';

file_put_contents($zip_path, $zip);

if (class_exists('ZipArchive')) {
	$archive = new ZipArchive();

	check('a real ZIP reader opens it', $archive->open($zip_path) === true);

	check('it holds exactly one entry', $archive->numFiles === 1);

	check('the entry is the client file', $archive->getNameIndex(0) === 'celular-marcel.conf');

	check('the extracted contents match the original byte for byte',
		$archive->getFromIndex(0) === $conf);

	$archive->close();
} else {
	fwrite(STDOUT, "  note: the zip extension is absent, preview/var/tmp/test.zip left for an external check\n");
}

fwrite(STDOUT, "\n== 20. the dashboard widget ==\n");

require_once(WGEASY_PREVIEW_WGEASY . '/usr/local/www/widgets/include/wgeasy_peers.inc');

check('the widget declares a title and a link',
	($wgeasy_peers_title === 'WireGuard Easy Peers')
	&& ($wgeasy_peers_title_link === '/wgeasy/vpn_wg_easy.php'));

// wg_get_status() parses `wg show all dump`, which the fake wg synthesizes
$status = wg_get_status();

check('the kernel status reports the tunnels', array_key_exists('tun_wg0', $status));

check('and their peers', count($status['tun_wg0']['peers'] ?? array()) > 0,
	count($status['tun_wg0']['peers'] ?? array()) . ' peers');

$body = wgeasy_compose_widget_body('wgeasy_peers-0', 120, false);

check('the widget lists peers by name, not just a count',
	strpos($body, 'celular-marcelo-v2') !== false, substr(strip_tags($body), 0, 200));

check('it shows where each peer is connected from',
	strpos($body, '203.0.113.200:') !== false);

check('each row links to that peer in Easy Peers',
	preg_match('#/wgeasy/vpn_wg_easy_edit\.php\?peer=\d+#', $body) === 1);

check('it reports how long ago the peer was last seen',
	(strpos($body, 'ago') !== false) || (strpos($body, 'second') !== false), strip_tags($body));

// A peer with no handshake is offline and hidden unless asked for
$offline = array(
	'enabled'	=> 'yes',
	'tun'		=> 'tun_wg0',
	'descr'		=> 'cliente-offline',
	'publickey'	=> wg_gen_keypair(false)['pubkey'],
	'presharedkey'	=> '');

$offline['allowedips']['row'] = array(array('address' => '10.6.0.99', 'mask' => '32', 'descr' => ''));

$offline_idx = max(array_keys(config_get_path('installedpackages/wireguard/peers/item', []))) + 1;

config_set_path("installedpackages/wireguard/peers/item/{$offline_idx}", $offline);

write_config('Preview: add an offline peer.');

check('a peer that never handshook is left out',
	strpos(wgeasy_compose_widget_body('wgeasy_peers-0', 120, false), 'cliente-offline') === false);

check('unless the widget is asked to show offline peers',
	strpos(wgeasy_compose_widget_body('wgeasy_peers-0', 120, true), 'cliente-offline') !== false);

check('an offline peer is reported as never seen',
	preg_match('#cliente-offline.*?never#s', wgeasy_compose_widget_body('wgeasy_peers-0', 120, true)) === 1);

config_del_path("installedpackages/wireguard/peers/item/{$offline_idx}");

write_config('Preview: drop the offline peer.');

fwrite(STDOUT, "\n{$pass} passed, {$fail} failed\n");

exit($fail > 0 ? 1 : 0);

?>

