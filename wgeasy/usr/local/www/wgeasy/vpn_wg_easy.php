<?php
/*
 * vpn_wg_easy.php
 *
 * Peer list for WireGuard Easy.
 *
 * Mirrors the columns, the layout and the actions of the native
 * vpn_wg_peers.php, and adds the delivery actions for peers created here:
 * download the client file, download its QR code and send it by email.
 *
 * Toggling and deleting go through the native wg_toggle_peer() and
 * wg_delete_peer(). No file belonging to pfSense-pkg-WireGuard is modified.
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

##|+PRIV
##|*IDENT=page-vpn-wgeasy
##|*NAME=VPN: WireGuard Easy
##|*DESCR=Allow access to the 'VPN: WireGuard Easy' page.
##|*MATCH=vpn_wg_easy.php*
##|-PRIV

// pfSense includes
require_once('functions.inc');
require_once('guiconfig.inc');

// WireGuard includes
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

// WireGuard Easy includes
require_once('wgeasy.inc');

global $wgg, $wgeasyg;

// Initialize $wgg state
wg_globals();

$input_errors = array();

$savemsg = null;

$applied = false;

$ret_code = 0;

if ($_POST) {
	if (isset($_POST['apply'])) {
		$ret_code = wgeasy_apply_changes();

		$applied = true;
	}

	if (isset($_POST['peer']) && is_numericint($_POST['peer'])) {
		$peer_idx = $_POST['peer'];

		switch ($_POST['act'] ?? '') {
			case 'toggle':
				$res = wg_toggle_peer($peer_idx);

				$input_errors = $res['input_errors'];

				if (empty($input_errors) && wg_is_service_running() && $res['changes']) {
					mark_subsystem_dirty($wgg['subsystems']['wg']);

					wg_apply_list_add('tunnels', $res['tuns_to_sync']);
				}

				break;

			case 'delete':
				$res = wg_delete_peer($peer_idx);

				$input_errors = $res['input_errors'];

				if (empty($input_errors) && wg_is_service_running() && $res['changes']) {
					mark_subsystem_dirty($wgg['subsystems']['wg']);

					wg_apply_list_add('tunnels', $res['tuns_to_sync']);
				}

				break;

			case 'download':
				[$client_conf, $conf_filename] = wgeasy_conf_from_peer($peer_idx);

				if (is_null($client_conf)) {
					$input_errors[] = gettext('This peer has no client file on this firewall.');

					break;
				}

				send_user_download('data', $client_conf, $conf_filename);
				exit;

			case 'getconf':
				// Process ajax call feeding the QR code generator
				[$client_conf, $conf_filename] = wgeasy_conf_from_peer($peer_idx);

				header('Content-Type: application/json');

				print(json_encode(array(
					'conf' => $client_conf,
					'name' => $conf_filename)));

				exit;

			case 'email':
				[$client_conf, $conf_filename] = wgeasy_conf_from_peer($peer_idx);

				if (is_null($client_conf)) {
					$input_errors[] = gettext('This peer has no client file on this firewall.');

					break;
				}

				$peer = config_get_path("installedpackages/wireguard/peers/item/{$peer_idx}");

				$res = wgeasy_send_conf_email($_POST['email'] ?? '', $client_conf, $conf_filename, $peer['descr']);

				if ($res['success']) {
					$savemsg = $res['message'];
				} else {
					$input_errors[] = $res['message'];
				}

				break;

			default:
				// Shouldn't be here, so bail out.
				header("Location: {$wgeasyg['page']}");
				exit;
		}
	}
}

$shortcut_section = 'wireguard';

$pgtitle = array(gettext('VPN'), gettext('WireGuard Easy'), gettext('Easy Peers'));
$pglinks = array('', '@self', '@self');

$tab_array = array();
$tab_array[] = array(gettext('Easy Peers'), true, $wgeasyg['page']);

include('head.inc');

wg_print_service_warning();

if ($applied) {
	print_apply_result_box($ret_code);
}

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

if (!empty($savemsg)) {
	print_info_box($savemsg, 'success');
}

wg_print_config_apply_box();

display_top_tabs($tab_array);

$peers = config_get_path('installedpackages/wireguard/peers/item', []);

?>

<form name="mainform" method="post">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Easy Peers')?></h2></div>
		<div class="panel-body table-responsive">
			<table class="table table-hover table-striped table-condensed">
				<thead>
					<tr>
						<th><?=gettext('Description')?></th>
						<th><?=gettext('Public key')?></th>
						<th><?=gettext('Tunnel')?></th>
						<th><?=gettext('Allowed IPs')?></th>
						<th><?=htmlspecialchars(wg_format_endpoint(true))?></th>
						<th><?=gettext('Actions')?></th>
					</tr>
				</thead>
				<tbody>
<?php if (count($peers) > 0): ?>
<?php	foreach ($peers as $peer_idx => $peer): ?>
<?php		$edit_link = "{$wgeasyg['edit_page']}?peer=" . htmlspecialchars($peer_idx); ?>
<?php		$exportable = wgeasy_peer_is_exportable($peer); ?>
					<tr ondblclick="document.location='<?=$edit_link?>';" class="<?=wg_peer_status_class($peer)?>">
						<td><?=htmlspecialchars(wg_truncate_pretty($peer['descr'], 24))?></td>
						<td style="cursor: pointer;" class="pubkey" title="<?=htmlspecialchars($peer['publickey'])?>">
							<?=htmlspecialchars(wg_truncate_pretty($peer['publickey'], 16))?>
						</td>
						<td><?=htmlspecialchars($peer['tun'])?></td>
						<td><?=wg_generate_peer_allowedips_popup_link($peer_idx)?></td>
						<td><?=htmlspecialchars(wg_format_endpoint(false, $peer))?></td>
						<td style="cursor: pointer;">
							<a class="fa-solid fa-pencil" title="<?=gettext('Edit Peer')?>" href="<?=$edit_link?>"></a>
							<?=wg_generate_toggle_icon_link(($peer['enabled'] == 'yes'), 'peer', "?act=toggle&peer={$peer_idx}")?>
<?php		if ($exportable): ?>
							<a class="fa-solid fa-download" title="<?=gettext('Download the client configuration')?>" href="<?="?act=download&peer={$peer_idx}"?>" usepost></a>
							<a class="fa-solid fa-qrcode wgeasy-qr" title="<?=gettext('Download the QR code')?>" href="#" data-peer="<?=htmlspecialchars($peer_idx)?>"></a>
							<a class="fa-solid fa-paper-plane wgeasy-mail" title="<?=gettext('Send the client configuration by email')?>" href="#" data-peer="<?=htmlspecialchars($peer_idx)?>" data-descr="<?=htmlspecialchars($peer['descr'])?>"></a>
<?php		endif; ?>
							<a class="fa-solid fa-trash-can text-danger" title="<?=gettext('Delete Peer')?>" href="<?="?act=delete&peer={$peer_idx}"?>" usepost></a>
						</td>
					</tr>
<?php	endforeach; ?>
<?php else: ?>
					<tr>
						<td colspan="6">
							<?php print_info_box(gettext('No WireGuard peers have been configured. Click the "Add Peer" button below to create one.'), 'warning', null); ?>
						</td>
					</tr>
<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<nav class="action-buttons">
		<a href="<?=htmlspecialchars($wgeasyg['edit_page'])?>" class="btn btn-success btn-sm">
			<i class="fa-solid fa-plus icon-embed-btn"></i>
			<?=gettext('Add Peer')?>
		</a>
	</nav>
</form>

<div class="modal fade" id="wgeasy_email_modal" tabindex="-1" role="dialog" aria-labelledby="wgeasy_email_title">
	<div class="modal-dialog" role="document">
		<form method="post" class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="wgeasy_email_title"><?=gettext('Send the client configuration')?></h4>
			</div>
			<div class="modal-body">
				<input type="hidden" name="act" value="email" />
				<input type="hidden" name="peer" id="wgeasy_email_peer" value="" />
				<p id="wgeasy_email_descr"></p>
				<div class="form-group">
					<label for="wgeasy_email_to"><?=gettext('Email address')?></label>
					<input type="email" name="email" id="wgeasy_email_to" class="form-control" placeholder="user@example.com" required="required" />
				</div>
				<span class="help-block">
					<?=gettext('Uses the SMTP server configured under System > Advanced > Notifications. Email is not encrypted in transit end to end; prefer the QR code or the download for sensitive deployments.')?>
				</span>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal"><?=gettext('Cancel')?></button>
				<button type="submit" class="btn btn-primary">
					<i class="fa-solid fa-paper-plane icon-embed-btn"></i>
					<?=gettext('Send')?>
				</button>
			</div>
		</form>
	</div>
</div>

<div id="wgeasy_qr_hidden" style="display: none;"></div>

<?php if ($qrcode_js = wgeasy_qrcode_js_url()): ?>
<script src="<?=htmlspecialchars($qrcode_js)?>"></script>
<?php endif; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var wgeasyPage = <?=json_encode($wgeasyg['page'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var wgeasyQrSize = <?=(int) $wgeasyg['qr_size']?>;

	/*
	 * The QR library renders its <svg> with width="100%" height="100%" and no
	 * xmlns, ignoring the size it was given, so a serialized copy will not load
	 * as an image. Fix both on the element it made.
	 */
	function wgeasyFixQrSvg(holder, size) {
		var svg = holder.querySelector('svg');

		if (!svg) {
			return null;
		}

		svg.setAttribute('width', size);
		svg.setAttribute('height', size);

		if (!svg.getAttribute('xmlns')) {
			svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
		}

		return svg;
	}

	function wgeasySvgDataUrl(svg) {
		var text = new XMLSerializer().serializeToString(svg);

		return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(text)));
	}

	// Hands a data URL to the browser as a download
	function wgeasySave(dataUrl, filename) {
		var link = document.createElement('a');

		link.href = dataUrl;
		link.download = filename;

		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	}

	/*
	 * A bare SVG is rescaled to the whole window when opened, so the QR is
	 * rasterized to a PNG of a fixed size. The SVG is kept as a fallback for
	 * browsers that refuse to draw it onto a canvas.
	 */
	function wgeasyDownloadQr(text, filename) {
		var holder = document.getElementById('wgeasy_qr_hidden');

		holder.innerHTML = '';

		new QRCode(holder, {
			text: text,
			width: wgeasyQrSize,
			height: wgeasyQrSize,
			correctLevel: QRCode.CorrectLevel.M
		});

		var svg = wgeasyFixQrSvg(holder, wgeasyQrSize);

		if (!svg) {
			return;
		}

		var svgUrl = wgeasySvgDataUrl(svg);

		var img = new Image();

		img.onload = function() {
			try {
				var canvas = document.createElement('canvas');

				canvas.width = wgeasyQrSize;
				canvas.height = wgeasyQrSize;

				var ctx = canvas.getContext('2d');

				// White background, so the code stays readable in dark viewers
				ctx.fillStyle = '#ffffff';
				ctx.fillRect(0, 0, wgeasyQrSize, wgeasyQrSize);
				ctx.drawImage(img, 0, 0, wgeasyQrSize, wgeasyQrSize);

				wgeasySave(canvas.toDataURL('image/png'), filename + '.png');
			} catch (e) {
				wgeasySave(svgUrl, filename + '.svg');
			}

			holder.innerHTML = '';
		};

		img.onerror = function() {
			wgeasySave(svgUrl, filename + '.svg');

			holder.innerHTML = '';
		};

		img.src = svgUrl;
	}

	// Fetches a client file without putting every private key in this page
	function wgeasyFetchConf(peer, done) {
		$.ajax({
			url: wgeasyPage,
			type: 'post',
			dataType: 'json',
			data: {
				act: 'getconf',
				peer: peer
			},
			success: function(resp) {
				if (resp && resp.conf) {
					done(resp);
				} else {
					alert(<?=json_encode(gettext('This peer has no client file on this firewall.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);
				}
			}
		});
	}

	$('.wgeasy-qr').click(function() {
		var peer = $(this).attr('data-peer');

		if (typeof QRCode === 'undefined') {
			alert(<?=json_encode(gettext('The QR code library was not found.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);

			return false;
		}

		wgeasyFetchConf(peer, function(resp) {
			wgeasyDownloadQr(resp.conf, resp.name.replace(/\.conf$/, ''));
		});

		// Prevents the browser from scrolling
		return false;
	});

	$('.wgeasy-mail').click(function() {
		var $this = $(this);

		$('#wgeasy_email_peer').val($this.attr('data-peer'));
		$('#wgeasy_email_descr').text($this.attr('data-descr'));
		$('#wgeasy_email_to').val('');

		$('#wgeasy_email_modal').modal('show');

		// Prevents the browser from scrolling
		return false;
	});
});
//]]>
</script>

<?php
include('wireguard/includes/wg_foot.inc');
include('foot.inc');
?>
