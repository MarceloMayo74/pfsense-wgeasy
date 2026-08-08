<?php
/*
 * Preview bootstrap.
 *
 * Puts the pfSense core stubs first on the include path, then the REAL
 * pfSense-pkg-WireGuard tree, then the wgeasy tree. Seeds a fake configuration
 * on first run and repoints the shelled-out binaries at fakebin.php.
 */

define('WGEASY_PREVIEW_ROOT', __DIR__);
define('WGEASY_PREVIEW_PROJECT', dirname(__DIR__));
define('WGEASY_PREVIEW_VAR', __DIR__ . '/var');
define('WGEASY_PREVIEW_WGEASY', WGEASY_PREVIEW_PROJECT . '/wgeasy');
define('WGEASY_PREVIEW_NATIVE', WGEASY_PREVIEW_PROJECT . '/wireguard-nativo');
define('WGEASY_PREVIEW_MENU_DIR', WGEASY_PREVIEW_WGEASY . '/usr/local/share/pfSense/menu');

foreach (array('', '/tmp', '/run', '/conf', '/pfconf', '/mail') as $sub) {
	$dir = WGEASY_PREVIEW_VAR . $sub;

	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
}

set_include_path(implode(PATH_SEPARATOR, array(
	WGEASY_PREVIEW_ROOT . '/stubs',				// pfSense core stubs (shadow first)
	WGEASY_PREVIEW_NATIVE . '/usr/local/pkg',		// real pfSense-pkg-WireGuard
	WGEASY_PREVIEW_WGEASY . '/usr/local/pkg',		// wgeasy.inc
	get_include_path())));

require_once('config.inc');

config_read_file();

/*
 * Seeds a configuration that looks like a real firewall with WireGuard set up
 */
function wgeasy_preview_seed() {
	$keypair = function() {
		$bytes = array_values(unpack('C*', random_bytes(32)));

		$bytes[0] &= 248;
		$bytes[31] = ($bytes[31] & 127) | 64;

		$privkey = pack('C*', ...$bytes);

		return array(
			'privatekey'	=> base64_encode($privkey),
			'publickey'	=> base64_encode(sodium_crypto_box_publickey_from_secretkey($privkey)));
	};

	$tun0 = $keypair();
	$tun1 = $keypair();
	$tun2 = $keypair();
	$peer0 = $keypair();

	$tunnel0 = array(
		'name'		=> 'tun_wg0',
		'enabled'	=> 'yes',
		'descr'		=> 'Road warriors',
		'listenport'	=> '51820',
		'privatekey'	=> $tun0['privatekey'],
		'publickey'	=> $tun0['publickey'],
		'mtu'		=> '1420');

	$tunnel0['addresses']['row'] = array(
		array('address' => '10.6.0.1', 'mask' => '24', 'descr' => 'Tunnel network'));

	// Lowered MTU, the case a client has to be told about (a PPPoE WAN)
	$tunnel1 = array(
		'name'		=> 'tun_wg1',
		'enabled'	=> 'yes',
		'descr'		=> 'Sucursales',
		'listenport'	=> '51821',
		'privatekey'	=> $tun1['privatekey'],
		'publickey'	=> $tun1['publickey'],
		'mtu'		=> '1412');

	$tunnel1['addresses']['row'] = array(
		array('address' => '10.7.0.1', 'mask' => '24', 'descr' => 'Sitios'),
		array('address' => 'fd00:7::1', 'mask' => '64', 'descr' => 'Sitios v6'));

	/*
	 * Assigned to the pfSense interface opt2 (see the interfaces stub), so its
	 * own address rows are empty, the way the native package leaves them
	 */
	$tunnel2 = array(
		'name'		=> 'tun_wg2',
		'enabled'	=> 'yes',
		'descr'		=> 'Asignado a interfaz',
		'listenport'	=> '51822',
		'privatekey'	=> $tun2['privatekey'],
		'publickey'	=> $tun2['publickey'],
		'mtu'		=> '1420');

	$tunnel2['addresses']['row'] = array();

	$peer = array(
		'enabled'		=> 'yes',
		'tun'			=> 'tun_wg0',
		'descr'			=> 'notebook-marcelo',
		'persistentkeepalive'	=> '',
		'publickey'		=> $peer0['publickey'],
		'presharedkey'		=> '');

	$peer['allowedips']['row'] = array(
		array('address' => '10.6.0.2', 'mask' => '32', 'descr' => 'notebook-marcelo'));

	$config = array();

	$config['system'] = array(
		'hostname'	=> 'pfsense',
		'domain'	=> 'casa.example.com',
		'dnsserver'	=> array('192.168.1.5', '1.1.1.1'));

	/*
	 * WAN has a gateway; LAN and IOT run the DHCP server; MODEM is a static
	 * helper link with neither, like the access path to an upstream modem
	 */
	$config['interfaces'] = array(
		'wan'	=> array('if' => 'em0', 'descr' => 'WAN', 'ipaddr' => 'dhcp', 'gateway' => 'WANGW'),
		'lan'	=> array('if' => 'em1', 'descr' => 'LAN', 'ipaddr' => '192.168.1.1', 'subnet' => '24'),
		'opt1'	=> array('if' => 'em2', 'descr' => 'IOT', 'ipaddr' => '192.168.20.1', 'subnet' => '24'),
		'opt3'	=> array('if' => 'em3', 'descr' => 'MODEM', 'ipaddr' => '192.168.0.2', 'subnet' => '24'));

	// DHCP server runs on LAN and IOT only; enable is presence based
	$config['dhcpd'] = array(
		'lan'	=> array('enable' => '', 'range' => array('from' => '192.168.1.100', 'to' => '192.168.1.199')),
		'opt1'	=> array('enable' => '', 'range' => array('from' => '192.168.20.100', 'to' => '192.168.20.199')),
		'opt3'	=> array('range' => array('from' => '', 'to' => '')));

	/*
	 * Firewall aliases, core pfSense configuration. Only host and network
	 * aliases hold something a client can route to: the port alias and the one
	 * made of hostnames must not reach the list offered on the form.
	 */
	$config['aliases']['alias'] = array(
		array(
			'name'		=> 'redes_internas',
			'type'		=> 'network',
			'address'	=> '192.168.1.0/24 192.168.20.0/24',
			'descr'		=> 'LAN e IOT'),
		array(
			'name'		=> 'nas',
			'type'		=> 'host',
			'address'	=> '192.168.1.10',
			'descr'		=> 'Servidor de archivos'),
		array(
			'name'		=> 'puertos_web',
			'type'		=> 'port',
			'address'	=> '80 443',
			'descr'		=> 'HTTP y HTTPS'),
		array(
			'name'		=> 'dominios',
			'type'		=> 'host',
			'address'	=> 'www.example.com',
			'descr'		=> 'Solo nombres'));

	$config['notifications']['smtp'] = array(
		'ipaddress'		=> 'smtp.example.com',
		'port'			=> '587',
		'tls'			=> '',
		'authentication_mech'	=> 'login',
		'username'		=> 'pfsense@example.com',
		'password'		=> 'notarealpassword',
		'fromaddress'		=> 'pfsense@example.com',
		'notifyemailaddress'	=> 'admin@example.com');

	$config['installedpackages']['wireguard']['config'] = array(
		array(
			'enable'		=> 'on',
			'keep_conf'		=> 'yes',
			'resolve_interval'	=> '300',
			'interface_group'	=> 'all',
			'hide_secrets'		=> 'no',
			'hide_peers'		=> 'no'));

	/*
	 * Dynamic DNS, core pfSense configuration. 'cloudflare' is a split domain
	 * type so host + domainname are joined; 'noip' is not. The third entry is
	 * disabled and must be ignored.
	 */
	$config['dyndnses']['dyndns'] = array(
		array(
			'type'		=> 'cloudflare',
			'host'		=> 'vpn',
			'domainname'	=> 'casa.example.com',
			'interface'	=> 'wan',
			'enable'	=> '',
			'descr'		=> 'VPN',
			'id'		=> ''),
		array(
			'type'		=> 'noip',
			'host'		=> 'marcelo.ddns.net',
			'interface'	=> 'wan',
			'enable'	=> '',
			'descr'		=> 'Respaldo',
			'id'		=> '1'),
		array(
			'type'		=> 'duckdns',
			'host'		=> 'viejo.duckdns.org',
			'interface'	=> 'wan',
			'descr'		=> 'Deshabilitado',
			'id'		=> '2'));

	$config['dnsupdates']['dnsupdate'] = array(
		array(
			'host'		=> 'wg.interna.example.com',
			'server'	=> '192.168.1.5',
			'interface'	=> 'wan',
			'enable'	=> '',
			'descr'		=> 'BIND interno'));

	/*
	 * A pass rule that allows tun_wg0's listen port but not tun_wg1's, so both
	 * outcomes of the readiness check are visible
	 */
	$config['filter']['rule'] = array(
		array(
			'type'		=> 'pass',
			'interface'	=> 'wan',
			'ipprotocol'	=> 'inet',
			'protocol'	=> 'udp',
			'source'	=> array('any' => ''),
			'destination'	=> array('network' => 'wanip', 'port' => '51820'),
			'descr'		=> 'WireGuard road warriors'),
		array(
			'type'		=> 'pass',
			'interface'	=> 'WireGuard',
			'ipprotocol'	=> 'inet46',
			'source'	=> array('any' => ''),
			'destination'	=> array('any' => ''),
			'descr'		=> 'Allow tunnel traffic'));

	$config['installedpackages']['wireguard']['tunnels']['item'] = array($tunnel0, $tunnel1, $tunnel2);

	// The native package indexes new peers from 1, see wg_peer_get_config()
	$config['installedpackages']['wireguard']['peers']['item'] = array(1 => $peer);

	$GLOBALS['pfsense_config'] = $config;

	/*
	 * The Dynamic DNS cache file. pfSense runs the hostname through
	 * escapeshellarg() when building this name, quotes included.
	 */
	file_put_contents(WGEASY_PREVIEW_VAR . "/pfconf/dyndns_wancloudflare'vpn.casa.example.com'.cache",
			'203.0.113.10|' . time());

	write_config('Preview seed configuration.');
}

if (empty($GLOBALS['pfsense_config'])) {
	wgeasy_preview_seed();
}

/*
 * Load the real package now so the binary paths can be repointed before any
 * page uses them
 */
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

global $wgg;

$fakebin = PHP_BINARY . ' ' . WGEASY_PREVIEW_ROOT . '/fakebin.php';

$wgg['wg']			= "{$fakebin} wg";
$wgg['ifconfig']		= "{$fakebin} ifconfig";
$wgg['pkg']			= "{$fakebin} pkg";
$wgg['conf_path']		= WGEASY_PREVIEW_VAR . '/conf';
$wgg['conf_paths_to_clean']	= array(WGEASY_PREVIEW_VAR . '/conf');

// wg_autoloader() resolves wgconfig.class.php through these
$wgg['wg_pkg_root']		= WGEASY_PREVIEW_NATIVE . '/usr/local/pkg/wireguard';
$wgg['wg_classes']		= "{$wgg['wg_pkg_root']}/classes";
$wgg['wg_includes']		= "{$wgg['wg_pkg_root']}/includes";

require_once('wgeasy.inc');

global $wgeasyg;

// So the QR library is found in the repo instead of under /usr/local/www
$wgeasyg['www_root'] = WGEASY_PREVIEW_WGEASY . '/usr/local/www';

?>
