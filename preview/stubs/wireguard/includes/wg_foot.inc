<?php
/*
 * Preview shadow of wireguard/includes/wg_foot.inc
 *
 * The native file references /usr/local/www/wg/js/WireGuardHelpers.js through
 * filemtime(), which does not exist off the firewall. The preview ships its own
 * implementation of the single helper the pages use.
 */
?>

<script src="/assets/wg_helpers_shim.js"></script>
