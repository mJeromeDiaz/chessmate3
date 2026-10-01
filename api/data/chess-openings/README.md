# lichess-org/chess-openings

`a.tsv` … `e.tsv` (columns `eco`, `name`, `pgn`), copied from
<https://github.com/lichess-org/chess-openings> at commit
`c67912be581f0793dbaa776be5ccf111e01f88d9` (2026-09-29).

License: CC0 1.0 Universal (public domain dedication), as stated by the repository: "As a collection
of facts, this data set is in the public domain."

Loaded by `bin/console app:repertoire:sync-openings` into `repertoire_opening`; the `uci` and `epd`
columns of the upstream build are computed by `App\Chess\Rules` instead (same normalization). To
update: download the five files again, update the commit above, rerun the command.
