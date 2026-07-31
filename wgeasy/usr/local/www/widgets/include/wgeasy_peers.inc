<?php
/*
 * wgeasy_peers.inc
 *
 * Dashboard widget helpers for WireGuard Easy.
 *
 * The native WireGuard widget lists tunnels and only counts their active
 * peers. This one lists the peers themselves: who is connected, from where,
 * and how long ago they last spoke.
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

$wgeasy_peers_title = gettext('WireGuard Easy Peers');
$wgeasy_peers_title_link = '/wgeasy/vpn_wg_easy.php';

/*
 * Saves the per user widget settings, same shape the native widget uses
 */
function wgeasy_widget_settings_post($post, $user_settings) {
	global $wgg;

	if (!isset($post['widgetkey'])) {
		return;
	}

	$widgetkey = $post['widgetkey'];

	$pconfig = array(
			'refresh_interval'	=> $wgg['default_widget_refresh_interval'],
			'activity_threshold'	=> $wgg['default_widget_activity_threshold'],
			'show_offline'		=> 'no');

	$refresh_interval = $post["{$widgetkey}_refresh_interval"];

	if (isset($refresh_interval)
	    && is_numericint($refresh_interval) && in_array($refresh_interval, range(0, 10))) {
		$pconfig['refresh_interval'] = $refresh_interval;
	}

	$activity_threshold = $post["{$widgetkey}_activity_threshold"];

	if (isset($activity_threshold)
	    && is_numericint($activity_threshold) && ($activity_threshold >= 0)) {
		$pconfig['activity_threshold'] = $activity_threshold;
	}

	if (!empty($post["{$widgetkey}_show_offline"])) {
		$pconfig['show_offline'] = 'yes';
	}

	$user_settings['widgets'][$widgetkey] = $pconfig;

	$message = sprintf(gettext('Updated %s widget settings via dashboard.'), gettext('WireGuard Easy Peers'));

	save_widget_settings($_SESSION['Username'], $user_settings['widgets'], $message);
}

/*
 * Builds the table body: one row per peer known to the kernel.
 *
 * Everything comes from wg_get_status(), which is the package's own wrapper
 * around `wg show all dump`, so the endpoint shown is the address the peer is
 * really talking from, not the one stored in the configuration.
 */
function wgeasy_compose_widget_body($widgetkey, $activity_threshold, $show_offline = false) {
	// A simple hack to use functions inside heredoc strings, as the native widget does
	$s = fn($x) => $x;

	$colspan = 6;

	if (empty(config_get_path('installedpackages/wireguard/tunnels/item', []))) {
		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s(gettext('No WireGuard tunnels have been configured.'))}</td>
			</tr>

			ROW;
	}

	$a_devices = wg_get_status();

	if (empty($a_devices)) {
		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s(gettext('The WireGuard service is not running.'))}</td>
			</tr>

			ROW;
	}

	$current_time = (new DateTimeImmutable)->getTimestamp();

	$rows = array();

	foreach ($a_devices as $device_name => $device) {
		foreach ((array) $device['peers'] as $peer) {
			$handshake = is_numericint($peer['latest_handshake']) ? (int) $peer['latest_handshake'] : 0;

			$is_active = (($handshake > 0)
					&& (($activity_threshold == 0) || (($current_time - $handshake) <= $activity_threshold)));

			if (!$is_active && !$show_offline) {
				continue;
			}

			/*
			 * A peer that is not in the configuration is running but unknown,
			 * so fall back to a truncated public key for a name
			 */
			$descr = trim((string) $peer['config']['descr']);

			$name = (strlen($descr) > 0)
					? wg_truncate_pretty($descr, 20)
					: wg_truncate_pretty($peer['public_key'], 12);

			$peer_idx = wg_peer_get_array_idx($peer['public_key'], $device_name);

			$link = ($peer_idx >= 0)
					? '/wgeasy/vpn_wg_easy_edit.php?peer=' . rawurlencode($peer_idx)
					: '/wgeasy/vpn_wg_easy.php';

			$endpoint = (strlen(trim((string) $peer['endpoint'])) > 0) ? $peer['endpoint'] : gettext('(none)');

			$last_seen = ($handshake > 0)
					? wg_human_time_diff("@{$handshake}")
					: gettext('never');

			$rows[$handshake . '-' . $peer['public_key']] = <<<"ROW"
				<tr>
					<td>
						{$s(wg_handshake_status_icon("@{$handshake}"))}
						<a href="{$s(htmlspecialchars($link))}" title="{$s(htmlspecialchars($descr))}">{$s(htmlspecialchars($name))}</a>
					</td>
					<td>{$s(htmlspecialchars($device_name))}</td>
					<td>{$s(htmlspecialchars($endpoint))}</td>
					<td>{$s(htmlspecialchars($last_seen))}</td>
					<td>{$s(htmlspecialchars(format_bytes($peer['transfer_rx'])))}</td>
					<td>{$s(htmlspecialchars(format_bytes($peer['transfer_tx'])))}</td>
				</tr>

				ROW;
		}
	}

	if (empty($rows)) {
		$empty = $show_offline
				? gettext('No WireGuard peers have been configured.')
				: gettext('No peers are connected right now.');

		return <<<"ROW"
			<tr>
				<td colspan="{$colspan}">{$s($empty)}</td>
			</tr>

			ROW;
	}

	// Most recently seen first
	krsort($rows);

	return implode('', $rows);
}

?>
