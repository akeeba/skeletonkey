# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Skeleton Key is a Joomla 4/5/6 extension package that allows administrators to log into the frontend as any other user for troubleshooting purposes. It uses single-use secure tokens with short expiration windows, stored in the `#__user_keys` database table.

## Build System

The build uses **Apache Phing** with shared build infrastructure located at `../buildfiles/`. The Babel toolchain for JS is also in `../buildfiles/node_modules/`.

**Build the release package:**
```bash
phing
```
This runs the default `git` target which compiles JavaScript and creates plugin ZIP packages.

**Compile JavaScript only:**
```bash
phing compile-javascript
```
This transpiles `plugins/system/skeletonkey/media/js/backend.js` → `backend.min.js` + source map using Babel with `@babel/preset-env` and `minify`.

**Create a GitHub release:**
```bash
phing release
```

## Architecture

The package consists of three coordinated Joomla plugins that communicate via Joomla's event system:

### System Plugin (`plugins/system/skeletonkey/`)
The main orchestrator. Injects "Login as user" buttons into the admin users list (`onBeforeDisplay`), handles AJAX token creation (`onAjaxSkeletonkey`), and detects/validates cookies on frontend page load (`onAfterInitialise`). The frontend JavaScript lives in `media/js/backend.js`.

### Authentication Plugin (`plugins/authentication/skeletonkey/`)
Validates tokens during the Joomla authentication flow (`onUserAuthenticate`). Verifies the token hash from the cookie against the database, enforces expiration, and implements attack detection (purges all tokens for a user if a replayed token is detected). Cleans up cookies on logout (`onUserAfterLogout`).

### Action Log Plugin (`plugins/actionlog/skeletonkey/`)
Audit trail. Listens for `onSkeletonKeyRequestLogin` events and logs which admin requested login as which user, plus success/failure.

### Authentication Flow
1. Admin clicks "Login as user" button in backend users list
2. JavaScript sends AJAX request → System plugin generates a random token, stores its hash in `#__user_keys` with short TTL, sets an HTTP-only cookie with the plaintext token
3. New browser tab opens to the frontend
4. System plugin's `onAfterInitialise` detects the cookie and triggers Joomla authentication
5. Authentication plugin validates the token (single-use, checks expiration, verifies hash)
6. Action Log plugin records the event

### Service Providers
Each plugin registers via `services/provider.php` using Joomla's DI container pattern, implementing `ServiceProviderInterface` to register the extension with `PluginInterface`.

## Key Configuration (Plugin Parameters)

- `allowedControlGroups` — user groups permitted to initiate login-as (default: 8 / Super Users)
- `allowedTargetGroups` — user groups that can be logged in as (default: 2 / Registered)
- `disallowedTargetGroups` — user groups that cannot be logged in as (default: 7,8 / Admin & Super User)
- `cookie_lifetime` — token expiration in seconds (default: 10)
- `key_length` — random token length in characters (default: 32)

## Languages

Localization files are in INI format under each plugin's `language/` directory. Supported: en-GB, el-GR, nl-NL.
