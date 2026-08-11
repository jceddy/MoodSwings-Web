-- No schema change: extends the existing "'Are you sure?' on a
-- targetless play" confirmation dialog (see web-static/README.md) to
-- cover Wrath specifically -- leaving its own single bare-bool
-- checkbox ("Put every other mood in play into the discard pile")
-- unchecked means the play does nothing whatsoever beyond entering
-- play (WrathEffect returns immediately), the same unambiguous "missed
-- click" case the existing mechanism already guards against for other
-- field types. Client-side only (web-static/js/game.js's new
-- cardIsWrathWithoutItsBoxChecked()).
UPDATE schema_version SET version = '1.15.0' WHERE id = 1;
