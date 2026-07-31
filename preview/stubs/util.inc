<?php
/*
 * Preview stub of pfSense util.inc
 *
 * Only the primitives the WireGuard package and wgeasy actually call.
 */

if (!function_exists('gettext')) {
	function gettext($text) {
		return $text;
	}
}

if (!function_exists('ngettext')) {
	function ngettext($single, $plural, $number) {
		return ($number == 1) ? $single : $plural;
	}
}

function is_ipaddrv4($ipaddr) {
	return (is_string($ipaddr) && (filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
}

function is_ipaddrv6($ipaddr) {
	return (is_string($ipaddr) && (filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false));
}

function is_ipaddr($ipaddr) {
	return (is_ipaddrv4($ipaddr) || is_ipaddrv6($ipaddr));
}

function is_v4($ipaddr) {
	return is_ipaddrv4($ipaddr);
}

function is_v6($ipaddr) {
	return is_ipaddrv6($ipaddr);
}

function is_private_ip($ipaddr) {
	if (!is_ipaddrv4($ipaddr)) {
		return false;
	}

	return (filter_var($ipaddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false);
}

function is_subnet($subnet) {
	if (!is_string($subnet) || (substr_count($subnet, '/') != 1)) {
		return false;
	}

	[$address, $mask] = explode('/', $subnet);

	if (!ctype_digit((string) $mask)) {
		return false;
	}

	if (is_ipaddrv4($address)) {
		return (($mask >= 0) && ($mask <= 32));
	}

	if (is_ipaddrv6($address)) {
		return (($mask >= 0) && ($mask <= 128));
	}

	return false;
}

function is_subnetv4($subnet) {
	return (is_subnet($subnet) && is_ipaddrv4(explode('/', $subnet)[0]));
}

function is_subnetv6($subnet) {
	return (is_subnet($subnet) && is_ipaddrv6(explode('/', $subnet)[0]));
}

function is_port($port) {
	return (ctype_digit((string) $port) && ($port >= 1) && ($port <= 65535));
}

function is_numericint($arg) {
	return ((is_int($arg) || (is_string($arg) && ctype_digit($arg))) ? true : false);
}

function is_hostname($hostname, $allow_wildcard = false) {
	if (!is_string($hostname)) {
		return false;
	}

	return (bool) preg_match('/^(?:(?:[a-z0-9_]|[a-z0-9_][a-z0-9_\-]*[a-z0-9_])\.)*(?:[a-z0-9_]|[a-z0-9_][a-z0-9_\-]*[a-z0-9_])$/i', $hostname);
}

function is_domain($domain) {
	return is_hostname($domain);
}

function gen_subnet($ipaddr, $bits) {
	$bits = (int) $bits;

	if (is_ipaddrv4($ipaddr)) {
		if ($bits <= 0) {
			return '0.0.0.0';
		}

		$mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

		return long2ip(ip2long($ipaddr) & $mask);
	}

	if (is_ipaddrv6($ipaddr)) {
		$bytes = str_split(inet_pton($ipaddr));

		for ($i = 0; $i < 16; $i++) {
			$keep = $bits - ($i * 8);

			if ($keep >= 8) {
				continue;
			}

			$mask = ($keep <= 0) ? 0 : (0xFF << (8 - $keep)) & 0xFF;

			$bytes[$i] = chr(ord($bytes[$i]) & $mask);
		}

		return inet_ntop(implode('', $bytes));
	}

	return '';
}

function gen_subnet_max($ipaddr, $bits) {
	$bits = (int) $bits;

	if (is_ipaddrv4($ipaddr)) {
		$hosts = ($bits <= 0) ? 0xFFFFFFFF : (~((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF)) & 0xFFFFFFFF;

		return long2ip(ip2long(gen_subnet($ipaddr, $bits)) | $hosts);
	}

	if (is_ipaddrv6($ipaddr)) {
		$bytes = str_split(inet_pton(gen_subnet($ipaddr, $bits)));

		for ($i = 0; $i < 16; $i++) {
			$keep = $bits - ($i * 8);

			if ($keep >= 8) {
				continue;
			}

			$mask = ($keep <= 0) ? 0xFF : (~((0xFF << (8 - $keep)) & 0xFF)) & 0xFF;

			$bytes[$i] = chr(ord($bytes[$i]) | $mask);
		}

		return inet_ntop(implode('', $bytes));
	}

	return '';
}

function ip_after($ip, $increment = 1) {
	if (is_ipaddrv4($ip)) {
		return long2ip(ip2long($ip) + $increment);
	}

	return false;
}

function ip_less_than($ipa, $ipb) {
	if (is_ipaddrv4($ipa) && is_ipaddrv4($ipb)) {
		return (ip2long($ipa) < ip2long($ipb));
	}

	return false;
}

function ip_greater_than($ipa, $ipb) {
	return ip_less_than($ipb, $ipa);
}

function unlink_if_exists($fn) {
	$to_do = glob($fn);

	if (is_array($to_do) && (count($to_do) > 0)) {
		foreach ($to_do as $filename) {
			if (is_file($filename)) {
				@unlink($filename);
			}
		}

		return true;
	}

	if (@is_file($fn)) {
		return @unlink($fn);
	}

	return false;
}

function is_module_loaded($module_name) {
	return false;
}

function isvalidpid($pidfile) {
	return false;
}

function is_process_running($process) {
	return false;
}

/*
 * Subsystem dirty flags, backed by files just like pfSense does
 */
function subsystem_dirty_path($subsystem) {
	return WGEASY_PREVIEW_VAR . '/tmp/dirty.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $subsystem);
}

function mark_subsystem_dirty($subsystem) {
	file_put_contents(subsystem_dirty_path($subsystem), 'DIRTY');
}

function clear_subsystem_dirty($subsystem) {
	@unlink(subsystem_dirty_path($subsystem));
}

function is_subsystem_dirty($subsystem) {
	return file_exists(subsystem_dirty_path($subsystem));
}

function format_bytes($bytes, $decimals = 2, $showsuffix = true, $suffix = '') {
	$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB');

	$bytes = (float) $bytes;

	$i = 0;

	while (($bytes >= 1024) && ($i < (count($units) - 1))) {
		$bytes /= 1024;

		$i++;
	}

	return round($bytes, $decimals) . ($showsuffix ? ' ' . $units[$i] . $suffix : '');
}

function save_widget_settings($username, $widgets, $message = '') {
	return true;
}

function update_status($status) {
	// Console output during package install, nothing to do here
}

function log_error($error) {
	file_put_contents(WGEASY_PREVIEW_VAR . '/php_errors.log', date('Y-m-d H:i:s') . "  {$error}\n", FILE_APPEND);
}

function send_user_download($type, $data, $name = '') {
	header('Content-Type: application/octet-stream');
	header("Content-Disposition: attachment; filename=\"{$name}\"");

	print(($type == 'file') ? file_get_contents($data) : $data);

	exit;
}

/*
 * pfSense ships PEAR's Net_IPv6. Only uncompress() is used by the WireGuard
 * package, to compare interface addresses in their expanded form.
 */
if (!class_exists('Net_IPv6')) {
	class Net_IPv6 {
		public static function uncompress($ip, $leading_zeros = false) {
			$raw = @inet_pton($ip);

			if ($raw === false) {
				return $ip;
			}

			$groups = str_split(bin2hex($raw), 4);

			if (!$leading_zeros) {
				$groups = array_map(fn($group) => ltrim($group, '0') ?: '0', $groups);
			}

			return implode(':', $groups);
		}

		public static function compress($ip) {
			$raw = @inet_pton($ip);

			return ($raw === false) ? $ip : inet_ntop($raw);
		}
	}
}

/*
 * These normally come from the pfSense PHP extension
 */
function pfSense_interface_listget() {
	return array();
}

function pfSense_get_ifaddrs($if) {
	return array();
}

function pfSense_get_interface_stats($if) {
	return array(
		'inbytes'	=> 0,
		'outbytes'	=> 0,
		'inpkts'	=> 0,
		'outpkts'	=> 0,
		'mtu'		=> 1420);
}

?>
