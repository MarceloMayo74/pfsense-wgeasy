<?php
/*
 * CLI smoke test: renders the page and optionally provisions a client, without
 * a browser. Run:  php -f preview/smoke.php [get|post]
 */

$mode = $argv[1] ?? 'get';

$_SERVER['REQUEST_METHOD']	= ($mode == 'post') ? 'POST' : 'GET';
$_SERVER['REQUEST_URI']		= '/wgeasy/vpn_wg_easy_edit.php';
$_SERVER['SCRIPT_NAME']		= '/wgeasy/vpn_wg_easy_edit.php';
$_SERVER['HTTP_HOST']		= 'localhost';

require_once(__DIR__ . '/bootstrap.php');

if ($mode == 'post') {
	$_POST = array(
		'act'			=> 'provision',
		'enabled'		=> 'yes',
		'applynow'		=> 'yes',
		'usepsk'		=> 'yes',
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

	$_REQUEST = $_POST;
}

ob_start();

include(WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/vpn_wg_easy_edit.php');

$html = ob_get_clean();

fwrite(STDOUT, "=== rendered " . strlen($html) . " bytes ===\n");

// Pull the generated client configuration out of the page for inspection
if (preg_match('#<pre id="wgeasy_conf"[^>]*>(.*?)</pre>#s', $html, $m)) {
	fwrite(STDOUT, "\n=== client .conf shown on the page ===\n");
	fwrite(STDOUT, html_entity_decode($m[1], ENT_QUOTES) . "\n");
}

foreach (array('input-errors', 'alert-success', 'alert-danger') as $marker) {
	if (strpos($html, $marker) !== false) {
		fwrite(STDOUT, "[page contains {$marker}]\n");
	}
}

if (preg_match_all('#<li>(.*?)</li>#s', $html, $m)) {
	foreach ($m[1] as $li) {
		$li = trim(strip_tags($li));

		if ((strlen($li) > 0) && (strpos($li, 'href') === false)) {
			fwrite(STDOUT, "  - {$li}\n");
		}
	}
}

fwrite(STDOUT, "\n=== tunnel .conf files on disk ===\n");

foreach (glob(WGEASY_PREVIEW_VAR . '/conf/*.conf') as $conf) {
	fwrite(STDOUT, "--- " . basename($conf) . " ---\n" . file_get_contents($conf) . "\n");
}

?>
