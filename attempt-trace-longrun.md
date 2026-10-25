# Langlauf: 160 Items über Host, Adapter und CATquiz (Adapter #6)

Gemessen am 18.10.2026 mit `tests/longrun_test.php` (PHPUnit, Gruppe `longrun`):

    CATQUIZ_LONGRUN=1 CATQUIZ_LONGRUN_OUT=/tmp/longrun.json \
        vendor/bin/phpunit public/mod/adaptivequiz/catmodel/catquiz/tests/longrun_test.php

Aufbau: Simulationspool (`local/catquiz/tests/fixtures/simulation.xml`, 420 Fragen,
`simulation.csv`), Strategie FASTEST, Mindest- und Höchstzahl 160, Antworten im Muster
1010, Trace an. Jede Anfrage läuft wie `attempt.php` (`local\monitoring\request_simulator`):
Antwort verarbeiten, nächstes Item vergeben (3.0: `cat_session::administer_next_item`,
Legacy: `cat_session::run_item_administration`), Versuch abschließen, wenn das CAT-Modell
stoppt; Host-Timing (`request_timing::reset_request()`) und Timeline starten je Anfrage neu,
der Trace wird nach jeder Anfrage geschrieben. Ein Prozess für alle Anfragen: die
Prozess-Speicherwerte enthalten, was PHPUnit und Moodle-Caches im Prozess behalten.

| | 3.0 (Moodle 5.1.8, PHP 8.3.6) | Legacy (Moodle 4.5.15, PHP 8.3.6) |
|---|---|---|
| Anfragen / Items / verschiedene Fragen | 161 / 160 / 160 | 161 / 160 / 160 |
| Laufzeit gesamt | 37,4 s | 34,0 s |
| Anfrage p50 / p95 / max (ms, ohne Trace-Schreiben) | 222,4 / 315,3 / 408,8 | 189,4 / 317,9 / 452,6 |
| Fehler / Fatals / offene Spans | 0 / 0 / 0 | 0 / 0 / 0 |
| Request-IDs verschieden / fehlend | 161 / 0 | 161 / 0 |
| Prozessspeicher am Anfrageende, erste → letzte (KB) | 80745,5 → 92360,5 | 72897,4 → 87675,4 |
| Steigung Prozessspeicher (KB/Item) | 49,0 | 52,8 |
| Spitze je Anfrage, max (KB) / Steigung (KB/Item) | 117760,0 / 61,9 | 116224,0 / 61,4 |
| QUBA laden: erstes / letztes Zehntel p50 (ms), Steigung (ms/Item) | 1,6 / 11,7, 0.0748 | 1,5 / 10,8, 0.0795 |

## Spans je Anfrage (ms)

| Span | 3.0 p50 | 3.0 p95 | Legacy p50 | Legacy p95 |
|---|---|---|---|---|
| `host:administer_item` | 153,6 | 220,2 | 138,2 | 215,5 |
| `host:lock_wait` | 0,4 | 0,6 | 0,1 | 0,2 |
| `host:process_response` | 55,8 | 101,3 | 50,6 | 142,6 |
| `host:quba_load` | 7,7 | 20,9 | 6,8 | 18,0 |
| `host:quba_save` | 3,9 | 71,8 | 5,8 | 81,6 |
| `host:select_item` | 139,4 | 207,6 | 120,7 | 192,2 |
| `adapter:evaluate` | 139,4 | 207,5 | 120,7 | 192,2 |
| `adapter:fetch_question_id` | 138,6 | 206,6 | 120,1 | 191,7 |
| `catquiz:fetch_question_id` | 138,6 | 206,6 | 120,0 | 191,6 |
| `testenvironment` | 0,7 | 1,0 | 0,5 | 0,8 |
| `contextcreator` | 39,9 | 63,5 | 29,1 | 53,4 |
| `context:person_ability` | 22,7 | 44,6 | 13,8 | 29,9 |
| `strategy` | 97,2 | 152,1 | 89,4 | 144,7 |
| `strategy:update_personability` | 27,8 | 42,6 | 23,9 | 42,4 |
| `strategy:fisherinformation` | 10,0 | 19,2 | 9,9 | 17,2 |
| `strategy:progress_save` | 8,4 | 15,6 | 7,5 | 16,5 |
| `pool:cache_lookup` | 1,8 | 2,8 | 1,8 | 2,7 |
| `host:load_question` | 3,0 | 4,3 | 3,0 | 4,6 |
| `host:question_usage` | 2,7 | 3,9 | 2,3 | 6,6 |

## Einmalige Spans (ms)

| Span | 3.0 | Legacy |
|---|---|---|
| `adapter:post_create_attempt` | 3,1 | 3,6 |
| `adapter:create_new_for_attempt` | 3,0 | 3,4 |
| `adapter:prepare_attempt_caches` | 0,1 | 0,1 |
| `prepare_attempt_caches` | 0,1 | 0,1 |
| `pool:query` | 59,8 | 63,0 |
| `adapter:post_complete_attempt` | 29,9 | 28,8 |
| `adapter:attempt_finalizer` | 29,9 | 28,8 |

## Lesart

- Die Korrelation trägt über den ganzen Versuch: jede der 161 Anfragen hat ihre eigene
  `requestid` (die des Hosts), die Spans liegen ineinander (`host:select_item` ⊇
  `adapter:evaluate` ⊇ `adapter:fetch_question_id` ⊇ `catquiz:fetch_question_id`), die
  letzte Anfrage ist `complete` mit `adapter:attempt_finalizer`.
- Die Auswahl (`catquiz:fetch_question_id`) ist der größte Anteil; darin
  `contextcreator` (vor allem `context:person_ability`) und `strategy`
  (`update_personability`, `fisherinformation`). Der Adapter selbst kostet neben der
  Auswahl unter 1 ms (Differenz `adapter:evaluate` – `catquiz:fetch_question_id`).
- Die QUBA-Ladezeit wächst mit der Länge des Versuchs etwa linear (rund 0,08 ms je Item);
  `host:process_response` und `host:quba_save` wachsen mit.
- Der Prozessspeicher wächst um rund 50 KB je Item. Im Web-Betrieb ist jede Anfrage ein
  eigener Prozess; die Spitze je Anfrage (`memory_get_peak_usage(true)` nach
  `memory_reset_peak_usage()`) liegt hier über dem Prozessspeicher und ist daher kein
  Webwert. Für Webwerte `cli/measure_longrun.php --workers=N` (je Worker ein Prozess) oder
  den Trace der Produktion lesen.
- Der Test prüft aggregiert mit weitem Rand, nicht punktgenau: kein Fehler, kein Fatal,
  kein offener Span, jede Anfrage mit eigener ID, Prozessspeicher < 128 KB/Item, Spitze
  < 512 KB/Item.
