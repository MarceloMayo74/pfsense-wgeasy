<?php
/*
 * Preview shadow of wireguard/includes/wg_install.inc
 *
 * The real file installs earlyshellcmds, interface groups, Unbound ACLs and the
 * rc service. None of that exists off the firewall, and wg_resync() calls into
 * it on every save, so it is replaced with no-ops here.
 *
 * Everything else in the package (wg.inc, wg_api.inc, wg_globals.inc,
 * wg_validate.inc, wg_guiconfig.inc, wgconfig.class.php) is the real code.
 */

function wg_install() {
	return true;
}

function wg_deinstall() {
	return true;
}

function wg_delete_temp_files() {
	return true;
}

function wg_defaults_install() {
	return true;
}

function wg_service_rcfile() {
	return true;
}

function wg_service_install() {
	return true;
}

function wg_service_deinstall() {
	return true;
}

function wg_destroy_tunnels() {
	return true;
}

function wg_listenport_alias_install() {
	return true;
}

function wg_earlyshellcmd_install() {
	return true;
}

function wg_earlyshellcmd_deinstall() {
	return true;
}

function wg_ifgroup_install() {
	return true;
}

function wg_ifgroup_deinstall() {
	return true;
}

function wg_unbound_acl_deinstall() {
	return true;
}

function wg_unbound_acl_install() {
	return true;
}

?>
