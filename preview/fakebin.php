<?php
/*
 * Stand-in for the wg(8), ifconfig(8) and pkg(8) binaries the WireGuard package
 * shells out to. Invoked by the real wg_api.inc code through $wgg['wg'],
 * $wgg['ifconfig'] and $wgg['pkg'], which bootstrap.php repoints here.
 *
 * Key material is real: keys are generated and derived with libsodium, so
 * genkey/pubkey produce a genuine Curve25519 pair exactly like wg(8) does.
 *
 * Every invocation is appended to var/fakebin.log so it is possible to see
 * which commands the package would run on the firewall.
 */

$var = __DIR__ . '/var';

if (!is_dir($var)) {
	mkdir($var, 0777, true);
}

$tool = $argv[1] ?? '';

$args = array_slice($argv, 2);

file_put_contents("{$var}/fakebin.log",
		date('H:i:s') . '  ' . $tool . ' ' . implode(' ', $args) . "\n", FILE_APPEND);

/*
 * cmd.exe hands quotes through literally, so anything that arrives from
 * escapeshellarg() or from `echo "..." |` needs cleaning up
 */
function clean($value) {
	return trim((string) $value, " \t\r\n\"'");
}

function clamp_key($raw) {
	$bytes = array_values(unpack('C*', $raw));

	$bytes[0] &= 248;
	$bytes[31] = ($bytes[31] & 127) | 64;

	return pack('C*', ...$bytes);
}

switch ($tool) {
	case 'wg':
		$sub = clean($args[0] ?? '');

		switch ($sub) {
			case 'genkey':
				print(base64_encode(clamp_key(random_bytes(32))) . "\n");
				exit(0);

			case 'genpsk':
				print(base64_encode(random_bytes(32)) . "\n");
				exit(0);

			case 'pubkey':
				$privkey = clean(stream_get_contents(STDIN));

				$raw = base64_decode($privkey, true);

				if (($raw === false) || (strlen($raw) != 32)) {
					fwrite(STDERR, "wg: invalid key\n");
					exit(1);
				}

				print(base64_encode(sodium_crypto_box_publickey_from_secretkey(clamp_key($raw))) . "\n");
				exit(0);

			case 'show':
				/*
				 * `wg show all dump` is what wg_get_status() parses, so it is
				 * synthesized from the configuration: one line per interface,
				 * then one per peer, tab separated. Peers are given a recent
				 * handshake so they look connected, except any peer described
				 * as "offline", which keeps the preview honest about both.
				 */
				if (clean($args[1] ?? '') !== 'all') {
					exit(0);
				}

				$config = @json_decode(@file_get_contents(__DIR__ . '/var/config.json'), true);

				$tunnels = $config['installedpackages']['wireguard']['tunnels']['item'] ?? array();

				$peers = $config['installedpackages']['wireguard']['peers']['item'] ?? array();

				$now = time();

				foreach ((array) $tunnels as $tunnel) {
					$name = $tunnel['name'] ?? '';

					if (($tunnel['enabled'] ?? '') !== 'yes') {
						continue;
					}

					print(implode("\t", array(
						$name,
						$tunnel['privatekey'] ?? '(none)',
						$tunnel['publickey'] ?? '(none)',
						$tunnel['listenport'] ?? '0',
						'off')) . "\n");

					foreach ((array) $peers as $peer) {
						if (($peer['tun'] ?? '') !== $name) {
							continue;
						}

						$allowed = array();

						foreach ((array) ($peer['allowedips']['row'] ?? array()) as $row) {
							$allowed[] = "{$row['address']}/{$row['mask']}";
						}

						$offline = (stripos($peer['descr'] ?? '', 'offline') !== false);

						$handshake = $offline ? 0 : ($now - random_int(5, 90));

						print(implode("\t", array(
							$name,
							$peer['publickey'] ?? '(none)',
							empty($peer['presharedkey']) ? '(none)' : $peer['presharedkey'],
							$offline ? '(none)' : '203.0.113.200:' . random_int(30000, 60000),
							empty($allowed) ? '(none)' : implode(',', $allowed),
							$handshake,
							$offline ? 0 : random_int(1000, 9000000),
							$offline ? 0 : random_int(1000, 9000000),
							'off')) . "\n");
					}
				}

				exit(0);

			case 'set':
			case 'syncconf':
			case 'setconf':
				exit(0);

			default:
				exit(0);
		}

	case 'ifconfig':
		$first = clean($args[0] ?? '');

		// `ifconfig <if>` is a status query, everything else is a mutation
		if ((count($args) == 1) && ($first != '-g')) {
			print("{$first}: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST> metric 0 mtu 1420\n");
			print("\tgroups: wg WireGuard\n");
			exit(0);
		}

		exit(0);

	case 'pkg':
		print("pfSense-pkg-WireGuard\t0.2.9_6\tWireGuard for pfSense (preview)\n");
		exit(0);

	default:
		fwrite(STDERR, "fakebin: unknown tool '{$tool}'\n");
		exit(127);
}

?>
