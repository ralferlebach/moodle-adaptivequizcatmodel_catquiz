# Stoppvertrag CATquiz → Adapter → mod_adaptivequiz (Adapter-Issue #12)

Stand 17.11.2026. Gilt für beide Linien. Eine Stelle übersetzt: `classes/local/catmodel/stop_code.php`.

## 1. Grundsätze

- **Ein Stoppcode sagt, warum ein Test endete, nicht ob das Ergebnis gilt.**
  - Die Gültigkeit kommt getrennt über den Ergebnis-Provider (`catquiz_result_provider`) in den Snapshot des Hosts:
    `resultvalid`, `resultstatus`, `resultreason`.
  - Bewertungsübersicht und Abschluss lesen nur den Snapshot (Host #14), nie den Stoppcode.
- **Ein technischer Fehler bleibt als Fehler erkennbar** (`error`). Keine Übersetzung macht aus `error` oder dem
  historischen CAT-Status -1 einen fachlichen Erfolg.
- **Ein unbekannter Status behält seinen Namen.** Der Host zeigt dazu einen neutralen Hinweis, nie den Hinweis eines
  regulären Endes. Ein leerer oder fehlender Status ergibt keinen Code.
- **Kein Bestand wird umgedeutet.** Gespeicherte Stoppcodes und CAT-Status bleiben, wie sie sind; es gibt keine
  Neubewertung durch diesen Vertrag.

## 2. Tabelle

| CATquiz-Status (`local_catquiz\local\status`) | Nr. | Host-Code (`stop_reason`) | Hinweis | Bedeutung |
|---|---|---|---|---|
| `reachedmaximumquestions` | 4 | `maximumquestions` | keiner | Regulär: die Höchstzahl an Fragen ist erreicht |
| `stoptargetprecision` | 11 | `targetprecision` | Info | Regulär: die Hauptskala ist vor der Höchstzahl genau genug gemessen |
| `abortpersonabilitynotchanged` | 5 | `targetprecision` | Info | Regulär: die Schätzung bewegt sich nicht mehr; so genau, wie es mit dem Pool geht – nicht „keine Fragen mehr“ |
| `stopscalescomplete` | 13 | `scalescomplete` | Info | Regulär (local_catquiz #172): jede gemessene Skala ist nach ihrer eigenen Regel fertig, das Minimum ist erreicht |
| `noremainingquestions` | 1 | `noeligiblequestions` | Info | Keine passende Frage mehr, nach dem Minimum |
| `noremainingquestionsbeforeminimum` | 10 | `noeligiblebeforeminimum` | Info | Keine passende Frage mehr **vor** dem Minimum (#12 AK2); über die Gültigkeit entscheidet der Snapshot |
| `errornoitems` | 7 | `noeligiblequestions` | Info | Keine Items mit Parametern für die gewählten Skalen |
| `emptyfirstquestionlist` | 6 | `noeligiblequestions` | Info | Keine erste Frage verfügbar |
| `exceededmaxattempttime` | 8 | `timelimit` | Info | Zeit im Versuch abgelaufen |
| `attemptclosedbytimelimit` | 9 | `timelimit` | Info | Vom Task nach Ablauf geschlossen |
| `error` | 12 | `error` | Fehler | Technischer Fehler |
| `errorfetchnextquestion` | 3 | `error` | Fehler | Technischer Fehler beim Holen der nächsten Frage |
| `testiteminrelatedscale` | 2 | `error` | Fehler | Konfigurationsfehler |
| `statusundefined` | -1 | – | – | Historisch, z. B. der Fallback vor 2026100922. Wird nicht übersetzt; der gespeicherte Host-Code bleibt |
| unbekannt | – | eigener Name | neutral | Ein neuerer CATquiz-Status, den dieser Adapter noch nicht kennt |

## 3. Technischer Fehler und Gültigkeit (Host #23, Stand 18.11.2026, freigegeben 19.11.2026)

Ursache und Gültigkeit sind getrennt modelliert:

| Fall | `stopcode` | CAT-Status | Gültigkeit (`resultvalid`) | Note | Abschluss |
|---|---|---|---|---|---|
| Technischer Fehler vor dem Minimum | `error` | 12 | 0 (`min_questions_not_reached` u. a.) | keine | nicht erfüllt |
| Technischer Fehler nach dem Minimum, Messung gültig | `error` | 12 | 1 (aus der Messung) | genau eine | erfüllt |
| Technischer Fehler nach dem Minimum, Messung ungültig | `error` | 12 | 0 (Grund der Messung) | keine | nicht erfüllt |

- Der Stoppcode bleibt `error` – ein technischer Abbruch wird nie als reguläres Ende angezeigt.
- Die Gültigkeit entscheidet allein die finalisierte Messung (Engine-Validator → Snapshot); Note und Abschluss lesen
  nur den Snapshot. Genau eine Finalisierung, genau ein Abschluss-Ereignis; eine zweite Finalisierung ändert nichts.
- Beleg: `tests/scale_set_complete_replay_test.php::test_a_technical_error_ends_the_attempt_once` (echter Fehler: die
  Itemparameter verschwinden unter dem laufenden Versuch, die Engine endet mit `error`), beide Fälle vor/nach dem
  Minimum; der Fall „nach dem Minimum“ ergibt im Replay eine gültige Messung mit Note und Abschluss.
- **Fachlich freigegeben am 19.11.2026** (Projektverantwortlicher, Rückmeldung zum Re-Audit vom 19.11.2026: „Zählt, wie
  umgesetzt“): Eine gültige Messung nach dem Minimum ergibt trotz technischem Ende Note und Abschluss; der Stoppcode
  bleibt `error`. Im Browser belegt: local_catquiz `tests/playwright/tests/cat-attempt-path.spec.ts` („a technical
  error before/after the minimum“ – Dialog mit Fokus, axe ohne Verstoß, Note/Abschluss wie die Gültigkeit).

## 4. Nachweise

- `tests/stop_code_test.php`:
  - jede Zeile der Tabelle;
  - leerer, fehlender und unbekannter Status;
  - jeder Status von `local_catquiz\local\status` ist einem Host-Code zugeordnet.
  - neu (19.11.2026) `test_stored_status_numbers_of_every_version`: jede gespeicherte Statusnummer −1 bis 13,
    eine nie geschriebene (99, −7): ein reguläres Ende nur aus seinem eigenen Status; −1 (vor #64 so gespeicherte
    Fehler), 0 und unbekannte Nummern weder als reguläres Ende noch als leerer Pool – neutraler Hinweis; die drei
    Fehlerstatus immer `error` mit Fehlerhinweis.
- `tests/stop_matrix_test.php` (neu 19.11.2026): echte Versuche über Host, Adapter, Engine und Question Usage für
  RELSUBS, LOWESTSUB, FASTEST, ALLSUBS und CLASSIC, je reguläres Ende, nicht erreichbares Minimum und technischer
  Fehler (15 Fälle). Je Fall: Host-Code = Übersetzung des Engine-Status; Fehler bleibt `error`; `resultvalid` =
  statistische Gültigkeit der Globalskalen-Zeile (`global_scale_validity` – die der Note, nicht die des Berichts);
  Note und Abschluss genau bei gültigem Ergebnis; ein Abschluss-Ereignis; zweite Finalisierung ändert nichts. Ein
  Test, dessen Minimum Pool und Teilskalen-Grenzen nicht hergeben, wird vor dem Start mit Begründung abgewiesen – wie
  `attempt.php` beider Linien: keine Frage, kein CAT-Versuch, der leere Versuch bleibt stehen, keine Note, kein
  Abschluss (der `request_simulator` der v-3.0-Linie bildet das jetzt nach). Das Ende eines laufenden Versuchs vor dem
  Minimum belegen local_catquiz `end_without_question_test` und `stop_code_test`.
- Host `tests/local/attempt/stop_notice_test.php`: Hinweisart und Text je Code; Host-Behat
  `result_after_technical_error.feature` (neu 19.11.2026): Fehlerdialog auf der echten Ergebnisseite, gültige Messung
  mit Note und Abschluss, fehlende Schätzung ohne Note (nicht 0) und ohne Abschluss, reguläres Ende gleich bewertet.
- local_catquiz `tests/teststrategy/strategy_semantics_test.php` und `end_without_question_test.php` (#172): welcher
  Status wann entsteht.

## 5. Kompatibilität

- Ein Host vor 2026111701 (v-3.0) bzw. 2026111700 (Legacy) kennt `scalescomplete` und `noeligiblebeforeminimum`
  nicht und zeigt dafür den neutralen Hinweis.
- Der Adapter verlangt die neuen Host-Versionen.
- Historische Versuche (Status −1, Stoppcode leer vor Host #20) werden nicht umgeschrieben und nicht neu bewertet; sie
  werden nur gelesen (neutraler bzw. kein Hinweis).
