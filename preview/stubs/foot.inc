<?php
/* Preview stub of pfSense foot.inc */
?>
	</div>

	<script type="text/javascript">
	//<![CDATA[
	/*
	 * Shims for two things pfSense provides that this preview does not ship:
	 * Bootstrap's modal plugin, and the "usepost" attribute that turns a link
	 * into a POST.
	 */
	if (typeof $.fn.modal === 'undefined') {
		$.fn.modal = function(action) {
			return this.each(function() {
				var $modal = $(this);

				if (action === 'hide') {
					$modal.hide().removeClass('in');
				} else {
					$modal.show().addClass('in');

					$modal.find('[data-dismiss="modal"]').off('click.shim').on('click.shim', function() {
						$modal.hide().removeClass('in');
					});
				}
			});
		};
	}

	$(document).on('click', 'a[usepost]', function(event) {
		event.preventDefault();

		var href = $(this).attr('href');
		var query = href.indexOf('?') >= 0 ? href.slice(href.indexOf('?') + 1) : '';
		var form = $('<form method="post"></form>').attr('action', href.split('?')[0]);

		$.each(query.split('&'), function(i, pair) {
			if (pair.length === 0) {
				return;
			}

			var kv = pair.split('=');

			form.append($('<input type="hidden">')
				.attr('name', decodeURIComponent(kv[0]))
				.attr('value', decodeURIComponent(kv[1] || '')));
		});

		form.appendTo('body').submit();
	});

	$(document).ready(function() {
		for (var i = 0; i < events.length; i++) {
			try {
				events[i]();
			} catch (e) {
				if (window.console) {
					console.error('events[' + i + ']', e);
				}
			}
		}
	});
	//]]>
	</script>
</body>
</html>
