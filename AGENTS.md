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
phing compile-js
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

- Commits and tags are signed through 1Password, which sandboxes cannot reach: run every `git commit` / `git tag` outside the sandbox from the first attempt; never use `--no-gpg-sign`. Details: `.claude/memory/git-signing.md`.

## Project memory

Project memory lives in `.claude/memory/`. Read the matching file before starting that kind of work.

| File | Read it when… |
|------|---------------|
| [`architecture.md`](.claude/memory/architecture.md) | Before you change the system, authentication or action log plugin, the login-as flow or the plugin parameters |
| [`translations.md`](.claude/memory/translations.md) | Before you add a language, or add, change or translate language strings (INI files, glossaries, manifest `<languages>` entries) |
| [`security-model.md`](.claude/memory/security-model.md) | Before you triage, rate or fix a security finding, or decide whether it is in scope |
| [`testing.md`](.claude/memory/testing.md) | Before you write or change an end-to-end test (or anything that captures logs, edits site config or needs a DB trigger in a test) |
| [`joomla-compat.md`](.claude/memory/joomla-compat.md) | Before you touch cookies, plugin constructors, the package installer script, `build.xml`'s fileset or the key request protocol |
| [`git-signing.md`](.claude/memory/git-signing.md) | Before you run `git commit` or `git tag` |
| [`recording-memories.md`](.claude/memory/recording-memories.md) | Before you add or change a project memory file |
