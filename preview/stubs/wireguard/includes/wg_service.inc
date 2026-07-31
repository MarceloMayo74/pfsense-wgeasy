<?php
/*
 * Preview shadow of wireguard/includes/wg_service.inc
 *
 * The real file is also the wireguardd daemon entry point and its service
 * detection uses posix_kill(), which does not exist on Windows. Here the
 * service is simply reported as running, so the pages take the same code path
 * they take on a healthy firewall (dirty subsystem, apply list, tunnel sync).
 */

function wg_is_cli() {
	return (PHP_SAPI === 'cli');
}

function wg_is_service_running() {
	return true;
}

function wg_service_fpm_start() {
	return true;
}

function wg_service_fpm_stop() {
	return true;
}

function wg_service_fpm_restart() {
	return true;
}

function wg_service_cli_start() {
	return true;
}

function wg_service_cli_stop() {
	return true;
}

function wg_service_cli_restart() {
	return true;
}

function wg_restart_extra_services() {
	return true;
}

function wg_service_daemon() {
	return true;
}

function wg_deregister_service_env($resync = true) {
	return true;
}

function wg_register_service_env($resync = true) {
	return true;
}

function wg_register_daemon_sig_handler() {
	return true;
}

function wg_daemon_sig_handler($signo) {
	return true;
}

function wg_service_parse_errors($output) {
	return array();
}

?>
