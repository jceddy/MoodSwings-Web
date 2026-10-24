-- Web UI only: the Discord notifications checkbox now sits on its own
-- line under the Connect/Disconnect Discord button.
UPDATE schema_version SET version = '1.62.26' WHERE id = 1;
