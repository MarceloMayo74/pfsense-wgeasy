<?php
/*
 * wgeasy_menu.php
 *
 * Optional fallback. Registers (or removes) the "WireGuard Provision" entry
 * under the VPN menu directly in config.xml, which is the mechanism every
 * pfSense version supports.
 *
 * Only needed when /usr/local/share/pfSense/menu/wgeasy.xml does not produce
 * the menu entry on this pfSense version.
 *
 * Usage, from a shell on the firewall:
 *
 *   php -f /root/wgeasy_menu.php add
 *   php -f /root/wgeasy_menu.php remove
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

require_once('config.inc');
require_once('util.inc');

$url = '/wgeasy/vpn_wg_easy.php';

$entry = array(
		'name'		=> 'WireGuard Easy',
		'tooltiptext'	=> 'Create WireGuard clients: key pair, .conf file, QR code and email delivery',
		'section'	=> 'VPN',
		'url'		=> $url,
		'configfile'	=> 'wgeasy.xml');

$action = ($argv[1] ?? 'add');

$menus = config_get_path('installedpackages/menu', []);

$found = false;

foreach ($menus as $idx => $menu) {
	if ($menu['url'] == $url) {
		$found = $idx;

		break;
	}
}

switch ($action) {
	case 'add':
		if ($found !== false) {
			print("The menu entry is already registered.\n");

			exit(0);
		}

		$menus[] = $entry;

		config_set_path('installedpackages/menu', $menus);

		write_config('[wgeasy] Registered the WireGuard Provision menu entry.');

		print("Menu entry registered. Reload the pfSense GUI to see it.\n");

		break;

	case 'remove':
		if ($found === false) {
			print("The menu entry is not registered.\n");

			exit(0);
		}

		unset($menus[$found]);

		config_set_path('installedpackages/menu', array_values($menus));

		write_config('[wgeasy] Removed the WireGuard Provision menu entry.');

		print("Menu entry removed.\n");

		break;

	default:
		print("Usage: php -f wgeasy_menu.php [add|remove]\n");

		exit(1);
}

exit(0);

?>
