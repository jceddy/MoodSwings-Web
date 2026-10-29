-- Fix: a play repeated by multiple Duplicity sources no longer counts each pause as a repeated board state.
UPDATE schema_version SET version = '1.63.9' WHERE id = 1;
