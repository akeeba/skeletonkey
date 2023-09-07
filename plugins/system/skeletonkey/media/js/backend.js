/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2023 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

"use strict";
if (!window.Joomla) throw new Error("Joomla API was not properly initialised");

var initSkeletonKey = function() {
	var a = Joomla.getOptions("plg_system_skeletonkey"),
		b = document.querySelectorAll("table#userList>tbody tr");
	if (!b || b.length < 1) return;
	b.forEach(function(b) {
		var c = b.querySelectorAll("td"),
			d = 1 * c[c.length - 1].textContent;
		if (-1 !== a.loginUsers.indexOf(d)) {
			var e = b.querySelectorAll("th div.btn-group"),
				f = e[0],
				g = document.createElement("span");
			g.classList.add("icon-external-link-alt", "me-1");
			g.setAttribute("aria-hidden", "true");
			var h = document.createElement("span");
			h.textContent = Joomla.Text._("PLG_SYSTEM_SKELETONKEY_BTN_LABEL");
			var i = document.createElement("button");
			i.setAttribute("type", "button");
			i.classList.add("btn", "btn-dark", "btn-sm");
			i.appendChild(g);
			i.appendChild(h);
			i.addEventListener("click", function(a) {
				a.preventDefault();
				var b = Joomla.getOptions("system.paths"),
					c = Joomla.getOptions("csrf.token"),
					e = "".concat(b ? "".concat(b.base, "/index.php") : window.location.pathname, "?option=com_ajax&format=json&plugin=skeletonkey&group=system&user_id=%d").concat(c ? "&".concat(c, "=1") : "");
				Joomla.request({
					url: e.replace("%d", d.toString()),
					onSuccess: function onSuccess(a) {
						var c = JSON.parse(a).data;
						if (c && c[0] !== false) {
							window.open(b.rootFull, '_blank'); // Open in a new tab
						} else {
							Joomla.renderMessages({
								error: [Joomla.Text._("PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED")]
							});
						}
					},
					onError: function onError() {
						Joomla.renderMessages({
							error: [Joomla.Text._("PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED_AJAX")]
						});
					}
				});
			});
			f.appendChild(i);
		}
	});
};
initSkeletonKey();

