# Recovery älterer CATquiz-Ergebnisse (local_catquiz #137, Adapter #8)

Stand 15.10.2026, Adapter 2026100906, local_catquiz 2026100922 (1.2.1) / 2026100923 (1.3.0).

## Wozu

Versuche, die vor dem Einfrieren des Ergebnisbereichs beendet wurden, haben keine Note. Ihr Snapshot
trägt einen der Gründe `range_not_recorded`, `no_primary_scale` / `no_stored_result` oder
`not_finalised`. Die Recovery beurteilt solche Versuche innerhalb eines Zeitfensters nach heutigen Regeln
neu und führt die Note über die normale Gradebook-Kette nach.

## Einmalige Migration beim Upgrade (Adapter #8)

Der normale Regrade der Aktivität (`regrade_activity`, ausgelöst etwa beim Speichern der Einstellungen)
nimmt einen vorhandenen Snapshot bewusst nicht neu. Ein älterer Versuch mit ungültigem Snapshot bliebe
deshalb ohne Note, obwohl der Task erfolgreich läuft. Damit niemand das CLI kennen muss:

- Das Upgrade auf **2026100906** stellt einmal den Ad-hoc-Task `task\recover_legacy_results` ein. Das
  Upgrade selbst bewertet nichts.
- Der Task ruft dieselbe Klasse `legacy_result_recovery` mit `--execute`-Verhalten auf. Er nutzt dieselbe
  Triage und dasselbe Protokoll wie das CLI.
- Zeitfenster: `legacy_result_recovery::MIGRATION_SINCE` = **14.07.2026**, 00:00 Uhr in der Zeitzone des
  Servers. Der Stichtag ist fest, kein gleitendes „drei Monate zurück“. Er wird mit dem Task gespeichert
  und steht je Versuch im Protokoll (`cutoff`).
- Ein wiederholter Lauf, etwa ein Retry, überspringt protokollierte Versuche und ändert nichts.
- Ein Fehler bei einem Versuch oder einer Aktivität stoppt die übrigen nicht. Der Fehler steht im
  Task-Log (`failed: attempt …`). Von der fehlgeschlagenen Bewertung bleibt nichts: Snapshot, Note und
  Protokoll einer Aktivität werden gemeinsam geschrieben oder gar nicht. Ein späterer Lauf versucht den
  Versuch erneut.
- Der Task braucht ein local_catquiz, das Versuche über dem Maximum ablehnt (siehe unten). Mit einem
  älteren local_catquiz wartet er, Moodle wiederholt ihn, statt solche Versuche zu bewerten. Daher
  local_catquiz zuerst aktualisieren.

Das CLI bleibt für Probelauf, Diagnose und Läufe von Hand.

## Bewertungsgrundlage

Seit 2026100903 bewertet der Adapter die **Globalskala** des Versuchs. Das ist die Testskala, auf der
CATquiz den Versuch finalisiert hat. Seit 2026100904 (mit local_catquiz 2026100918) ist sie gültig, wenn
sie gemessen ist und jedes Kriterium erfüllt, das der Test setzt:

- Mindestfragen pro Skala, angewandt auf die Globalskala
- Mindestfragen des Tests
- maximaler Standardfehler
- die allgemeine Fraction-Regel: nicht alle Antworten richtig, nicht alle falsch. Diese Regel soll neu
  verhandelt werden.

Was die Strategie über die Skala sagt, spielt keine Rolle („nicht primär“, „Bericht abgeschaltet“,
„verborgen“). Das gilt auch für die Primärmarkierung der gespeicherten Zeilen (`isprimary`) und für einen
alten Snapshot-Grund `no_primary_scale`. Der minimale Standardfehler zählt nicht. Das Erreichen der
Maximalzahl an Fragen ist ein regulärer Abschluss.

**Ungültig** ist dagegen ein Versuch mit **mehr gestellten Fragen als dem Maximum** (`max_questions_exceeded`,
Produktionsfall 33 Slots bei Maximum 25). Gezählt werden die Fragen-Slots der Nutzung, verglichen mit dem
CAT-Maximum des Versuchs, sonst mit `maximumquestions` der Aktivität. Die zentrale Validierung von
local_catquiz stellt das fest. Der Adapter übernimmt den Grund aus der gespeicherten Zeile.

## Ablauf je Versuch

Kandidaten sind beendete Versuche von CATquiz-Aktivitäten ohne gültigen Snapshot.

| Prüfung | Ergebnis, wenn nicht erfüllt |
|---|---|
| noch nicht rekonstruiert (Protokolltabelle) | `already_recovered`, unverändert |
| CAT-Versuch vorhanden | `no_cat_attempt`, unverändert |
| `starttime` ≥ Stichtag (leer zählt als davor) | `outside_window`, unverändert |
| von CATquiz beendet (`endtime`) | `not_finalised`, unverändert |
| Antworten (Progress) gespeichert | `not_reconstructable`, unverändert |

Danach:

1. Der Versuch wird mit dem Validator jeder Finalisierung (`attempt_revalidator`) aus seinen Antworten
   neu beurteilt: Mindestfragenzahl, Standardfehler, N je Skala, Fraction.
2. Fehlt einer Skala der eingefrorene Bereich, gilt der heutige Bereich der Skala, gekennzeichnet mit
   `local_catquiz_personparams.rangebackfilled = 1`.
3. Die Aktivität nimmt den Snapshot neu und baut die Noten auf (`result_service::regrade`).
4. Der Versuch wird in `adaptivequiz_cat_recovery` protokolliert: alter Grund, Schritte, Globalskala,
   Bereich und Herkunft (`frozen` / `current_scale`), Urteil, neuer Grund, Prozent, Code-Version, Zeit.

Das Ergebnis ist `recovered_valid` (mit Note) oder `recovered_invalid` (ohne Note, mit Grund). Bricht ein
Versuch oder die Bewertung seiner Aktivität mit einem Fehler ab, ist er `failed`. Er wird nicht
protokolliert, und ein späterer Lauf versucht ihn erneut. Dazu gehört auch ein Versuch, den die
Neubeurteilung schon gültig gesetzt hat, dessen Snapshot aber noch keinen Prozentwert hat.

## Bedienung

Zuerst ein Probelauf. Er schreibt nichts:

    sudo -u www-data php mod/adaptivequiz/catmodel/catquiz/cli/recover_legacy_results.php \
        --since=2026-07-14 --csv=/tmp/recovery-probe.csv

Die CSV-Datei enthält eine Zeile je Kandidat. Prüfen Sie die Verteilung, danach schreiben Sie:

    sudo -u www-data php mod/adaptivequiz/catmodel/catquiz/cli/recover_legacy_results.php \
        --since=2026-07-14 --execute --csv=/tmp/recovery-run.csv

Mit `--attemptid=N` läuft die Recovery nur für einen Versuch. Ein zweiter Lauf überspringt protokollierte
Versuche und ändert nichts.

`--since` ist 00:00 Uhr in der Zeitzone des Servers.

## Tests

`tests/local/recovery/legacy_result_recovery_test.php` (18 Fälle) prüft:

- Upgrade → Ad-hoc-Task → Recovery → Snapshot → Gradebook mit gemischtem Bestand (gültig, 4/12, 33/25,
  nie beendet, vor dem Stichtag); dieselbe Einteilung wie im Probelauf des CLI; nur einmal eingestellt;
  zweiter Lauf ohne Änderung
- der normale Regrade lässt den ungültigen Snapshot unverändert
- alter Grund `no_primary_scale` und vertauschte `isprimary`-Markierung: Note der Globalskala
- 33 Slots bei Maximum 25: ohne Note; 25 bei 25: mit Note
- Fehler in einer Aktivität: die andere wird bewertet, nichts Halbes bleibt, späterer Lauf holt nach

- fehlender Bereich: Probelauf, Lauf und Wiederholung
- kein gespeichertes Ergebnis
- Bewertung auf der Globalskala bei berichteter Subskala
- 4 von mindestens 12 Fragen: ohne Note
- Fraction 1: ohne Note
- Stichtag: genau am Stichtag, eine Sekunde davor, ohne `starttime`
- nie beendet, keine Antworten, kein CAT-Versuch
- nach dem Snapshot beendet
- gültige Versuche bleiben unberührt
- Einzelversuch und CSV

`tests/result_provider_test.php` prüft die Bewertung auf der Globalskala.
