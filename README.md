# adaptivequizcatmodel_catquiz

The CAT model subplugin of [mod_adaptivequiz](https://github.com/ralferlebach/moodle-mod_adaptivequiz) that connects
the activity to [local_catquiz](https://github.com/ralferlebach/moodle-local_catquiz): the activity asks the CAT model
for the next question, hands it the answers and the end of an attempt, and takes the result and the grade from it.

## Versions (local_catquiz issue #31)

| Branch | Moodle | mod_adaptivequiz | local_catquiz |
|---|---|---|---|
| `ALiSe-v-1.2.0-legacy` | 4.5 (refused on 5.0 and later) | branch `ALiSe-v-1.2.0-legacy` | line 1.2 |
| `v-3.0` | 5.1 to 5.3 (`requires` 5.1) | branch `v-3.0` | line 1.3 |

This is branch `v-3.0` (Moodle 5.1-5.3, local_catquiz 1.3).

## Installation

Install into `mod/adaptivequiz/catmodel/catquiz` (Moodle 5.1 and later: `public/mod/adaptivequiz/catmodel/catquiz`),
together with mod_adaptivequiz and local_catquiz of the same line. Choose "catquiz" as the CAT model in the settings
of an adaptive quiz.

## Documentation

* `CHANGELOG.md` - changes per internal version.
* `legacy-recovery.md` - how results of older attempts are recovered.
* `doc/` - further notes.

## License

GNU GPL v3 or later.
