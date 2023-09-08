/**
 * @package   Skeletonkey
 * @copyright Copyright (c)2022-2023 Nicholas K. Dionysopoulos
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
		elIcon.classList.add('fa','fa-external-link-alt');
		elIcon.setAttribute('aria-hidden', 'true');

		// Create the text for the login button
		const elSpan       = document.createElement('span');
		elSpan.textContent = Joomla.Text._('PLG_SYSTEM_SKELETONKEY_BTN_LABEL');

		// Create the login button itseld
		const elLink = document.createElement('button');
		elLink.setAttribute('type', 'button');
		elLink.classList.add('btn', 'btn-dark', 'btn-sm', 'ms-1');
		elLink.appendChild(elIcon);
		elLink.appendChild(elSpan);

		// Create a special click event handler
		elLink.addEventListener('click', (e) => {
			e.preventDefault();

			const paths = Joomla.getOptions('system.paths');
			const token = Joomla.getOptions('csrf.token');
			const uri   = `${paths ? `${paths.base}/index.php` : window.location.pathname}?option=com_ajax&format=json&plugin=skeletonkey&group=system&user_id=%d${token ? `&${token}=1` : ''}`;

			Joomla.request({
				url:       uri.replace('%d', userId.toString()),
				onSuccess: (data, xhr) => {
					const returnedData = JSON.parse(data).data;

					if (!returnedData || returnedData[0] === false)
					{
						Joomla.renderMessages({
							error: [Joomla.Text._('PLG_SYSTEM_SKELETONKEY_ERR_LOGINFAILED')]
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
