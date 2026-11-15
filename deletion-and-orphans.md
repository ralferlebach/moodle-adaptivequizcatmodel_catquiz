# Löschen von CATquiz-Daten und Inventur verwaister Zeilen (Issue #11)

Stand: 15.11.2026. Gilt für die Zweige `ALiSe-v-1.2.0-legacy` (ab 2026111502) und `v-3.0` (ab 2026111503) mit
mod_adaptivequiz ab 2026111500 (legacy) bzw. 2026111501 (v-3.0).

## 1. Identitätsvertrag

Zwei Nummernräume treffen sich (local_catquiz #95):

| Nummer | Tabelle.Spalte | verweist auf |
|---|---|---|
| Versuch der Aktivität | `adaptivequiz_attempt.id` | – |
| | `local_catquiz_attempts.attemptid`, `local_catquiz_trace.adaptiveattemptid` | Versuch der Aktivität |
| CAT-Versuch | `local_catquiz_attempts.id` | – |
| | `local_catquiz_progress.attemptid`, `local_catquiz_personparams.attemptid` | CAT-Versuch (seit local_catquiz #101) |

`attempt_cleanup` bestimmt die CAT-Versuche über die Versuche der Aktivität und die Komponente (beide Schreibweisen,
`mod_adaptivequiz` und `adaptivequiz`). Fortschritt und Fähigkeiten löscht er nur über die CAT-Versuch-ID, nie über
eine bloß gleiche Zahl. Der Callback in `main` (2026082704) löschte den Fortschritt über die Nummer des Versuchs
der Aktivität. Bei gekreuzten Nummern traf er den Fortschritt einer anderen Person, und der eigene blieb liegen.

## 2. Löschpfade

| Pfad | Einstieg | Transaktion |
|---|---|---|
| Aktivität löschen | `course_delete_module()` → `adaptivequiz_delete_instance()` → `delete_instance_callback()` | Host (ganze Löschung) und Adapter |
| Kurs löschen | `delete_course()` → je Aktivität wie oben | je Aktivität |
| Einen Versuch löschen | `delattempt.php` → `adaptivequiz_delete_attempt()` → `post_delete_attempt_callback()` | Host und Adapter |
| Kurs zurücksetzen | `adaptivequiz_reset_userdata()` → je Versuch `post_delete_attempt_callback()` | je Aktivität |
| Datenschutz-API | Host-Provider → `delete_catmodel_data_*()` → `attempt_cleanup` | Adapter |
| CatQuizLab Deep Purge, Run-Reset | über `course_delete_module()` (Zuständigkeit: CatQuizLab, `engine_footprint`) | wie Aktivität löschen |

Jeder Pfad ist eine Transaktion: Er löscht alles, was er gefunden hat, oder nichts. Schlägt ein Schritt fehl,
rollt die Transaktion zurück, und es entsteht eine `cleanup_failed_exception`. Ihre Meldung nennt, was gelöscht
werden sollte, und die Ursache: „Nothing was deleted: the data is as before and the deletion can run again.“

Der Weg zurück ist derselbe Aufruf noch einmal. Ein zweiter Lauf über bereits Gelöschtes ändert nichts.

## 3. Was bleibt, mit Absicht

- **Fortschritt aus der Zeit vor local_catquiz #101.** Damals wurde er über die Nummer des Versuchs der Aktivität
  geführt. Eine Löschung fasst diese Zeilen nicht an: Ihre Nummer ist von einer CAT-Versuch-ID nicht zu
  unterscheiden. Sie gehören in die Inventur (§4), nicht in eine Löschung.
- **Fähigkeiten ohne Versuch** (`local_catquiz_personparams.attemptid IS NULL`), etwa aus einer Rekalibrierung.
  Sie gehören zur Person, nicht zu einem Versuch.
- **Daten anderer Aktivitäten, Kurse und CAT-Kontexte**, auch derselben Person.
- **Bewertungen anderer Aktivitäten.** Die Bewertung der gelöschten Aktivität entfernt Moodle mit ihrem Bewertungsaspekt.

Keine Löschung bereinigt Altbestände. Für die 1.525 verwaisten Fortschrittszeilen auf CLARA gibt es keine
automatische Löschung: Sie bleiben, bis der Betreiber nach der Inventur entscheidet.

## 4. Inventur (nur lesend)

```
php mod/adaptivequiz/catmodel/catquiz/cli/orphan_inventory.php          # Tabelle
php mod/adaptivequiz/catmodel/catquiz/cli/orphan_inventory.php --json   # für Ablage und Vergleich
```

Ab Moodle 5.1 liegt das Skript unter `public/mod/...`. Es schreibt nichts: Ein Test prüft, dass während der
Inventur kein Schreibzugriff stattfindet. Die Ausgabe enthält nur Zahlen, keine IDs und keine Personen.

| Klasse | Tabelle | Bedeutung |
|---|---|---|
| `progress_orphan_hostid` | progress | Kein CAT-Versuch dieser Nummer, aber ein Versuch der Aktivität derselben Person: Fortschritt aus der Zeit vor #101 |
| `progress_orphan_none` | progress | Weder CAT-Versuch noch Versuch der Person: Der Versuch wurde gelöscht, u. a. durch den alten Callback |
| `progress_foreign` | progress | Nennt den CAT-Versuch einer anderen Person: Die Nummernräume haben sich getroffen |
| `cat_attempt_no_host_instance` | attempts | Versuch der Aktivität weg, Aktivität noch da |
| `cat_attempt_no_host_noinstance` | attempts | Versuch und Aktivität weg |
| `cat_attempt_no_hostid` | attempts | CAT-Versuch der Aktivität ohne Nummer des Aktivitätsversuchs |
| `personparams_orphan` | personparams | Fähigkeit nennt einen CAT-Versuch, den es nicht gibt |
| `trace_orphan` | trace | Trace eines gelöschten Versuchs; der Aufbewahrungs-Task von local_catquiz entfernt ihn |

Jede Klasse ist nach Alter aufgeteilt: bis 30, bis 180, bis 365 Tage, älter. Den Kopf der Ausgabe bilden die
Aufbewahrungseinstellungen von local_catquiz (`progressretention`, `attempttraceretentiondays`). Daran lässt sich
die Klasse gegen die geltende Aufbewahrung lesen. Unter `minimal` hat eine verwaiste Fortschrittszeile keinen
Aufbewahrungsgrund mehr.

### Vorgehen für den Betreiber (z. B. CLARA)

1. Inventur vor dem Update (`--json`) ablegen.
2. Adapter- und Host-Update einspielen (Reihenfolge s. §6).
3. Einen echten Testlöschlauf machen: Testkurs mit zwei Aktivitäten und laufenden Versuchen, eine Aktivität löschen.
   Danach die Inventur erneut ausführen: Die Zahlen dürfen nicht steigen. Die zweite Aktivität muss fortsetzbar
   bleiben.
4. Altbestände bleiben unverändert. Ob und wie sie bereinigt werden, entscheidet der Betreiber getrennt. Eine
   Bereinigung braucht einen eigenen Auftrag mit Freigabe und gehört nicht zu diesem Patch.

## 5. Nachweise

- `tests/local/attempt_deletion_chain_test.php` (35 Tests):
  - Echte Versuche über Host, Adapter und CATquiz, A und B laufend mit gekreuzten Nummern, C fertig mit Bewertung
    in einem anderen Kurs.
  - Pfade: Aktivität, Kurs, Versuch, Reset; dazu Fehler in jedem Schritt mit Wiederholung, und die Bereinigung
    allein ohne Transaktion des Hosts.
  - Je Pfad belegt:
    - genau A's Zeilen weg, Zeile für Zeile;
    - B und C unverändert;
    - keine neue Waise;
    - B beantwortet danach seine nächste Frage im selben CAT-Versuch.
- `tests/local/orphan_inventory_test.php`: jede Klasse und Altersstufe; kein Schreibzugriff.
- Zahntests:
  - Ohne die Transaktion des Adapters werden 8 Tests rot.
  - Ohne die Transaktionen des Hosts werden 10 Tests rot.
  - Mit dem Callback aus `main` werden 4 Tests rot. Dabei verliert B im Fall „ältere Schreibweise, Aktivität
    eingetragen“ seinen Fortschritt, obwohl die Zeilenzahlen stimmen. Deshalb vergleichen die Tests Zeilen und
    nicht nur Zahlen.
- Host v-3.0, `tests/local/catmodel/deletion_transaction_test.php`: Ein fehlschlagendes Test-Catmodel lässt
  Versuch, Question Usage und Aktivität unverändert.
- CatQuizLab (`engine_footprint_test`, `run_lifecycle_test`, `audit_test`, `experiment_stop_test`) mit diesem
  Adapter und Host: grün.

## 6. Einspielen und Zuständigkeiten

- Reihenfolge:
  1. mod_adaptivequiz (legacy 2026111500, v-3.0 2026111501), denn der Adapter verlangt diese Version.
  2. Danach der Adapter.
- Querverweise:
  - local_catquiz #95/#96 (Eigentum am Fortschritt) und #101 (Fortschritt über die CAT-Versuch-ID).
  - CatQuizLab: Deep Purge und Run-Reset prüfen ihre eigene Spur selbst (`engine_footprint`); der Adapter ändert
    dort nichts, es gibt keinen doppelten Fix.
- Offen:
  - Ein Browser-Test, der die Aktivität über die Oberfläche löscht.
  - Die CI-Matrix auf GitHub (DoD4).
  - Der reale Testlöschlauf auf CLARA mit Betreiberfreigabe (DoD5).
