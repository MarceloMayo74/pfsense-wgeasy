<?php
/*
 * Preview stub of pfSense config.inc
 *
 * Backs the configuration with a JSON file instead of config.xml. The public
 * surface (config_get_path / config_set_path / config_del_path / write_config /
 * array_init_path) matches what the WireGuard package uses.
 */

function pfs_config_path() {
	return WGEASY_PREVIEW_VAR . '/config.json';
}

function config_read_file($nocache = false, $force = false) {
	$path = pfs_config_path();

	$GLOBALS['pfsense_config'] = file_exists($path)
					? (array) json_decode(file_get_contents($path), true)
					: array();

	return $GLOBALS['pfsense_config'];
}

function write_config($desc = 'Unknown', $backup = true, $write_config_only = false) {
	file_put_contents(pfs_config_path(),
			json_encode($GLOBALS['pfsense_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

	file_put_contents(WGEASY_PREVIEW_VAR . '/changelog.txt',
			date('Y-m-d H:i:s') . "  {$desc}\n", FILE_APPEND);

	return true;
}

function config_get_path($path, $default = null) {
	if (!isset($GLOBALS['pfsense_config'])) {
		config_read_file();
	}

	$ref = $GLOBALS['pfsense_config'];

	foreach (explode('/', trim((string) $path, '/')) as $key) {
		if (is_array($ref) && array_key_exists($key, $ref)) {
			$ref = $ref[$key];
		} else {
			return $default;
		}
	}

	return is_null($ref) ? $default : $ref;
}

function config_set_path($path, $value) {
	if (!isset($GLOBALS['pfsense_config'])) {
		config_read_file();
	}

	$ref = &$GLOBALS['pfsense_config'];

	foreach (explode('/', trim((string) $path, '/')) as $key) {
		if (!is_array($ref)) {
			$ref = array();
		}

		if (!array_key_exists($key, $ref)) {
			$ref[$key] = array();
		}

		$ref = &$ref[$key];
	}

	$ref = $value;

	unset($ref);

	return $value;
}

function config_del_path($path) {
	if (!isset($GLOBALS['pfsense_config'])) {
		config_read_file();
	}

	$parts = explode('/', trim((string) $path, '/'));

	$leaf = array_pop($parts);

	$ref = &$GLOBALS['pfsense_config'];

	foreach ($parts as $key) {
		if (!is_array($ref) || !array_key_exists($key, $ref)) {
			return;
		}

		$ref = &$ref[$key];
	}

	if (is_array($ref)) {
		unset($ref[$leaf]);
	}

	unset($ref);
}

function config_path_enabled($path, $enablekey = 'enable') {
	return !is_null(config_get_path("{$path}/{$enablekey}"));
}

function array_get_path($array, $path, $default = null) {
	$ref = $array;

	foreach (explode('/', trim((string) $path, '/')) as $key) {
		if (is_array($ref) && array_key_exists($key, $ref)) {
			$ref = $ref[$key];
		} else {
			return $default;
		}
	}

	return is_null($ref) ? $default : $ref;
}

function array_set_path(&$array, $path, $value) {
	$ref = &$array;

	foreach (explode('/', trim((string) $path, '/')) as $key) {
		if (!is_array($ref)) {
			$ref = array();
		}

		if (!array_key_exists($key, $ref)) {
			$ref[$key] = array();
		}

		$ref = &$ref[$key];
	}

	$ref = $value;

	unset($ref);

	return $array;
}

function array_init_path(&$array, $path, $default = array()) {
	$ref = &$array;

	foreach (explode('/', trim((string) $path, '/')) as $key) {
		if (!is_array($ref)) {
			$ref = array();
		}

		if (!isset($ref[$key])) {
			$ref[$key] = array();
		}

		$ref = &$ref[$key];
	}

	if (!is_array($ref) && is_null($ref)) {
		$ref = $default;
	}

	unset($ref);

	return $array;
}

?>
