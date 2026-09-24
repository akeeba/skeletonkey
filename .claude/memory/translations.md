# Translations

## Translation workflow

Translations follow a canonical en-GB → target language flow, in Joomla INI format.

**Glossaries** are Markdown files at `build/glossaries/<lang-tag>.md`. Consult the target language's
glossary before translating and update it with any new terms. Glossaries exist for el-GR, fr-FR, de-DE,
es-ES, it-IT, pt-PT.

**Languages translated** (as of 2026-06-17):

- el-GR, fr-FR, de-DE, es-ES, it-IT, pt-PT — all plugins and the package;
- nl-NL — system plugin only (pre-existing, no glossary in `build/glossaries/`).

**Language files per tag** (7 per language):

- `plugins/system/skeletonkey/language/<tag>/plg_system_skeletonkey.ini` — configuration labels and UI strings
- `plugins/system/skeletonkey/language/<tag>/plg_system_skeletonkey.sys.ini` — plugin name and XML description
- `plugins/authentication/skeletonkey/language/<tag>/plg_authentication_skeletonkey.ini` — empty (no strings)
- `plugins/authentication/skeletonkey/language/<tag>/plg_authentication_skeletonkey.sys.ini` — plugin name and XML description
- `plugins/actionlog/skeletonkey/language/<tag>/plg_actionlog_skeletonkey.ini` — audit log message strings
- `plugins/actionlog/skeletonkey/language/<tag>/plg_actionlog_skeletonkey.sys.ini` — plugin name and XML description
- `build/templates/language/<tag>/pkg_skeletonkey.sys.ini` — package name and XML description

**Manifest requirement:** register every new language file in the owning plugin's `skeletonkey.xml`
under `<languages folder="language">`, and in the package template `build/templates/pkg_skeletonkey.xml`
(4 manifests in total). List languages alphabetically by tag. The root `pkg_skeletonkey.xml` is a
gitignored build copy; don't edit it instead.

**Why:** Joomla will not install or load language files that are not declared in the manifest, and the
glossaries keep terminology consistent between runs.

**How to apply:** When adding a language, create all 7 files, update all 4 manifests, and create or
update the glossary in `build/glossaries/`, and keep the "Languages" line in `AGENTS.md` in step.
