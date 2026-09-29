# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

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

## Testing

Two separate suites; PHPUnit 11 is installed globally (`composer global require phpunit/phpunit ^11`), never as a project dependency.

- **Unit** (`UnitTest/`, `phpunit.xml`): `phpunit` from the repo root. No Joomla, no Docker; covers `Helper\DbQuery` and the shipped files (language, manifests, version limits, defaults). See `UnitTest/README.md`.
- **End-to-end** (`tests/integration/`, `phpunit-integration.xml`): `tests/integration/docker/run.sh` provisions a throwaway Joomla + Apache + MySQL stack in Docker (site on http://localhost:8200), installs the package, runs the suite over real HTTP and tears it down. `--keep-containers` then `phpunit -c phpunit-integration.xml` to iterate; `--matrix` for Joomla 5.4 / 6.0 / 6.1 × lowest/highest PHP. See `tests/integration/README.md`.

Known product bugs are tracked in the git-ignored `known-issues.md`; tests covering them skip with `Known issue #N` via `assertOrKnownIssue()` rather than asserting the wrong behaviour.

## Architecture

The package consists of three coordinated Joomla plugins that communicate via Joomla's event system:

### System Plugin (`plugins/system/skeletonkey/`)
The main orchestrator. Injects "Login as user" buttons into the admin users list (`onBeforeDisplay`), handles AJAX token creation (`onAjaxSkeletonkey`), and detects/validates cookies on frontend page load (`onAfterInitialise`). The frontend JavaScript lives in `media/js/backend.js`.

### Authentication Plugin (`plugins/authentication/skeletonkey/`)
Validates tokens during the Joomla authentication flow (`onUserAuthenticate`). Verifies the token hash from the cookie against the database, enforces expiration, and implements attack detection (purges all of a user's `#__user_keys` rows when a cookie carries a known series with a wrong token; a replayed, already-used key simply finds no row). Cleans up cookies on logout (`onUserAfterLogout`).

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

Localization files are in INI format under each plugin's `language/` directory. Supported: en-GB (source), de-DE, el-GR, es-ES, fr-FR, it-IT and pt-PT; nl-NL covers the system plugin only.

## Git: commit and tag outside the sandbox

Commits and tags are always signed, with a key held in 1Password. The 1Password signing agent is reached
over a local socket that agent sandboxes do not expose, so a sandboxed `git commit` or `git tag` **always**
fails (e.g. `error: 1Password: Could not connect to socket. Is the agent running?`).

Run every `git commit` and `git tag` **outside the sandbox from the first attempt** — in Claude Code with
`dangerouslyDisableSandbox: true`, in other harnesses with their equivalent unsandboxed / escalated
execution. Do not try the sandboxed form first, do not diagnose the failure, and never work around it
with `--no-gpg-sign`, `-c commit.gpgsign=false` or unsigned tags.

## Project memory

Project memory lives in `.claude/memory/`, committed with the code, so that it is shared across machines
and across agentic harnesses (Claude Code, Codex, Qwen Code, Kimi Code, Junie, …). Read the relevant file
**before** starting work that matches its trigger:

| Before you… | Read |
|---|---|
| Add a language, or add, change or translate language strings (INI files, glossaries, manifest `<languages>` entries) | `.claude/memory/translations.md` |
| Triage, rate or fix a security finding, or decide whether it is in scope | `.claude/memory/security-model.md` |

### Recording new memories

This is the **default and only** place for project memory. Do not write memories for this project to a
harness's private memory store (such as Claude Code's auto-memory under `~/.claude/projects/`); write
them here instead:

- Add to the existing topic file when one fits; otherwise create a new kebab-case `.md` file named after
  the topic, and add a row for it to the table above with a concrete trigger.
- Plain Markdown, no frontmatter. State the rule, then **Why:** (the reason or incident behind it) and
  **How to apply:**. Link related files with relative Markdown links.
- Don't record what the code, Git history or an existing `AGENTS.md` already says — update that
  `AGENTS.md` instead when the rule belongs there. Remove or correct entries that turn out wrong.
- These files are committed: no secrets, credentials, customer data or personal details.
