# Joomla compatibility and packaging decisions

Facts verified against the real Joomla sources (5.4.8 and 6.1.3), and the decisions built on them. Read before touching
cookies, plugin constructors, installer scripts or the build fileset.

## Cookies

- `Cookie::set($name, $value, $options = [])` takes the **options array** on Joomla 5.4 already (joomla/input 3.x, which
  also passes `samesite` to `setcookie()`). The positional signature is deprecated. Use the array form everywhere; there
  is nothing to fork on. The key cookie is `HttpOnly` and `SameSite=Strict`.
- The cookie **name** is `skeletonkey_` + `getHash(Uri::root()` without its scheme `+ user agent)`, in both the system
  and the authentication plugin, and both must derive it identically.
  **Why:** with "Force HTTPS: Administrator only" the key is issued from an `https://` back-end and read on an
  `http://` front-end (known issue #5).
- `Secure` follows the **front-end**: only `force_ssl = 2` (whole site). `isHttpsForced()` answers for the back-end and
  is already true for `force_ssl = 1`, which would make the cookie invisible to an HTTP front-end. Following `force_ssl`
  rather than the request scheme is deliberate, as Joomla does it (see [security-model.md](security-model.md), L5).

## Plugin constructors

`CMSPlugin::__construct($config = [])` has had that shape since before 5.4 (it keeps a deprecated path for the old
"dispatcher first" call, removed in 7.0). The system plugin's constructor is `__construct($config = [])` and the service
provider is the only caller (`new Skeletonkey($config)`). Do not reintroduce `&$subject`.

## Package installer script

- Joomla installs plugins **disabled**. The package script (`build/templates/script.skeletonkey.php`, class
  `Pkg_SkeletonkeyInstallerScript`) enables the three plugins in `postflight()` only when `$type === 'install'`.
  **Why:** on an update we cannot know whether the site owner disabled one on purpose.
- The build picks the script up by name (`script.${build.component}.php` in `build/templates/`); `build.xml`'s `package`
  fileset must list `script.*.php` or it is not shipped, and the package manifest template needs `<scriptfile>`.
- The per-plugin scripts enforce the PHP/Joomla version limits; the package script does not need to, because the
  children refuse to install.

## Key request protocol

The button POSTs `user_id` and the anti-CSRF token in the **body** (never the URL) to
`index.php?option=com_ajax&format=json&plugin=skeletonkey&group=system`. The plugin answers `true`, `false`, or a reason
code the button turns into a message (`blocked`, `mustreset`). Every refusal by an authenticated user is audited; the
request itself is audited before the key is issued.
