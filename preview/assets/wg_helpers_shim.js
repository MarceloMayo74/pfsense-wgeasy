/*
 * Stand-in for /wg/js/WireGuardHelpers.js of pfSense-pkg-WireGuard.
 *
 * The pages call wgRegTrimHandler() to strip whitespace from fields marked
 * .trim. On the firewall the native file provides it; this preview ships its
 * own one line implementation rather than vendoring a copy of that package.
 *
 * Copyright (c) 2026 Marcelo Mayo <marcelomayo1974@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 */

function wgRegTrimHandler() {
	$('body').on('change', '.trim', function () {
		$(this).val($(this).val().replace(/\s/g, ''));
	});
}
