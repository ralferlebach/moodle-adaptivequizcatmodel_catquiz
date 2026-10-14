# Changelog – adaptivequizcatmodel_catquiz

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
