<?php
/*
 * vpn_wg_easy_edit.php
 *
 * Add or edit a WireGuard client for WireGuard Easy.
 *
 * Generates the client key pair with wg(8), saves the peer through the native
 * wg_do_peer_post() so it behaves exactly like one created under Peers > Edit,
 * and hands over a ready to use client .conf with a QR code, a download and an
 * email button.
 *
 * Opening an existing peer shows its client file again, rebuilt from the
 * settings stored alongside the peer.
 *
 * No file belonging to pfSense-pkg-WireGuard is modified or required to change.
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
##|*NAME=VPN: WireGuard Easy: Edit
##|*DESCR=Allow access to the 'VPN: WireGuard Easy' page.
##|*MATCH=vpn_wg_easy_edit.php*
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

$pconfig = $input_errors = array();

$client_conf = $conf_filename = $savemsg = null;

// What the result panel refers to; kept apart so the form can reset after saving
$client_descr = $client_tun = null;

$applied = false;

$ret_code = 0;

// All form save logic is in wgeasy.inc
if ($_POST) {
	if (isset($_POST['apply'])) {
		$ret_code = wgeasy_apply_changes();

		$applied = true;

		$pconfig = wgeasy_default_pconfig($_POST['tun'] ?? null);
	} else {
		switch ($_POST['act'] ?? '') {
			case 'provision':
				$res = wgeasy_do_provision_post($_POST);
				$input_errors = $res['input_errors'];
				$pconfig = $res['pconfig'];

				$is_edit = !is_null($res['pconfig']['index']) && is_numericint($_POST['index'] ?? null);

				if (empty($input_errors)) {
					$client_conf = $res['client_conf'];
					$conf_filename = $res['conf_filename'];
					$client_descr = $pconfig['descr'];
					$client_tun = $pconfig['tun'];

					if (wg_is_service_running() && $res['changes']) {
						// Everything looks good so far, so mark the subsystem dirty
						mark_subsystem_dirty($wgg['subsystems']['wg']);

						// Add tunnel to the list to apply
						wg_apply_list_add('tunnels', $res['tuns_to_sync']);
					}

					$savemsg = $is_edit
							? sprintf(gettext('Peer %1$s was updated on tunnel %2$s.'),
								htmlspecialchars($client_descr), htmlspecialchars($client_tun))
							: sprintf(gettext('Peer %1$s was created on tunnel %2$s.'),
								htmlspecialchars($client_descr), htmlspecialchars($client_tun));

					// Push the new peer into the kernel right away if requested
					if ($pconfig['applynow'] == 'yes') {
						$ret_code = wgeasy_apply_changes();

						$applied = true;
					}

					/*
					 * A brand new client leaves the form ready for the next
					 * one; an edited peer stays open on itself.
					 */
					if (!$is_edit) {
						$pconfig = wgeasy_default_pconfig($client_tun);
					}
				}

				break;

			case 'email':
				[$client_conf, $conf_filename] = wgeasy_get_posted_conf($_POST);

				$client_descr = trim((string) ($_POST['descr'] ?? ''));
				$client_tun = trim((string) ($_POST['tun'] ?? ''));

				$pconfig = wgeasy_default_pconfig($client_tun);

				if (is_null($client_conf)) {
					$input_errors[] = gettext('The client configuration could not be recovered. Generate it again.');
				} else {
					$res = wgeasy_send_conf_email($_POST['email'] ?? '', $client_conf, $conf_filename, $client_descr);

					if ($res['success']) {
						$savemsg = $res['message'];
					} else {
						$input_errors[] = $res['message'];
					}
				}

				break;

			case 'download':
				[$client_conf, $conf_filename] = wgeasy_get_posted_conf($_POST);

				if (is_null($client_conf)) {
					$input_errors[] = gettext('The client configuration could not be recovered. Generate it again.');

					$pconfig = wgeasy_default_pconfig($_POST['tun'] ?? null);

					break;
				}

				send_user_download('data', $client_conf, $conf_filename);
				exit;

			case 'genkeys':
				// Process ajax call requesting new key pair
				print(wg_gen_keypair(true));
				exit;

			case 'genpubkey':
				// Process ajax call calculating the public key from a private key
				print(wg_gen_publickey($_POST['privatekey'] ?? '', true));
				exit;

			case 'genpsk':
				// Process ajax call requesting new pre-shared key
				print(wg_gen_psk());
				exit;

			case 'nextaddress':
				// Process ajax call requesting the next free tunnel address
				print(wgeasy_next_free_address($_POST['tun'] ?? ''));
				exit;

			default:
				// Shouldn't be here, so bail out.
				header("Location: {$wgeasyg['page']}");
				exit;
		}
	}
} else {
	/*
	 * Opening an existing peer loads its settings and, when this page created
	 * it, its client file as well
	 */
	if (isset($_REQUEST['peer']) && is_numericint($_REQUEST['peer'])
	    && ($peer_pconfig = wgeasy_pconfig_from_peer($_REQUEST['peer']))) {
		$pconfig = $peer_pconfig;

		[$client_conf, $conf_filename] = wgeasy_conf_from_peer($_REQUEST['peer']);

		$client_descr = $pconfig['descr'];
		$client_tun = $pconfig['tun'];
	} else {
		$pconfig = wgeasy_default_pconfig($_REQUEST['tun'] ?? null);
	}
}

$is_edit = is_numericint($pconfig['index'] ?? null);

$tun_list = wgeasy_get_tun_list();

$shortcut_section = 'wireguard';

$pgtitle = array(gettext('VPN'), gettext('WireGuard Easy'), gettext('Easy Peers'),
			$is_edit ? gettext('Edit') : gettext('Add'));
$pglinks = array('', $wgeasyg['page'], $wgeasyg['page'], '@self');

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

if (empty($tun_list)) {
	print_info_box(gettext('There are no WireGuard tunnels available. Create a tunnel first.') .
			' <a href="/wg/vpn_wg_tunnels_edit.php">' . gettext('Create a New Tunnel') . '</a>', 'warning', null);
}

$form = new Form(false);

/*
 * Section 1 and 2 mirror vpn_wg_peers_edit.php field for field, with the key
 * pair shown before the pre-shared key. Everything the native page does not
 * have lives in section 3.
 */
$section = new Form_Section('Peer Configuration');

$section->addInput(new Form_Checkbox(
	'enabled',
	'Enable',
	gettext('Enable Peer'),
	$pconfig['enabled'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>Uncheck this option to create the peer without enabling it.');

$section->addInput(new Form_Select(
	'tun',
	'*Tunnel',
	$pconfig['tun'],
	$tun_list
))->setHelp("WireGuard tunnel for this peer. (<a href='/wg/vpn_wg_tunnels_edit.php'>Create a New Tunnel</a>)");

$section->addInput(new Form_Input(
	'descr',
	'*Description',
	'text',
	$pconfig['descr'],
	['placeholder' => 'Description']
))->setHelp('Peer description for administrative reference. It also names the client file, ' .
	    'keeping the first 15 characters of [a-zA-Z0-9_=+.-].');

/*
 * Same position the native page gives it. Unlike the native page there is no
 * Dynamic Endpoint checkbox hiding it: the client file always needs an address
 * to dial, so the field is always asked for, and it is always stored on the
 * peer as well, which is what fills the Endpoint column of the list.
 */
$endpoint_candidates = wgeasy_get_endpoint_candidates();

$group = new Form_Group('*Endpoint');

$group->add(new Form_Input(
	'endpoint',
	'Endpoint',
	'text',
	$pconfig['endpoint'],
	['placeholder' => 'vpn.example.com']
))->addClass('trim')
  ->setHelp('Hostname, IPv4, or IPv6 address of this firewall as reachable by the client. ' .
	    'Written to the client file and stored on this peer.')
  ->setWidth(4);

/*
 * A plain select next to the field instead of a datalist on it: a datalist
 * filters its suggestions against whatever is already typed, and this field
 * arrives pre-filled, so the rest of the list would be hidden.
 */
$group->add(new Form_Select(
	'endpoint_detected',
	'Detected',
	'',
	array_merge(array('' => gettext('Detected on this firewall...')), $endpoint_candidates)
))->setHelp('Dynamic DNS hostnames and interface addresses already configured here. ' .
	    'Picking one fills the endpoint. (<a href="/services_dyndns.php">' . gettext('Dynamic DNS') . '</a>)')
  ->setWidth(4);

$group->add(new Form_Input(
	'port',
	'Endpoint Port',
	'text',
	$pconfig['port']
))->addClass('trim')
  ->setHelp('Listen port.')
  ->setWidth(2);

$section->add($group);

$section->addInput(new Form_Input(
	'persistentkeepalive',
	'Keep Alive',
	'text',
	$pconfig['persistentkeepalive'],
	['placeholder' => 'Keep Alive']
))->addClass('trim')
  ->setHelp('Interval (in seconds) for Keep Alive packets sent by this client.<br />
	     Recommended for clients behind NAT. Leave blank to disable.');

$group = new Form_Group('*Client Keys');

$group->add(new Form_Input(
	'privatekey',
	'Client Private Key',
	wg_secret_input_type(),
	$pconfig['privatekey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Private key for this client, kept with the peer so the file can be handed out again. ' .
	    '(<a id="copypriv" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

$group->add(new Form_Input(
	'publickey',
	'Client Public Key',
	'text',
	$pconfig['publickey']
))->addClass('trim')
  ->setHelp('Public key for this client, saved on the peer. ' .
	    '(<a id="copypub" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)')
  ->setReadonly();

$group->add(new Form_Button(
	'genkeys',
	'Generate',
	null,
	'fa-solid fa-key'
))->addClass('btn-primary btn-sm')
  ->setHelp('New Key Pair');

$section->add($group);

if ($is_edit && empty($pconfig['privatekey'])) {
	$section->addInput(new Form_StaticText(
		gettext('Note'),
		gettext('This peer was not created here, so its private key is not on this firewall and no client ' .
			'file can be produced for it. Generating a new key pair above would re-key the client, and ' .
			'the old configuration would stop working.')
	));
}

$group = new Form_Group('Pre-shared Key');

$group->add(new Form_Input(
	'presharedkey',
	'Pre-shared Key',
	wg_secret_input_type(),
	$pconfig['presharedkey'],
	['autocomplete' => 'new-password']
))->addClass('trim')
  ->setHelp('Optional pre-shared key, written to both this peer and the client file. ' .
	    '(<a id="copypsk" style="cursor: pointer;" data-success-text="Copied" data-timeout="3000">Copy</a>)');

$group->add(new Form_Button(
	'genpsk',
	'Generate',
	null,
	'fa-solid fa-key'
))->addClass('btn-primary btn-sm')
  ->setHelp('New Pre-shared Key');

$section->add($group);

$form->add($section);

$section = new Form_Section('Address Configuration');

$section->addInput(new Form_StaticText(
	gettext('Hint'),
	gettext('The address entered here is assigned to the client and saved as the Allowed IPs of this peer. ' .
		'These entries must be unique between multiple peers on the same tunnel. Otherwise, traffic to the ' .
		'conflicting networks will only be routed to the last peer in the list.')
));

$group = new Form_Group('*Allowed IPs');

$group->add(new Form_Input(
	'address',
	'Allowed IPs',
	'text',
	$pconfig['address'],
	['placeholder' => '10.6.0.2/32']
))->addClass('trim')
  ->setHelp('Address assigned to this client, in CIDR notation. Separate multiple addresses with commas.<br />
	     Written as <code>Address</code> in the client file and as the peer <code>AllowedIPs</code> here.')
  ->setWidth(5);

$group->add(new Form_Button(
	'nextaddr',
	'Suggest',
	null,
	'fa-solid fa-wand-magic-sparkles'
))->addClass('btn-primary btn-sm')
  ->setHelp('Next free address on this tunnel');

$section->add($group);

$form->add($section);

$section = new Form_Section('Client Configuration');

$section->addInput(new Form_Select(
	'routing',
	'Routing',
	$pconfig['routing'],
	array(
		'full'		=> gettext('Full tunnel (send all traffic through the tunnel)'),
		'split'		=> gettext('Split tunnel (only the tunnel networks)'),
		'custom'	=> gettext('Custom'))
))->setHelp('Preset for the networks below.');

$section->addInput(new Form_Input(
	'client_allowedips',
	'*Tunneled Networks',
	'text',
	$pconfig['client_allowedips'],
	['placeholder' => '0.0.0.0/0, ::/0']
))->addClass('trim')
  ->setHelp('Networks the client routes into the tunnel. Written as <code>AllowedIPs</code> in the client file.');

$group = new Form_Group('DNS Servers');

$group->add(new Form_Input(
	'dns',
	'DNS Servers',
	'text',
	$pconfig['dns'],
	['placeholder' => 'DNS Servers']
))->addClass('trim')
  ->setHelp('Optional. Separate multiple entries with commas.<br />
	     Leave blank to keep the DNS servers already configured on the client.')
  ->setWidth(4);

$group->add(new Form_Select(
	'dns_preset',
	'DNS Preset',
	$pconfig['dns_preset'],
	array_merge(array('' => gettext('Presets...')), wgeasy_get_dns_presets())
))->setHelp('Picking one fills the field on the left. Full tunnel sets the tunnel address of this firewall automatically; ' .
	    'for internal name resolution on a split tunnel, type that address here by hand.')
  ->setWidth(6);

$section->add($group);

$section->addInput(new Form_Input(
	'mtu',
	'MTU',
	'text',
	$pconfig['mtu'],
	['placeholder' => 'MTU']
))->addClass('trim')
  ->setHelp("Optional. Leave blank to let the client decide. The tunnel default on this firewall is {$wgg['default_mtu']}.");

$section->addInput(new Form_Checkbox(
	'applynow',
	'Apply',
	gettext('Apply the changes immediately'),
	$pconfig['applynow'] == 'yes'
))->setHelp('<span class="text-danger">Note: </span>This action may momentarily suspend active WireGuard peer connections on the changed tunnels.');

$form->add($section);

$form->addGlobal(new Form_Input(
	'act',
	'',
	'hidden',
	'provision'
));

$form->addGlobal(new Form_Input(
	'index',
	'',
	'hidden',
	$pconfig['index']
));

$form->addGlobal(new Form_Button(
	'saveform',
	'Save Peer',
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');

$form->addGlobal(new Form_Button(
	'cancel',
	'Cancel',
	$wgeasyg['page'],
	'fa-solid fa-xmark'
))->addClass('btn-default');

print($form);

?>

<?php if (!empty($client_conf)): ?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<?=gettext('Client Configuration')?> &mdash; <?=htmlspecialchars($conf_filename)?>
		</h2>
	</div>
	<div class="panel-body">
		<div class="alert alert-warning" role="alert">
			<span class="text-danger"><?=gettext('Note: ')?></span>
			<?=gettext('This file contains the private key of the client. Hand it over through a channel you trust.')?>
		</div>
		<div class="row">
			<div class="col-sm-7">
				<pre id="wgeasy_conf" style="max-height: 400px; overflow-y: auto;"><?=htmlspecialchars($client_conf)?></pre>
				<form action="<?=htmlspecialchars($wgeasyg['edit_page'])?>" method="post" style="display: inline;">
					<input type="hidden" name="act" value="download" />
					<input type="hidden" name="confdata" value="<?=base64_encode($client_conf)?>" />
					<input type="hidden" name="confname" value="<?=htmlspecialchars($conf_filename)?>" />
					<button type="submit" class="btn btn-primary btn-sm" title="<?=gettext('Download the client configuration')?>">
						<i class="fa-solid fa-download icon-embed-btn"></i>
						<?=gettext('Download')?>
					</button>
				</form>
				<button type="button" id="wgeasy_copy" class="btn btn-default btn-sm" data-success-text="<?=gettext('Copied')?>" data-timeout="3000">
					<i class="fa-solid fa-clipboard icon-embed-btn"></i>
					<span id="wgeasy_copy_label"><?=gettext('Copy')?></span>
				</button>
				<a id="wgeasy_qrdownload" href="#" class="btn btn-default btn-sm" style="display: none;">
					<i class="fa-solid fa-qrcode icon-embed-btn"></i>
					<?=gettext('Download QR')?>
				</a>
				<div id="wgeasy_qr_hidden" style="display: none;"></div>
			</div>
			<div class="col-sm-5 text-center">
				<div id="wgeasy_qr"></div>
<?php if (is_null(wgeasy_qrcode_js_url())): ?>
				<div class="alert alert-warning" role="alert">
					<?=gettext('The QR code library was not found. Copy a qrcode.js build to /usr/local/www/wgeasy/js/wgeasy_qrcode.js.')?>
				</div>
<?php else: ?>
				<span class="help-block"><?=gettext('Scan with the WireGuard app on Android or iOS.')?></span>
<?php endif; ?>
			</div>
		</div>
		<div class="row">
			<div class="col-sm-12">
				<form action="<?=htmlspecialchars($wgeasyg['edit_page'])?>" method="post" class="form-inline">
					<input type="hidden" name="act" value="email" />
					<input type="hidden" name="confdata" value="<?=base64_encode($client_conf)?>" />
					<input type="hidden" name="confname" value="<?=htmlspecialchars($conf_filename)?>" />
					<input type="hidden" name="descr" value="<?=htmlspecialchars((string) $client_descr)?>" />
					<input type="hidden" name="tun" value="<?=htmlspecialchars((string) $client_tun)?>" />
					<div class="form-group">
						<label for="email"><?=gettext('Send by email')?>&nbsp;</label>
						<input type="email" name="email" id="email" class="form-control" placeholder="user@example.com" size="32" required="required" />
					</div>
					<button type="submit" class="btn btn-primary btn-sm" title="<?=gettext('Send the client configuration by email')?>">
						<i class="fa-solid fa-paper-plane icon-embed-btn"></i>
						<?=gettext('Send')?>
					</button>
					<span class="help-block">
						<?=gettext('Uses the SMTP server configured under System > Advanced > Notifications. Email is not encrypted in transit end to end; prefer the QR code or the download for sensitive deployments.')?>
					</span>
				</form>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($qrcode_js = wgeasy_qrcode_js_url()): ?>
<script src="<?=htmlspecialchars($qrcode_js)?>"></script>
<?php endif; ?>

<?php
$genkeywarning = gettext("Overwrite pre-shared key? Click 'ok' to overwrite key.");
$genkeyswarning = gettext("Overwrite the client key pair? The client file already handed out would stop working. Click 'ok' to overwrite the keys.");
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var wgeasyTunnels = <?=json_encode(wgeasy_tunnel_hints(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_FORCE_OBJECT)?>;
	var wgeasyDefaultAllowedIPs = <?=json_encode($wgeasyg['default_allowedips'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var wgeasyDnsNone = <?=json_encode(WGEASY_DNS_NONE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var wgeasyEditing = <?=$is_edit ? 'true' : 'false'?>;
	var wgeasyQrSize = <?=(int) $wgeasyg['qr_size']?>;
	var wgeasyQrDisplaySize = <?=(int) $wgeasyg['qr_display_size']?>;
	var wgeasyQrLevel = <?=json_encode($wgeasyg['qr_level'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var wgeasyQrQuietZone = <?=(int) $wgeasyg['qr_quiet_zone']?>;

	/*
	 * Repairs three things the QR library leaves wrong on the <svg> it builds:
	 *
	 *  - it uses width="100%" height="100%" and ignores the size it was given,
	 *    so inside a container with no height the code collapses to nothing
	 *  - it emits no quiet zone, while the standard requires four empty modules
	 *    on every side for a scanner to find the code
	 *  - it omits the xmlns, so a serialized copy will not load as an image
	 */
	function wgeasyFixQrSvg(holder, size) {
		var svg = holder.querySelector('svg');

		if (!svg) {
			return null;
		}

		var box = (svg.getAttribute('viewBox') || '').split(/\s+/);

		var modules = (box.length === 4) ? parseInt(box[2], 10) : 0;

		if (modules > 0) {
			var quiet = wgeasyQrQuietZone;
			var side = modules + (quiet * 2);

			svg.setAttribute('viewBox', (-quiet) + ' ' + (-quiet) + ' ' + side + ' ' + side);

			// The background rect has to cover the new margin as well
			for (var i = 0; i < svg.childNodes.length; i++) {
				var node = svg.childNodes[i];

				if (node.nodeName && (node.nodeName.toLowerCase() === 'rect')) {
					node.setAttribute('x', -quiet);
					node.setAttribute('y', -quiet);
					node.setAttribute('width', side);
					node.setAttribute('height', side);

					break;
				}
			}
		}

		svg.setAttribute('width', size);
		svg.setAttribute('height', size);

		// Shrink on narrow screens instead of overflowing the column
		svg.setAttribute('style', 'max-width: 100%; height: auto;');

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
			correctLevel: QRCode.CorrectLevel[wgeasyQrLevel]
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

	wgRegTrimHandler();

	// These are action buttons, not submit buttons
	$('#nextaddr').prop('type', 'button');
	$('#genpsk').prop('type', 'button');
	$('#genkeys').prop('type', 'button');

	function wgeasyCopy(link, field) {
		$(link).click(function () {
			var $this = $(this);
			var originalText = $this.text();

			// The 'modern' way...
			navigator.clipboard.writeText($(field).val());

			$this.text($this.attr('data-success-text'));

			setTimeout(function() {
				$this.text(originalText);
			}, $this.attr('data-timeout'));

			// Prevents the browser from scrolling
			return false;
		});
	}

	wgeasyCopy('#copypriv', '#privatekey');
	wgeasyCopy('#copypub', '#publickey');

	// Request a new client key pair
	$('#genkeys').click(function(event) {
		if ($('#privatekey').val().length == 0 || confirm(<?=json_encode($genkeyswarning, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>)) {
			$.ajax({
				url: <?=json_encode($wgeasyg['edit_page'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
				type: 'post',
				data: {
					act: 'genkeys'
				},
				success: function(response, textStatus, jqXHR) {
					var resp = JSON.parse(response);

					$('#privatekey').val(resp.privkey);
					$('#publickey').val(resp.pubkey);
				}
			});
		}

		return false;
	});

	// Request a new public key when the private key is changed
	$('#privatekey').change(function(event) {
		$.ajax({
			url: <?=json_encode($wgeasyg['edit_page'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
			type: 'post',
			data: {
				act: 'genpubkey',
				privatekey: $('#privatekey').val()
			},
			success: function(response, textStatus, jqXHR) {
				var resp = JSON.parse(response);

				$('#publickey').val(resp.pubkey);
			}
		});
	});

	$('#copypsk').click(function () {
		var $this = $(this);
		var originalText = $this.text();

		// The 'modern' way...
		navigator.clipboard.writeText($('#presharedkey').val());

		$this.text($this.attr('data-success-text'));

		setTimeout(function() {
			$this.text(originalText);
		}, $this.attr('data-timeout'));

		// Prevents the browser from scrolling
		return false;
	});

	// Request a new pre-shared key
	$('#genpsk').click(function(event) {
		if ($('#presharedkey').val().length == 0 || confirm(<?=json_encode($genkeywarning, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>)) {
			$.ajax({
				url: <?=json_encode($wgeasyg['edit_page'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>,
				type: 'post',
				data: {
					act: 'genpsk'
				},
				success: function(response, textStatus, jqXHR) {
					$('#presharedkey').val(response);
				}
			});
		}

		return false;
	});

	function wgeasyApplyRouting() {
		var routing = $('#routing').val();
		var tunnel = wgeasyTunnels[$('#tun').val()];

		if (routing == 'full') {
			$('#client_allowedips').val(wgeasyDefaultAllowedIPs);

			// Everything goes through the tunnel, so this firewall resolves names
			$('#dns').val((tunnel && tunnel.dns) ? tunnel.dns : '');
			$('#dns_preset').val('');
		} else if (routing == 'split') {
			$('#client_allowedips').val((tunnel && tunnel.networks) ? tunnel.networks : '');

			// The client keeps its own DNS unless one is chosen below
			$('#dns').val('');
			$('#dns_preset').val('');
		}
	}

	function wgeasyApplyDnsPreset() {
		var preset = $('#dns_preset').val();

		// The neutral prompt does nothing
		if (preset === null || preset.length == 0) {
			return;
		}

		if (preset == wgeasyDnsNone) {
			$('#dns').val('');
		} else {
			$('#dns').val(preset);
		}
	}

	function wgeasyUpdateTunnelDefaults() {
		var tunnel = wgeasyTunnels[$('#tun').val()];

		if (tunnel) {
			$('#port').val(tunnel.port);
		}

		wgeasyApplyRouting();

		// Request the next free address on the selected tunnel
		$.ajax({
			url: <?=json_encode($wgeasyg['edit_page'])?>,
			type: 'post',
			data: {
				act: 'nextaddress',
				tun: $('#tun').val()
			},
			success: function(response, textStatus, jqXHR) {
				// An empty answer must not wipe a hand typed address
				if (response.length > 0) {
					$('#address').val(response);
				}
			}
		});
	}

	$('#tun').change(function() {
		// Moving an existing peer must not silently rewrite its settings
		if (!wgeasyEditing) {
			wgeasyUpdateTunnelDefaults();
		}
	});

	$('#routing').change(function() {
		wgeasyApplyRouting();
	});

	$('#dns_preset').change(function() {
		wgeasyApplyDnsPreset();
	});

	// The detected list mirrors into the endpoint field; the server ignores it
	$('#endpoint_detected').change(function() {
		if ($(this).val().length > 0) {
			$('#endpoint').val($(this).val());
		}
	});

	$('#nextaddr').click(function() {
		$.ajax({
			url: <?=json_encode($wgeasyg['edit_page'])?>,
			type: 'post',
			data: {
				act: 'nextaddress',
				tun: $('#tun').val()
			},
			success: function(response, textStatus, jqXHR) {
				if (response.length > 0) {
					$('#address').val(response);
				} else {
					alert(<?=json_encode(gettext('No free address was found on this tunnel. Check the tunnel Interface Addresses (or the assigned interface address).'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>);
				}
			}
		});

		// Prevents the browser from scrolling
		return false;
	});

<?php if (!empty($client_conf)): ?>
	var wgeasyConf = <?=json_encode($client_conf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
	var wgeasyConfName = <?=json_encode($conf_filename, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;

	$('#wgeasy_copy').click(function() {
		var $this = $(this);
		var $label = $('#wgeasy_copy_label');
		var originalText = $label.text();

		// The 'modern' way...
		navigator.clipboard.writeText(wgeasyConf);

		$label.text($this.attr('data-success-text'));

		setTimeout(function() {
			$label.text(originalText);
		}, $this.attr('data-timeout'));

		// Prevents the browser from scrolling
		return false;
	});

	if (typeof QRCode !== 'undefined') {
		try {
			var wgeasyQrHolder = document.getElementById('wgeasy_qr');

			new QRCode(wgeasyQrHolder, {
				text: wgeasyConf,
				width: wgeasyQrDisplaySize,
				height: wgeasyQrDisplaySize,
				correctLevel: QRCode.CorrectLevel[wgeasyQrLevel]
			});

			// Without this the code is in the page but has no size to show at
			wgeasyFixQrSvg(wgeasyQrHolder, wgeasyQrDisplaySize);

			$('#wgeasy_qrdownload').show().click(function() {
				wgeasyDownloadQr(wgeasyConf, wgeasyConfName.replace(/\.conf$/, ''));

				// Prevents the browser from scrolling
				return false;
			});
		} catch (e) {
			$('#wgeasy_qr').html('<div class="alert alert-warning" role="alert">'
				+ <?=json_encode(gettext('The QR code could not be generated. Use the download button instead.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>
				+ '</div>');
		}
	}
<?php endif; ?>
});
//]]>
</script>

<?php
include('wireguard/includes/wg_foot.inc');
include('foot.inc');
?>
