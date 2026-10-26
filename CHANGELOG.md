# Changelog

## 1.3.3 (2026-10-25)

- **Fixed: on a site whose questionnaire runtime is older than 0.4.0, one completed response stopped every later response counting.** `QuestionnaireStatus` (1.3.0) took the site's first completed or amended response as the moment its runtime started recording finished ones. But an older runtime saves every response in-progress, finished or not, and a FHIR API client (the SMART questionnaire app, say) can write a completed one on 8.4.1: from then on, the runtime's own finished responses dropped out. The rule now applies only where the site's runtime is 0.4.0 or later (`QuestionnaireStatus::applies()`); elsewhere every response but entered-in-error counts, as before 1.3.0. Found by the Staff Worklist's end-to-end check on 8.4.1, whose one response saved through core as completed hid its other test responses.

## 1.3.2 (2026-10-25)

- **Fixed: on OpenEMR 8.0.0 to 8.0.0.3, every page that read the session or a CSRF token through `OpenEmr` stopped.** 8.0.x's `SessionWrapperFactory` has only `getWrapper()`; `getActiveSession()` came with 8.1.0. `OpenEmr::session()` now reads 8.0.x's wrapper, and the CSRF calls pass `CsrfUtils` a session only when it is a Symfony session, as 8.1.0's is; given none, 8.0.x's `CsrfUtils` reads `$_SESSION` itself. 1.3.1 had fixed the bag's getters on the same releases; this was the second gap the fresh 8.0.0 install showed, once the login worked.

## 1.3.1 (2026-10-25)

- **Fixed: on OpenEMR 7.0.4 and 8.0.0 to 8.0.0.3, a module using `OpenEmr` stopped every page, the login page included.** Those releases have `OEGlobalsBag` without `getWebRoot()`, `getProjectDir()`, `getSrcDir()` or `getKernel()`, which came with 8.1.0, and `OpenEmr` called them wherever the class existed. Each is now used only when the running OpenEMR has it; otherwise the value OpenEMR stores under its name (`webroot`, `fileroot`, `srcdir`, `kernel`) is read, from the bag on 7.0.4 to 8.0.x and from `$GLOBALS` before. Found on a fresh install of the official 8.0.0 image with every module enabled (the login answered HTTP 500). The tests now run OpenEmr against both shapes of the bag; the two new ones fail on 1.3.0.
- **`OpenEmr::srcDir()`** (OpenEMR's library folder, for the legacy includes) and **`OpenEmr::kernel()`** (null outside a request, and on 8.1.0 and later without asking `getKernel()`, which throws when there is none), so a module needn't call the bag's getters itself.
- `OpenEmr::setting()` reads the bag's `get()`, which every release with the bag has, rather than `getString()`.

## 1.3.0 (2026-10-25)

- **`GuideLabels::check()`:** every label a module's GUIDE.md quotes in bold is a string the module translates; a label from data is named with its file in composer.json (`extra.openhealthcare.guide-labels-elsewhere`), and that file must still contain it. Run on the two guides written so far: the Worklist's 51 labels all match; UDS's three demographics field titles come from its install SQL and are named there.
- **`QuestionnaireRuntime`:** the version of OpenEMR's questionnaire runtime a site serves, read from the runtime file. 0.4.0 (master 2ee0a6a503, backported to rel-850 as 0d8b8709b7) is the first with `enableWhenExpression`, repeats per instance and completed responses; 8.5.0 images built before the backport say 0.2.0.
- **`QuestionnaireStatus`:** which saved responses count as answered, now that runtime 0.4.0 saves a submitted response as completed. The rule needs no setting: until a site's first completed or amended response every response counts; after it, earlier responses count whatever their status and later ones only when completed or amended; entered-in-error never counts. The moment is the earliest `last_updated` (else `create_time`) among completed rows, so an old response edited later doesn't move it back. Checked against OpenEMR's save paths: the clinic's questionnaire pages save with `{ complete: true }`, and no core page saves a draft.
- **The footprint sheet names another openhealthcare module's tables** in its owner column ("the Sliding Fee module (another openhealthcare module)"), where it said "OpenEMR core", and says writes to them apart from writes to core tables. Three modules' sheets change: Revenue Cycle (the Worklist's state), the Staff Worklist (Sliding Fee's two tables, UDS's value set codes) and Title X (Sliding Fee's assessments). Each is written again the same day; no other module's sheet changes.

## 1.2.0 (2026-10-25)

- **The footprint sheet lists the programs a module runs** (a new section, "Programs it runs"): each file that calls `proc_open()`, `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()` or `pcntl_exec()`, as files it writes are listed. A method of the module's own with one of those names, or `curl_exec()`, isn't one, and the detector tests itself on both before it runs. Clinic Forms 0.38.0 is the first module that runs one (LibreOffice, to make PDFs tagged for PDF/UA). A module whose CI installs the newest 1.x (no committed lock file, as ours) must write its sheet again with 1.2.0, or its `footprint --check` fails: corrected 2026-10-25, after Revenue Cycle's and Title X's release CI failed on it. Every module's sheet was written again the same day.

## 1.1.0 (2026-10-23)

- **The footprint tool** (`tools/footprint.php`, or `vendor/bin/footprint`): one tool writes every module's FOOTPRINT.md, in place of a copy in each module. The copies had drifted: UDS's had no permission checks or OpenEMR functions sections, and five modules carried another module's closing notes, claiming checks that weren't theirs. A module's own `tools/footprint.php` now just loads this one.
  - What the sheet can't show is the module's own, from its composer.json (`extra.openhealthcare.footprint-not-covered`); the tool refuses to run without it.
  - From the copies, together: permission checks with a list of levels (`['write', 'addonly']`); methods aren't mistaken for OpenEMR's global functions; files the module writes; core classes that write for it (documents, questionnaires, disclosures); the openhealthcare-common methods it calls, read as its own; install SQL that updates rows, split into the module's own rows and others; `bin/` read with `src/` and `public/`; modules with no install SQL or no `version.php`.
  - Tested on a sample module (`tests/fixtures/sample-module`).

## 1.0.0 (2026-10-23)

- **`OpenEmr`:** one compatibility layer for OpenEMR 7.0 to 8.5, replacing the copies in Clinic Forms, Layout Export and Units Check. It covers settings, site directory, web root, project directory, OpenEMR's version, the event dispatcher (and dispatching to it), the session, CSRF tokens, the access-denied page, the administrator check and the audit log. Every method was the same in all three copies except `deny()`, which now takes the module's name for its log line.
- **`TranslatedStrings::scan()`:** finds translated strings with an apostrophe or quote mark, which OpenEMR's `xl()` shows as a backtick. It returns how many were checked, so a module's test can assert a floor and not pass on nothing.
