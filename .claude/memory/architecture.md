# Architecture and plugin parameters

Read before changing any of the three plugins, the authentication flow or the plugin parameters.

The package consists of three coordinated Joomla plugins that communicate via Joomla's event system:

### System Plugin (`plugins/system/skeletonkey/`)
The main orchestrator. Injects "Login as user" buttons into the admin users list (`onBeforeDisplay`), handles AJAX token creation (`onAjaxSkeletonkey`), and detects/validates cookies on frontend page load (`onAfterInitialise`). The frontend JavaScript lives in `media/js/backend.js`.

### Authentication Plugin (`plugins/authentication/skeletonkey/`)
Validates tokens during the Joomla authentication flow (`onUserAuthenticate`). Verifies the token hash from the cookie against the database, enforces expiration, and implements attack detection (purges all of a user's `#__user_keys` rows when a cookie carries a known series with a wrong token; a replayed, already-used key simply finds no row). Cleans up cookies on logout (`onUserAfterLogout`).

### Action Log Plugin (`plugins/actionlog/skeletonkey/`)
Audit trail. Listens for `onSkeletonKeyRequestLogin` (which admin asked to log in as which user, granted, failed or refused with a reason) and `onSkeletonKeyRedeemLogin` (a key was used to log in).

### Authentication Flow
1. Admin clicks "Login as user" button in backend users list
2. JavaScript sends a POST AJAX request → System plugin audits the request, then generates a random token, stores its hash in `#__user_keys` with short TTL, sets an HTTP-only cookie with the plaintext token
3. New browser tab opens to the frontend
4. System plugin's `onAfterInitialise` detects the cookie and triggers Joomla authentication
5. Authentication plugin validates the token (single-use, checks expiration, verifies hash)
6. Action Log plugin records the request (before the key is issued), refusals, and the redemption

### Service Providers
Each plugin registers via `services/provider.php` using Joomla's DI container pattern, implementing `ServiceProviderInterface` to register the extension with `PluginInterface`.

## Key Configuration (Plugin Parameters)

- `allowedControlGroups` — user groups permitted to initiate login-as (default: 8 / Super Users)
- `allowedTargetGroups` — user groups that can be logged in as (default: 2 / Registered)
- `disallowedTargetGroups` — user groups that cannot be logged in as (default: 7,8 / Admin & Super User)
- `cookie_lifetime` — token expiration in seconds (default: 10)
- `key_length` — random token length in characters (default: 32)
