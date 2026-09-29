/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos
 * @license   GPLv3 or later
 */

'use strict';

if (!window.Joomla)
{
	throw new Error('Joomla API was not properly initialised');
}

const initSkeletonKey = () => {
	// Get the user list rows
	const options = Joomla.getOptions('plg_system_skeletonkey');
	const rows    = document.querySelectorAll('table#userList>tbody tr');

	// No user rows? Nothing to do here.
	if (!rows || rows.length < 1)
	{
		return;
	}

	// Iterate through all of the rows
	rows.forEach((elRow) => {
		// Get the user ID
		const elCells = elRow.querySelectorAll('td');
		const userId  = elCells[elCells.length - 1].textContent * 1;

		// If we are not allowed to log in this user go away.
		if (options.loginUsers.indexOf(userId) === -1)
		{
			return;
		}

		// Find the button group which has the Add Note button
		const elButtonGroups = elRow.querySelectorAll('th div.btn-group');
		const elButtonGroup  = elButtonGroups[0];

		// Create the icon for the login button
		const elIcon = document.createElement('span');
		elIcon.classList.add('fa','fa-external-link-alt', 'pe-1');
		elIcon.setAttribute('aria-hidden', 'true');

		// Create the text for the login button
		const elSpan       = document.createElement('span');
		elSpan.textContent = Joomla.Text._('PLG_SYSTEM_SKELETONKEY_BTN_LABEL');

		// Create the login button itseld
		const elLink = document.createElement('button');
		elLink.setAttribute('type', 'button');
		elLink.classList.add('btn', 'btn-dark', 'btn-sm');
		elLink.appendChild(elIcon);
		elLink.appendChild(elSpan);

		// Create a special click event handler
		elLink.addEventListener('click', (e) => {
			e.preventDefault();

			const paths = Joomla.getOptions('system.paths');
			const token = Joomla.getOptions('csrf.token');
			const uri   = `${paths ? `${paths.base}/index.php` : window.location.pathname}?option=com_ajax&format=json&plugin=skeletonkey&group=system`;

			// The token and the user ID go in the POST body so they never end up in URLs or access logs.
			const body = new URLSearchParams();
			body.set('user_id', userId.toString());

			if (token)
			{
				body.set(token, '1');
			}

			Joomla.renderMessages({
				info: ['Making request...']
			});

			Joomla.request({
				url:       uri,
				method:    'POST',
				data:      body.toString(),
				headers:   {'Content-Type': 'application/x-www-form-urlencoded'},
				onSuccess: (data, xhr) => {
					const returnedData = JSON.parse(data).data;

					// The plugin answers true, or false, or a code saying why this user cannot be logged in as.
					const reasons = {
						blocked:   'PLG_SYSTEM_SKELETONKEY_ERR_BLOCKED',
						mustreset: 'PLG_SYSTEM_SKELETONKEY_ERR_MUSTRESET',
					};

					if (!returnedData || returnedData[0] !== true)
					{
						const reason = returnedData ? reasons[returnedData[0]] : undefined;

						Joomla.renderMessages({
							error: [Joomla.Text._(reason || 'PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED')]
						});

						return;
					}

					window.open(paths.rootFull, '_blank');
				},
				onError:   (xhr) => {
					Joomla.renderMessages({
						error: [Joomla.Text._('PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED_AJAX')]
					});
				}
			});
		});

		// Append the login button to the button group, after the Add Note button
		elButtonGroup.appendChild(elLink);
	});
}

initSkeletonKey();