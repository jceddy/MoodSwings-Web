-- Web client fix: the Resign button was disabled whenever ANY decision was pending, including one waiting on the
-- viewer themselves, which the server has allowed since resignGame() learned to answer it with a legal default
-- before resigning. It is now disabled only while the pending decision is waiting on someone else. No schema change.

UPDATE schema_version SET version = '1.62.6' WHERE id = 1;
