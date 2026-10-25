# Changelog

## 1.2.0 (2026-10-25)

- **The footprint sheet lists the programs a module runs** (a new section, "Programs it runs"): each file that calls `proc_open()`, `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()` or `pcntl_exec()`, as files it writes are listed. A method of the module's own with one of those names, or `curl_exec()`, isn't one, and the detector tests itself on both before it runs. Clinic Forms 0.38.0 is the first module that runs one (LibreOffice, to make PDFs tagged for PDF/UA). Each module's sheet gains the section when it next updates this package and writes its sheet again.

## 1.1.0 (2026-10-23)

- **The footprint tool** (`tools/footprint.php`, or `vendor/bin/footprint`): one tool writes every module's FOOTPRINT.md, in place of a copy in each module. The copies had drifted: UDS's had no permission checks or OpenEMR functions sections, and five modules carried another module's closing notes, claiming checks that weren't theirs. A module's own `tools/footprint.php` now just loads this one.
  - What the sheet can't show is the module's own, from its composer.json (`extra.openhealthcare.footprint-not-covered`); the tool refuses to run without it.
  - From the copies, together: permission checks with a list of levels (`['write', 'addonly']`); methods aren't mistaken for OpenEMR's global functions; files the module writes; core classes that write for it (documents, questionnaires, disclosures); the openhealthcare-common methods it calls, read as its own; install SQL that updates rows, split into the module's own rows and others; `bin/` read with `src/` and `public/`; modules with no install SQL or no `version.php`.
  - Tested on a sample module (`tests/fixtures/sample-module`).

## 1.0.0 (2026-10-23)

- **`OpenEmr`:** one compatibility layer for OpenEMR 7.0 to 8.5, replacing the copies in Clinic Forms, Layout Export and Units Check. It covers settings, site directory, web root, project directory, OpenEMR's version, the event dispatcher (and dispatching to it), the session, CSRF tokens, the access-denied page, the administrator check and the audit log. Every method was the same in all three copies except `deny()`, which now takes the module's name for its log line.
- **`TranslatedStrings::scan()`:** finds translated strings with an apostrophe or quote mark, which OpenEMR's `xl()` shows as a backtick. It returns how many were checked, so a module's test can assert a floor and not pass on nothing.
