<?php
/*
 * Router for the PHP built-in server.
 *
 * Maps the URLs the page expects onto the real files in the project tree, so
 * the preview always serves exactly the files that get shipped, with no copies.
 */

require_once(__DIR__ . '/bootstrap.php');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function serve_file($path, $type) {
	if (!file_exists($path)) {
		http_response_code(404);

		print("Not found: {$path}");

		return;
	}

	header("Content-Type: {$type}");
	header('Cache-Control: no-store');

	readfile($path);
}

function preview_chrome($title, $body) {
	print('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>');
	print('<link rel="stylesheet" href="/assets/preview.css"></head><body>');
	print('<div class="preview-banner">LOCAL PREVIEW &nbsp;|&nbsp; <a href="/wgeasy/vpn_wg_easy.php">Easy Peers</a>');
	print(' &nbsp;|&nbsp; <a href="/_state">state</a> &nbsp;|&nbsp; <a href="/_reset">reset data</a></div>');
	print('<div class="container">' . $body . '</div></body></html>');
}

switch (true) {
	case ($uri == '/'):
		header('Location: /wgeasy/vpn_wg_easy.php');
		exit;

	case ($uri == '/wgeasy/vpn_wg_easy.php'):
		include(WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/vpn_wg_easy.php');
		exit;

	case ($uri == '/wgeasy/vpn_wg_easy_edit.php'):
		include(WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/vpn_wg_easy_edit.php');
		exit;

	case ($uri == '/widgets/widgets/wgeasy_peers.widget.php'):
		// The dashboard normally defines these before including a widget
		$widgetkey = 'wgeasy_peers-0';

		$widgetconfig = array('basename' => 'wgeasy_peers');

		$user_settings = array('widgets' => array());

		print('<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title">'
			. 'WireGuard Easy Peers</h2></div><div class="panel-body">');

		include(WGEASY_PREVIEW_WGEASY . '/usr/local/www/widgets/widgets/wgeasy_peers.widget.php');
		exit;

	case ($uri == '/wgeasy/js/wgeasy_qrcode.js'):
		serve_file(WGEASY_PREVIEW_WGEASY . '/usr/local/www/wgeasy/js/wgeasy_qrcode.js', 'application/javascript');
		exit;

	case (strpos($uri, '/assets/') === 0):
		$name = basename($uri);

		$types = array('js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png');

		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

		serve_file(WGEASY_PREVIEW_ROOT . '/assets/' . $name, $types[$ext] ?? 'text/plain');
		exit;

	case ($uri == '/_reset'):
		foreach (array('/config.json', '/changelog.txt', '/fakebin.log', '/php_errors.log') as $file) {
			@unlink(WGEASY_PREVIEW_VAR . $file);
		}

		foreach (glob(WGEASY_PREVIEW_VAR . '/mail/*') as $file) {
			@unlink($file);
		}

		foreach (glob(WGEASY_PREVIEW_VAR . '/conf/*') as $file) {
			@unlink($file);
		}

		foreach (glob(WGEASY_PREVIEW_VAR . '/tmp/*') as $file) {
			@unlink($file);
		}

		header('Location: /wgeasy/vpn_wg_easy.php');
		exit;

	case ($uri == '/_state'):
		$body = '<h1>Preview state</h1>';

		$blocks = array(
			'config.json (stands in for config.xml)'	=> WGEASY_PREVIEW_VAR . '/config.json',
			'changelog (write_config calls)'		=> WGEASY_PREVIEW_VAR . '/changelog.txt',
			'binaries the package invoked'			=> WGEASY_PREVIEW_VAR . '/fakebin.log');

		foreach ($blocks as $label => $path) {
			$body .= '<h2>' . htmlspecialchars($label) . '</h2><pre>'
				. htmlspecialchars(file_exists($path) ? file_get_contents($path) : '(empty)') . '</pre>';
		}

		$body .= '<h2>tunnel .conf files written by wgconfig.class.php</h2>';

		$confs = glob(WGEASY_PREVIEW_VAR . '/conf/*.conf');

		if (empty($confs)) {
			$body .= '<pre>(none yet — save a peer first)</pre>';
		}

		foreach ($confs as $conf) {
			$body .= '<h3>' . htmlspecialchars(basename($conf)) . '</h3><pre>'
				. htmlspecialchars(file_get_contents($conf)) . '</pre>';
		}

		$body .= '<h2>mail outbox</h2>';

		$mails = glob(WGEASY_PREVIEW_VAR . '/mail/*.eml');

		if (empty($mails)) {
			$body .= '<pre>(no messages sent yet)</pre>';
		}

		foreach (array_reverse($mails) as $mail) {
			$body .= '<h3>' . htmlspecialchars(basename($mail)) . '</h3><pre>'
				. htmlspecialchars(file_get_contents($mail)) . '</pre>';
		}

		preview_chrome('Preview state', $body);
		exit;

	case (strpos($uri, '/wg/') === 0):
		preview_chrome('Native page', '<h1>Native WireGuard page</h1>'
			. '<p><code>' . htmlspecialchars($uri) . '</code> belongs to pfSense-pkg-WireGuard and is not part of this preview.</p>'
			. '<p>The point of the tab bar is that it links to the real pages on the firewall.</p>'
			. '<p><a href="/wgeasy/vpn_wg_easy.php">Back to Easy Peers</a></p>');
		exit;

	default:
		http_response_code(404);

		preview_chrome('Not found', '<h1>404</h1><p><code>' . htmlspecialchars($uri) . '</code></p>'
			. '<p><a href="/wgeasy/vpn_wg_easy.php">Back to Easy Peers</a></p>');
		exit;
}

?>
