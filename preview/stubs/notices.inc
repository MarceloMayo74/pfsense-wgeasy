<?php
/*
 * Preview stub of pfSense notices.inc
 *
 * send_smtp_message() writes the message to preview/var/mail/ instead of
 * talking to an SMTP server, so the "Send by email" button can be exercised
 * end to end and the resulting message inspected.
 */

require_once('config.inc');

function send_smtp_message($message, $subject = '(no subject)', $force = false) {
	$to = config_get_path('notifications/smtp/notifyemailaddress', '(unset)');

	$from = config_get_path('notifications/smtp/fromaddress', '(unset)');

	$host = config_get_path('notifications/smtp/ipaddress', '(unset)');

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

	return true;
}

function notify_via_smtp($message, $force = false, $subject = null) {
	return send_smtp_message($message, is_null($subject) ? '(no subject)' : $subject, $force);
}

function file_notice($id, $notice, $category = 'General', $url = '', $priority = 1, $local_only = false) {
	return true;
}

?>
