-- In default selections mode, Belligerence's optional "put your opponents' low moods into the discard pile" now starts checked (PHP + web UI only; no schema change).

UPDATE schema_version SET version = '1.64.39' WHERE id = 1;
