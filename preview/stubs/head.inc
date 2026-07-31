<?php
/*
 * Preview stub of pfSense head.inc
 *
 * Renders enough page chrome to judge the layout: breadcrumb, a VPN menu built
 * from the same /usr/local/share/pfSense/menu/*.xml file the package installs,
 * jQuery, and the "events" array pfSense pages push their init code into.
 */

global $pgtitle, $pglinks;

$menu_items = array();

foreach (glob(WGEASY_PREVIEW_MENU_DIR . '/*.xml') as $menu_file) {
	$xml = @simplexml_load_file($menu_file);

	if ($xml === false) {
		continue;
	}

	foreach ($xml->menu as $menu) {
		$menu_items[] = array(
				'name'		=> (string) $menu->name,
				'section'	=> (string) $menu->section,
				'url'		=> (string) $menu->url,
				'tooltip'	=> (string) $menu->tooltiptext);
	}
}

$crumbs = array();

foreach ((array) $pgtitle as $idx => $part) {
	$link = $pglinks[$idx] ?? '';

	if (!empty($link) && ($link != '@self')) {
		$crumbs[] = '<a href="' . htmlspecialchars($link) . '">' . htmlspecialchars($part) . '</a>';
	} else {
		$crumbs[] = htmlspecialchars($part);
	}
}

?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?=htmlspecialchars(implode(' / ', (array) $pgtitle))?> — pfSense preview</title>
	<link rel="stylesheet" href="/assets/preview.css" />
	<script src="/assets/jquery.min.js"></script>
	<script type="text/javascript">
		// pfSense pages push their init closures here; foot.inc runs them on ready
		var events = events || [];
	</script>
</head>
<body>
	<div class="preview-banner">
		LOCAL PREVIEW — pfSense core is stubbed, the WireGuard package includes are the real ones
		&nbsp;|&nbsp; <a href="/_state">state</a>
		&nbsp;|&nbsp; <a href="/_reset">reset data</a>
	</div>

	<nav class="navbar">
		<span class="navbar-brand">pfSense</span>
		<span class="navbar-menu">VPN
			<span class="navbar-dropdown">
				<a href="/wg/vpn_wg_tunnels.php">WireGuard</a>
<?php foreach ($menu_items as $item): ?>
<?php if ($item['section'] == 'VPN'): ?>
				<a href="<?=htmlspecialchars($item['url'])?>" title="<?=htmlspecialchars($item['tooltip'])?>"><?=htmlspecialchars($item['name'])?></a>
<?php endif; ?>
<?php endforeach; ?>
			</span>
		</span>
	</nav>

	<div class="container">
		<h1 class="page-title"><?=implode(' <small>/</small> ', $crumbs)?></h1>
