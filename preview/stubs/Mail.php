<?php
/*
 * Preview stub of PEAR Mail.
 *
 * pfSense ships PEAR Mail under /usr/local/share/pear and it is the only mailer
 * the system has -- PHPMailer is not there, which is why wgeasy assembles the
 * MIME by hand and sends it through this API.
 *
 * The stub offers the same surface wgeasy uses, Mail::factory('smtp', $params)
 * and $mailer->send($to, $headers, $body), and writes the message it was handed
 * to preview/var/mail/ instead of talking to a server, so the attachment path
 * can be exercised end to end and the resulting MIME inspected.
 *
 * $GLOBALS['wgeasy_preview_mail_error'] makes the send fail the way PEAR fails,
 * which is how the fallback path gets exercised.
 */

class PEAR_Error {
	private $message;

	function __construct($message) {
		$this->message = $message;
	}

	function getMessage() {
		return $this->message;
	}
}

class PEAR {
	static function isError($obj) {
		return ($obj instanceof PEAR_Error);
	}
}

class Mail_smtp {
	public $params;

	function __construct($params) {
		$this->params = $params;
	}

	function send($to, $headers, $body) {
		if (!empty($GLOBALS['wgeasy_preview_mail_error'])) {
			return new PEAR_Error($GLOBALS['wgeasy_preview_mail_error']);
		}

		$dir = WGEASY_PREVIEW_VAR . '/mail';

		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}

		$lines = array();

		foreach ((array) $headers as $name => $value) {
			$lines[] = "{$name}: {$value}";
		}

		// What the connection was made with, so the tests can check it
		$lines[] = 'X-Preview-SMTP-Host: ' . $this->params['host'];
		$lines[] = 'X-Preview-SMTP-Port: ' . $this->params['port'];
		$lines[] = 'X-Preview-SMTP-Auth: ' . var_export($this->params['auth'], true);
		$lines[] = 'X-Preview-SMTP-Validate: ' . (isset($this->params['socket_options']) ? 'off' : 'on');
		$lines[] = '';
		$lines[] = $body;

		$name = date('Ymd-His') . '-' . substr(md5(serialize($headers) . microtime()), 0, 6) . '.eml';

		file_put_contents("{$dir}/{$name}", implode("\r\n", $lines));

		return true;
	}
}

class Mail {
	static function &factory($driver, $params = array()) {
		$mailer = new Mail_smtp($params);

		return $mailer;
	}
}

?>
