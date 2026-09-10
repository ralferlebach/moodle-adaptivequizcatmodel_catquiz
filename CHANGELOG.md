# Changelog – adaptivequizcatmodel_catquiz

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
