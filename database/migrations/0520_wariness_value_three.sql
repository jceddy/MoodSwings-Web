-- Community set 'Emotional Baggage' (XEMB): Wariness is now worth 3 (was 4). The card art for Wariness and Admiration was also refreshed.

UPDATE cards SET base_value = 3 WHERE id = 257 AND name = 'Wariness';

UPDATE schema_version SET version = '1.64.24' WHERE id = 1;
