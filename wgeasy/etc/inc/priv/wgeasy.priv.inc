<?php
/*
 * wgeasy.priv.inc
 *
 * Privilege definitions for the WireGuard Easy pages.
 *
 * This lives in the same directory pfSense-pkg-WireGuard uses for its own
 * privileges (/etc/inc/priv), but in a separate file, so nothing owned by the
 * native package is touched.
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

global $priv_list;

$priv_list['page-vpn-wgeasy'] = array();
$priv_list['page-vpn-wgeasy']['name'] = "WebCfg - VPN: WireGuard Easy";
$priv_list['page-vpn-wgeasy']['descr'] = "Allow access to the 'VPN: WireGuard Easy' pages.";
$priv_list['page-vpn-wgeasy']['match'] = array();

$priv_list['page-vpn-wgeasy']['match'][] = "wgeasy/vpn_wg_easy.php*";
$priv_list['page-vpn-wgeasy']['match'][] = "wgeasy/vpn_wg_easy_edit.php*";

?>
