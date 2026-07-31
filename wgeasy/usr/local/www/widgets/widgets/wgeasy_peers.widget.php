<?php
/*
 * wgeasy_peers.widget.php
 *
 * Dashboard widget for WireGuard Easy: lists the peers that are connected,
 * where they are connected from, and when they were last seen.
 *
 * Structure, settings handling and AJAX refresh follow the native
 * wireguard.widget.php so it behaves like the rest of the dashboard.
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

// pfSense includes
require_once('guiconfig.inc');
require_once('util.inc');

// WireGuard includes
require_once('wireguard/includes/wg.inc');
require_once('wireguard/includes/wg_guiconfig.inc');

/*
 * Widget includes. Resolves to /usr/local/www/widgets/include on the firewall,
 * the same path the native widget hardcodes, without assuming the prefix.
 */
require_once(__DIR__ . '/../include/wgeasy_peers.inc');

global $wgg;

wg_globals();

/*
 * Validate the "widgetkey" value.
 * When this widget is present on the Dashboard, $widgetkey is defined before
 * the Dashboard includes the widget. During other types of requests, such as
 * saving settings or AJAX, the value may be set via $_POST or similar.
 */
if ($_POST['widgetkey'] || $_GET['widgetkey']) {
	$rwidgetkey = isset($_POST['widgetkey']) ? $_POST['widgetkey'] : (isset($_GET['widgetkey']) ? $_GET['widgetkey'] : null);
	[$wname, $wid] = explode('-', $rwidgetkey, 2);
	if (($wname == basename(__FILE__, '.widget.php')) &&
	    is_numericint($wid)) {
		$widgetkey = $rwidgetkey;
	} else {
		print gettext("Invalid Widget Key");
		exit;
	}
}

$widget_config		= $user_settings['widgets'][$widgetkey];

// Define default widget behavior
$wgeasy_refresh_interval	= (isset($widget_config['refresh_interval']) && is_numericint($widget_config['refresh_interval'])) ? $widget_config['refresh_interval'] : $wgg['default_widget_refresh_interval'];

$wgeasy_activity_threshold = (isset($widget_config['activity_threshold']) && is_numericint($widget_config['activity_threshold'])) ? $widget_config['activity_threshold'] : $wgg['default_widget_activity_threshold'];

$wgeasy_show_offline	= (isset($widget_config['show_offline']) && ($widget_config['show_offline'] == 'yes'));

// Are we handling an ajax refresh?
if (isset($_POST['ajax'])) {

	print(wgeasy_compose_widget_body($widgetkey, $wgeasy_activity_threshold, $wgeasy_show_offline));

	// We are done here...
	exit();

}

// Are we saving the configurable settings?
if (isset($_POST['save'])) {

	// Process settings post
	wgeasy_widget_settings_post($_POST, $user_settings);

	// Redirect back to home...
	header('Location: /');

	// We are done here...
	exit();

}

?>
	<div class="table-responsive">
		<table class="table table-hover table-striped table-condensed" style="overflow-x: visible;">
			<thead>
				<th><?=gettext('Peer')?></th>
				<th><?=gettext('Tunnel')?></th>
				<th><?=gettext('Connected from')?></th>
				<th><?=gettext('Last seen')?></th>
				<th><?=gettext('RX')?></th>
				<th><?=gettext('TX')?></th>
			</thead>
			<tbody id="<?=htmlspecialchars($widgetkey)?>">
				<?=wgeasy_compose_widget_body($widgetkey, $wgeasy_activity_threshold, $wgeasy_show_offline)?>
			</tbody>
		</table>
	</div>
</div>

<div id="widget-<?=htmlspecialchars($widgetkey)?>_panel-footer" class="panel-footer collapse">

	<form action="/widgets/widgets/<?=$widgetconfig['basename']?>.widget.php" method="post" class="form-horizontal">
		<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey)?>" />
		<input type="hidden" name="save" value="save" />

		<div class="form-group">
			<label for="<?=htmlspecialchars($widgetkey)?>_refresh_interval" class="col-sm-4 control-label"><?=gettext('Refresh Interval')?></label>
			<div class="col-sm-8">
				<input type="number" id="<?=htmlspecialchars($widgetkey)?>_refresh_interval" name="<?=htmlspecialchars($widgetkey)?>_refresh_interval" value="<?=htmlspecialchars($wgeasy_refresh_interval)?>" placeholder="<?=$wgg['default_widget_refresh_interval']?>" min="0" max="10" class="form-control" />
				<span class="help-block">
					<?=gettext('Widget refresh interval (in ticks).')?>
					<br />
					<span class="text-danger">Note:</span>
					<?=sprintf(gettext('The default is %s tick (0 to disable).'), $wgg['default_widget_refresh_interval'])?>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label for="<?=htmlspecialchars($widgetkey)?>_activity_threshold" class="col-sm-4 control-label">
				<span><?=gettext('Activity Threshold')?></span>
			</label>
			<div class="col-sm-8">
				<input type="number" id="<?=htmlspecialchars($widgetkey)?>_activity_threshold" name="<?=htmlspecialchars($widgetkey)?>_activity_threshold" value="<?=htmlspecialchars($wgeasy_activity_threshold)?>" placeholder="<?=$wgg['default_widget_activity_threshold']?>" min="0" class="form-control" />
				<span class="help-block">
					<?=gettext('A peer counts as connected when its last handshake is newer than this (in seconds).')?>
					<br />
					<span class="text-danger">Note:</span>
					<?=sprintf(gettext('The default is %s seconds (0 to count any peer with a handshake).'), $wgg['default_widget_activity_threshold'])?>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label for="<?=htmlspecialchars($widgetkey)?>_show_offline" class="col-sm-4 control-label">
				<span><?=gettext('Show Offline')?></span>
			</label>
			<div class="col-sm-8">
				<label class="chkboxlbl">
					<input type="checkbox" id="<?=htmlspecialchars($widgetkey)?>_show_offline" name="<?=htmlspecialchars($widgetkey)?>_show_offline" value="yes" <?=$wgeasy_show_offline ? 'checked="checked"' : ''?> />
					<?=gettext('List peers that are not connected as well')?>
				</label>
			</div>
		</div>

		<nav class="action-buttons">
			<button type="submit" class="btn btn-primary">
				<i class="fa-solid fa-save icon-embed-btn"></i>
				<?=gettext('Save')?>
			</button>
		</nav>
	</form>

	<script type="text/javascript">
	//<![CDATA[
	events.push(function(){

		var wgeasyRefreshInterval = <?=json_encode($wgeasy_refresh_interval)?>;

		// Callback function called by refresh system when data is retrieved
		function wgeasy_peers_callback(s) {
			$(<?=json_encode("#{$widgetkey}")?>).html(s);
		}

		// POST data to send via AJAX
		var postdata = {
			ajax: "ajax",
			widgetkey: <?=json_encode($widgetkey)?>
		};

		if (wgeasyRefreshInterval > 0) {

			// Create an object defining the widget refresh AJAX call
			var wgeasyObject = new Object();
			wgeasyObject.name = "wgeasy_peers";
			wgeasyObject.url = "/widgets/widgets/wgeasy_peers.widget.php";
			wgeasyObject.callback = wgeasy_peers_callback;
			wgeasyObject.parms = postdata;
			wgeasyObject.freq = wgeasyRefreshInterval;

			// Register the AJAX object
			register_ajax(wgeasyObject);

		}

	});
	//]]>
	</script>
