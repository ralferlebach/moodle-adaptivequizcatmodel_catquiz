# Changelog – adaptivequizcatmodel_catquiz

## Interne Version 2026102400

- #9: `tests/backup/duplicate_test.php` duplizierte mit `duplicate_module()` (seit Moodle 5.2 veraltet,
  MDL-86858) und löschte mit `course_delete_module()` (ebenso, MDL-86856). Die Adapter-Suite meldete deshalb auf
  5.2 und 5.3 eine Notice; die zweite Deprecation erschien erst, nachdem die erste behoben war. Jetzt nutzt der
  Test `core_courseformat\local\cmactions::duplicate()` und `::delete()`, wo die Methoden existieren; auf 5.1
  bleibt es bei den alten Funktionen. Die Weiche prüft die Methode, wie in mod_adaptivequiz. Die Prüfungen des
  Tests sind unverändert. Der Branch `ALiSe-v-1.2.0-legacy` (Moodle 4.5) ist nicht betroffen.

## Interne Version 2026101802

- local_catquiz #126 (Nacharbeitsauftrag 18.10.2026): Reproducer `tests/duplicate_question_reproducer_test.php`
  über Host, Adapter und CATquiz – Pilotfragen unter den ersten Fragen, eine Frage in zwei Skalen, Neuladen,
  ein veralteter zweiter Tab sendet eine schon verarbeitete Antwort, Fortsetzen –: jede Frage der Usage genau
  einmal, jede Antwort einmal gezählt, kein technisches Duplikat, ein Abschluss, eine Bewertung, gültiges
  Ergebnis. Zahn-Test: ohne QUBA-Ausschluss und ohne Registrierung der Pilot-Rückgabe rot (Duplikate).
- #6: Langlauf mit dem ganzen Feedbackpfad (`CATQUIZ_LONGRUN_FEEDBACK=1`, 512 MiB, Prozess-Peak im Ergebnis);
  optionaler schwerer CI-Schritt (`workflow_dispatch`, Eingabe `longrun`), der scheitert, wenn der Lauf
  übersprungen wurde.

## Interne Version 2026101801

- Issue #6 (Re-Audit 17.10.2026), Anteil des Adapters an der Versuchs-Timeline von local_catquiz:
  - Neue Spans `adapter:prepare_attempt_caches` (im Rückruf „Versuch angelegt“, neben
    `adapter:create_new_for_attempt`) und `adapter:fetch_question_id` (in `adapter:evaluate`). Alle
    Adapter-Spans tragen `meta.attemptid`.
  - Der Rückruf „Versuch angelegt“ nennt den Versuch der Timeline sofort: auch eine Anfrage, die vor der
    ersten Auswahl scheitert, hat ihren Trace.
  - Der Abschluss war nicht in der Timeline: Der Rückruf „Versuch beendet“ setzt jetzt den Versuch mit der Art
    `complete` (local_catquiz `timeline::KIND_COMPLETE`, sonst wie bisher) und misst
    `adapter:post_complete_attempt` ⊇ `adapter:attempt_finalizer`. Ein Prozess, der mehrere Versuche beendet
    (geplante Aufgabe Zeitlimit), schreibt je Versuch eine Zeile.
  - Neu laden: der Versuch wird nur noch bei eingeschaltetem Trace gelesen.
  - Mit local_catquiz 2026101703: Speicher je Span, Pseudo-Span `request` mit gemeinsamer Request-ID von
    Host und CATquiz, Prozess, Speicher und Fatal-Error-Auswertung (siehe `local_catquiz/doc/attempt-trace.md`).
  - Tests `tests/trace_test.php`: Trace aus ändert nichts und schreibt nichts, Trace an wählt dieselben
    Fragen; Host → Adapter → CATquiz als eine korrelierte Timeline (gemeinsame Request-ID, zeitliche
    Schachtelung); Abschluss-Anfrage; je beendetem Versuch eine Zeile.
  - Langlauf: `cli/measure_longrun.php` (p50/p95 je Span, Speicher- und QUBA-Wachstum je Item, Fatals, offene
    Spans, Request-IDs; mehrere Worker als eigene Prozesse) und `tests/longrun_test.php` (Gruppe `longrun`,
    nur mit `CATQUIZ_LONGRUN=1`; 160 Items aus 420). Messwerte: `attempt-trace-longrun.md`. Gemeinsame
    Klassen `local\monitoring\request_simulator` und `local\monitoring\longrun_stats`.
- `mod_form_extension::validation_callback()`: Mit einem anderen CAT-Modell war `$errors` undefiniert und die
  Rückgabe ein Typfehler; jetzt `[]`. Test `tests/local/catmodel/form/mod_form_extension_test.php`.
- Issue #8, Audit 18.10.2026: Die Recovery hat keinen Stichtag mehr. Kandidaten bestimmen Rekonstruierbarkeit
  (CAT-Versuch, beendet, Antworten gespeichert), Invalidität (kein gültiger Snapshot) und Idempotenz
  (Protokoll) – nicht das Startdatum; ein Versuch ohne `starttime` ist Kandidat wie jeder andere.
  `legacy_result_recovery::MIGRATION_SINCE` entfällt. Der Upgrade-Task läuft ohne Cutoff, auch ein früher mit
  Cutoff eingestellter. `--since` bleibt im CLI als optionaler Filter von Hand (dann `outside_window` wie
  bisher). Der Upgrade-Schritt 2026101703 stellt den Task erneut ein: was der alte Stichtag 14.07.2026
  ausließ, wird nachgeholt, bereits Protokolliertes übersprungen. Im Protokoll stehen `starttime` 0 (keine
  Startzeit) und `cutoff` 0 (kein Filter). Tests: Versuche vor und nach dem 14.07.2026 und ohne Startzeit,
  33/25 und 4/12 bleiben ungültig, gültige Globalskala mit heutigem Bereich über die Linkfunktion benotet,
  zweiter Lauf ändert nichts, Upgrade nach der alten Migration.

## Interne Version 2026101702

- Re-Audit 17.10.2026 (G3): Die CI checkt Host und CATquiz als Zweige aus; bei einem Lauf mit gemischten
  Ständen fehlten Host-Klassen oder Datensätze. Manuelle Läufe können beide jetzt auf feste Refs pinnen
  (`workflow_dispatch`-Eingaben `host_ref`, `catquiz_ref`), und jeder Lauf schreibt die verwendeten SHAs als
  Hinweis ins Protokoll – ein rotes Ergebnis lässt sich so einem Stand zuordnen.
- Zwei phpcs-Warnungen (Zeilenlänge) in `attempt_cleanup` und seinem Test behoben.

## Interne Version 2026100906

- Issue #8: Ältere CATquiz-Versuche mit ungültigem Snapshot wurden durch kein Upgrade und keinen Regrade
  neu bewertet. Ursache: Der Regrade der Aktivität nimmt vorhandene Snapshots bewusst nicht neu, und
  `snapshot_results` bearbeitet nur fehlende Snapshots. Die Recovery gab es bisher nur per CLI.
  - Das Upgrade auf 2026100906 stellt einmal den Ad-hoc-Task `task\recover_legacy_results` ein. Er führt
    die vorhandene `legacy_result_recovery` aus, mit festem Stichtag
    `legacy_result_recovery::MIGRATION_SINCE` = 14.07.2026 (Startzeit des CAT-Versuchs, 00:00 in der
    Zeitzone des Servers). Der normale Regrade bleibt unverändert.
  - Bewertet wird nur die Globalskala. `isprimary` und ein alter Grund `no_primary_scale` spielen keine
    Rolle. Bei `range_not_recorded` gilt der heutige Bereich der Globalskala, im Protokoll als
    `current_scale` ausgewiesen.
  - Neu: Mehr gestellte Fragen als das Maximum (33/25) sind ungültig (`max_questions_exceeded`, aus der
    zentralen Validierung von local_catquiz 2026100922/2026100923).
  - Robustheit: Ein Fehler bei einem Versuch oder einer Aktivität stoppt die übrigen nicht und steht im
    Task-Log. Snapshot, Note und Protokoll einer Aktivität werden gemeinsam geschrieben oder gar nicht.
    Ein Versuch, den die Neubeurteilung gültig gesetzt hat und dessen Snapshot noch keinen Prozentwert
    hat, bleibt Kandidat.
  - CLI und Task lesen den Stichtag gleich (`legacy_result_recovery::cutoff()`).
  - Tests: `legacy_result_recovery_test` +6, darunter der Weg Upgrade → Task → Gradebook mit gemischtem
    Bestand.

## Interne Version 2026100905

- CI-Fix: Die Spalte `rangesource` der Recovery-Tabelle `adaptivequiz_cat_recovery` war CHAR NOT NULL mit
  `''` als Vorgabe. XMLDB meldet das bei der Installation als Debugging-Meldung, und moodle-plugin-ci
  bricht dann `phpunit --install` ab (rote PHPUnit- und Behat-Läufe hier und in local_catquiz, die den
  Adapter mitinstallieren). Die Spalte ist jetzt NULL-fähig ohne Vorgabe; der Upgrade-Schritt 2026100905
  stellt bestehende Installationen um.

## Interne Version 2026100904

- Bewertung der Globalskala nach den Kriterien des Tests (Entscheidung vom 14.10.2026): Mindestfragen pro
  Skala und des Tests, maximaler Standardfehler, Fraction-Regel – angewandt auf N, Standardfehler und
  Fraction, die CATquiz für die Globalskala des Versuchs gespeichert hat, mit den Testeinstellungen des
  Versuchs (Progress, sonst die heutigen des Tests). Das Urteil der Strategie über die Skala zählt nicht
  mehr. Braucht local_catquiz 2026100918 (`global_scale_validity`); mit einem älteren CATquiz gilt das
  bisherige Urteil ohne die Darstellungsgründe. Die Recovery urteilt im Probelauf nach derselben Regel.
  Tests: `result_provider_test::test_verdict_on_the_global_scale` (10 Fälle),
  `::test_graded_on_the_global_scale`.

## Interne Version 2026100903

- **Bewertung auf der Globalskala** (Entscheidung vom 14.10.2026): Die Note ist das Ergebnis der
  Globalskala des Versuchs – der Testskala, auf der CATquiz ihn finalisiert hat –, nicht mehr das der
  Skala, die eine Strategie zuerst berichtet. Bewertbar ist sie, wenn sie statistisch gültig und gemessen
  ist; „nicht primär“, „Bericht abgeschaltet“ und „verborgen“ stehen dem nicht im Weg. Damit folgt auch die
  Abschlussregel „gültiges Ergebnis“ der Globalskala. Neue Gründe: `no_stored_result` (kein Ergebnis je
  Versuch gespeichert), `global_scale_missing`. Tests: `result_provider_test` (Globalskala, 6 Urteile;
  Globalskala des Versuchs statt der heutigen Testskala).
- **Recovery älterer Ergebnisse** (local_catquiz #137, Konzept „Legacy-Recovery“ vom 14.10.2026):
  `cli/recover_legacy_results.php --since=YYYY-MM-DD [--execute] [--attemptid=N] [--csv=DATEI]`, ohne
  `--execute` immer Probelauf. Triage: schon rekonstruiert, kein CAT-Versuch, vor dem Stichtag gestartet
  (`starttime`; leer = davor), nie beendet, keine gespeicherten Antworten → unverändert. Sonst: nach
  heutigen Regeln aus den Antworten beurteilt (`attempt_revalidator`), fehlender Bereich = heutiger Bereich
  der Skala, markiert; danach Snapshot und Gradebook über die Kette des Hosts. Jeder rekonstruierte Versuch
  wird in der neuen Tabelle `adaptivequiz_cat_recovery` protokolliert (alter Grund, Schritte, Bereich und
  Herkunft, Urteil, neuer Grund, Prozent, Code-Version); ein zweiter Lauf überspringt ihn. Das CLI wird
  ausgeliefert (`.gitattributes`). Tests: `local/recovery/legacy_result_recovery_test` (12 Fälle, u. a.
  4/12, Stichtag, Probelauf ändert nichts, Wiederholung ändert nichts).

## Interne Version 2026100902

- Issue #137 (CATquiz): Der Ergebnisbereich kommt aus dem beim Finalisieren eingefrorenen Bereich
  (`rangelower`/`rangeupper` der Personenparameter), nie aus der heutigen Skala. Fehlt er (Versuch vor
  CATquiz 2026100913 finalisiert), ist das Ergebnis ungültig mit Grund `range_not_recorded`; es wird
  kein Bereich erfunden.
- mod_adaptivequiz #20: Endet CATquiz einen Test, gibt der Adapter neben der Meldung den Grund als
  Code weiter (`local\catmodel\stop_code`): Maximum, Zielgenauigkeit (auch „Schätzung stabil“),
  keine passenden Fragen, Zeitlimit, technischer Fehler; unbekannte Status behalten ihren Namen
  und erhalten auf der Ergebnisseite einen neutralen Hinweis. Ein älteres CATquiz ohne Status
  liefert keinen Code.

## Interne Version 2026100901

- Issue #7: CATquiz als Ergebnisquelle im Ergebnisvertrag der Aktivität (mod_adaptivequiz #14):
  `local\catmodel\result\catquiz_result_provider`. Score ist die beim Finalisieren
  abgelegte Fähigkeit der primären Skala dieses Versuchs (`local_catquiz_personparams`,
  `isprimary`/`isvalid`), nicht der aktuelle Personenparameter und nie das `measure` der
  Aktivität. Bereich ist der Fähigkeitsbereich der Skala (Wurzelskala), den die Aktivität
  beim Abschluss mit dem Versuch festhält. Ungültig (u. a. Fraktion 0/1), nicht finalisiert,
  keine oder mehrdeutige primäre Skala, fehlender Bereich: kein bewertbarer Score, der
  Grund geht mit. Linkfunktion `linear`. Gradebook und Abschluss bleiben bei der Aktivität;
  die Note entsteht im Abschlussablauf, ohne dass die Ergebnisseite geöffnet wird.
  Benötigt mod_adaptivequiz 2026100902.

## Interne Version 2026100900

- mod_adaptivequiz #18: Rückruf `adaptivequizcatmodel_catquiz_completed_attempt_review_allowed()`.
  Die Aktivität fragt ihn, bevor sie dem Besitzer die Dateien der Fragen eines abgeschlossenen
  Versuchs ausliefert (Bilder, STACK-Grafiken in den Frage-Modals), auch bei inzwischen
  verborgener Aktivität. Freigabe nach der Testeinstellung „Fragen anzeigen“
  (`catquiz_showquestion`). Ohne passendes local_catquiz wird nichts freigegeben.

## 1.0.4 (interne Version 2026082704)

- **Fünf tote, nicht ladbare Klassen entfernen** – als **Git-Patch**
  `adaptivequizcatmodel_catquiz-remove-dead-classes.patch`, weil ein ZIP über
  einem vorhandenen Checkout keine Dateien löscht. Genau deshalb meldete der
  Wächtertest sie im letzten CI-Lauf erneut.
  - Betroffen: `difficulty_logit`, `cat_calculation_steps_result`,
    `cat_model_params`, `question_answer_evaluation`,
    `question_answer_evaluation_result`.
  - Ihr Namespace lautet `…\local\catmodel\local\…`, die Dateien liegen unter
    `classes/local/…`; das Verzeichnis `classes/local/catmodel/local/` existiert
    nicht. Keine der fünf war je ladbar.
  - **Namespace-Reparatur wäre der falsche Fix**: `cat_calculation_steps_result`
    importiert `mod_adaptivequiz\local\catalgorithm\difficulty_logit`, und diese
    Klasse existiert im Host-Modul **nicht** (verifiziert). Der tote Code zeigt
    also zusätzlich ins Leere. Produktiv genutzt werden durchgehend die Pendants
    aus `local_catquiz`.
  - Gegengeprüft: Patch gegen den tatsächlichen Repo-Stand anwendbar; danach alle
    vier Adapter-Suiten grün (9 Tests).

## 1.0.4 (interne Version 2026082703)

- **Slot-Reuse-Logik nachgezogen.** Dieses Repo hinkte der ausgelieferten Fassung
  hinterher: `evaluate_ability_to_administer_next_item()` rief
  `catquiz_handler::fetch_question_id()` **unbedingt zuerst** auf und sah erst
  danach auf den vorherigen Slot. Damit ist der Zweck verfehlt – ist das
  vorherige Item noch unbeantwortet (Reload oder Resume vor dem Absenden), legt
  `mod_adaptivequiz` einen **zweiten** Slot für dasselbe Item an, Question-Usage
  und CAT-Fortschritt laufen auseinander und der Versuch überschreitet seine
  konfigurierte Länge.
  - Neu wird der aktive Vorgänger-Slot **vor** der CAT-Auswahl geprüft und über
    `next_item::from_quba_slot()` wiederverwendet. Die Logik entspricht jetzt
    exakt der Fassung, die mit `mod_adaptivequiz` 3.0.0 ausgeliefert wird.
  - Das erklärt auch den Fehlschlag im ersten CI-Lauf
    (`TypeError: return_settings(): Return value must be of type stdClass, null
    returned`): Der Adapter lief in die CAT-Auswahl, obwohl er den Slot hätte
    wiederverwenden müssen.
- **Neuer Test** `catquiz_item_administration_slot_reuse_test`: Ein unbeantwortetes
  Vorgänger-Item wird in seinem eigenen Slot erneut ausgeliefert, **ohne** die
  CAT-Engine zu befragen; ein bereits bewertetes Item wird nicht wiederverwendet.
  Zahn-getestet.
  - Bewusst unter eigenem Namen abgelegt, damit ein bereits vorhandener
    `catquiz_item_administration_test` nicht überschrieben wird.

## 1.0.4 (interne Version 2026082702)

- **Neu: `attempts_report_url`-Callback.** Ohne ihn rendert `mod_adaptivequiz` die
  Versuchszahl auf der Aktivitätsseite als reinen Text
  (`attempts_number::when_custom_catmodel_in_use`) – eine Lehrkraft kam damit
  weder zu einer Versuchsübersicht noch zur Aktion „Close attempt". Der Callback
  verweist auf `local/catquiz/feedback.php` mit `courseid` und `instanceid`.
- **Neu: GitHub-Workflows** `moodle-plugin-ci-dev.yml` (lint-php → phpunit über
  PHP 8.1/8.2/8.3 × pgsql/mariadb) und `moodle-plugin-ci-main.yml` (volle Matrix
  auf `main`). Abgeleitet aus den Vorlagen von `local_catquiz`; Behat-, Grunt-,
  Mustache- und Jest-Schritte entfallen, da dieses Sub-Plugin weder `amd/` noch
  `templates/` noch `tests/behat/` besitzt.
- **Neu: Unit-Tests.**
  - `lib_test` pinnt den Callback-Vertrag zu `mod_adaptivequiz`: Auffindbarkeit
    über `get_plugin_list_with_function()`, Ziel-URL, und end-to-end, dass die
    Versuchszahl nur wegen dieses Callbacks zum Link wird.
  - `catquiz_item_administration_factory_test` pinnt die Interface-Verträge und
    die Methodensignatur zur Host-Schnittstelle.
  - `autoloading_test` stellt sicher, dass der Namespace **jeder** Klasse zu ihrem
    Pfad passt.
- **Entfernt: fünf nicht autoloadbare Klassen** (`difficulty_logit`,
  `cat_calculation_steps_result`, `cat_model_params`, `question_answer_evaluation`,
  `question_answer_evaluation_result`). Ihr Namespace lautete
  `…\local\catmodel\local\…`, die Dateien lagen aber unter `classes/local/…`; das
  Verzeichnis `classes/local/catmodel/local/` existierte nie. Per `class_exists()`
  verifiziert: **keine** von ihnen war ladbar.
  - Folgenlos, weil es tote Duplikate waren: `lib.php` und die Item-Administration
    importieren durchweg die Live-Klassen aus `local_catquiz`, und der adaptereigene
    `cat_calculation_steps_result` importierte `difficulty_logit` aus
    `mod_adaptivequiz`. Die fünf Dateien referenzierten nur einander.
  - Gegengeprüft: DB-Upgrade grün, und der vollständige Attempt-Durchlauf in
    `local_catquiz` (`test_all_wrong_attempt_drives_ability_down`, 55 Assertions)
    läuft unverändert.
- **Behoben: drei vorbestehende PHPDoc-Fehler** (`mod_form_extension`,
  `catquiz_item_administration`, `restore_subplugin`) – sonst wäre der neue
  Workflow schon im ersten Lauf rot gewesen.
- phpcs Exit 0, PHPDoc 0 Fehler, alle Unit-Suiten grün.
