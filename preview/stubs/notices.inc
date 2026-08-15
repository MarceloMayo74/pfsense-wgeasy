<?php
/*
 * Preview stub of pfSense notices.inc
 *
 * send_smtp_message() writes the message to preview/var/mail/ instead of
 * talking to an SMTP server, so the "Send by email" button can be exercised
 * end to end and the resulting message inspected.
 *
 * The return values mirror /etc/inc/notices.inc exactly, because they are the
 * whole trap in this function: it returns NULL when the message went out and
 * the error STRING when it did not. It also bails out silently -- returning
 * NULL, which reads as success -- when notifications are disabled and the call
 * does not force them, or when the server or the recipient is missing.
 *
 * $GLOBALS['wgeasy_preview_smtp_error'] makes the send fail.
 */

require_once('config.inc');

function send_smtp_message($message, $subject = '(no subject)', $force = false) {
	if ((config_path_enabled('notifications/smtp', 'disable') && !$force) ||
	    empty(config_get_path('notifications/smtp/ipaddress')) ||
	    empty(config_get_path('notifications/smtp/notifyemailaddress'))) {
		return;
	}

	$to = config_get_path('notifications/smtp/notifyemailaddress', '(unset)');

	$from = config_get_path('notifications/smtp/fromaddress', '(unset)');

	$host = config_get_path('notifications/smtp/ipaddress', '(unset)');

	if (!empty($GLOBALS['wgeasy_preview_smtp_error'])) {
		return sprintf('Could not send the message to %1$s -- Error: %2$s',
			$to, $GLOBALS['wgeasy_preview_smtp_error']);
	}

	$dir = WGEASY_PREVIEW_VAR . '/mail';

	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}

	$eml = array(
		"Date: " . date('r'),
		"From: {$from}",
		"To: {$to}",
		"Subject: {$subject}",
		"X-Preview-SMTP-Host: {$host}",
		"Content-Type: text/plain; charset=UTF-8",
		'',
		$message);

	$name = date('Ymd-His') . '-' . substr(md5($subject . microtime()), 0, 6) . '.eml';

	file_put_contents("{$dir}/{$name}", implode("\n", $eml));

	return;
}

function notify_via_smtp($message, $force = false, $subject = null) {
	return send_smtp_message($message, is_null($subject) ? '(no subject)' : $subject, $force);
}

function file_notice($id, $notice, $category = 'General', $url = '', $priority = 1, $local_only = false) {
	return true;
}

?>
