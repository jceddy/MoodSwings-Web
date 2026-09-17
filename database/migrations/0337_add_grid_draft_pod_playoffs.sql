-- Grid Draft's third tournament option (issue #91 follow-up): "Pods with
-- playoffs" (match_params.deck_type 'grid_draft_pod_playoff'). Like "Pod
-- draft (once)" (grid_draft_pod, migration 0336), participants split
-- into pods of <=4 that each draft together once -- but instead of
-- feeding one shared bracket that mixes every pod's own drafted players
-- freely, each pod instead plays its OWN single-elimination bracket to
-- completion using only its own drafted pools, and the WINNER of every
-- pod then drafts again, together, in one final pod (always <=4 players,
-- since a tournament is capped at 16 participants / pods of <=4 = at
-- most 4 pods = at most 4 pod winners), whose own single-elimination
-- bracket decides the tournament champion. A tournament with only one
-- pod at all (4 or fewer participants) skips the second draft entirely
-- -- that pod's own winner IS the champion, nothing left to decide.
--
-- Every pod/final bracket here is always single elimination, regardless
-- of whatever bracket_type the tournament itself was created with (never
-- consulted for this deck_type at all) -- pods only ever have 2-4
-- players, where double elimination's own losers-bracket machinery and
-- Swiss's own standings-based pairing add complexity for no real benefit.
--
-- tournament_rounds.pod_id ties a round to whichever pod's own bracket
-- it belongs to (a REGULAR pod's own bracket, or the FINAL pod's own
-- bracket that decides the champion) -- NULL for every other
-- tournament's own single shared bracket, including grid_draft_pod's own
-- (which has no bracket of its own at all -- see that migration's own
-- docblock; every match instead plays through the tournament's ordinary
-- top-level bracket/Swiss). The unique key gains pod_id so that e.g.
-- pod 1's own "single" round 1 and pod 2's own "single" round 1 can
-- coexist without colliding.
ALTER TABLE tournament_rounds
    ADD COLUMN pod_id BIGINT UNSIGNED DEFAULT NULL AFTER tournament_id,
    ADD CONSTRAINT fk_tournament_rounds_pod FOREIGN KEY (pod_id) REFERENCES tournament_pods (id) ON DELETE CASCADE,
    DROP KEY uq_tournament_rounds_number,
    ADD UNIQUE KEY uq_tournament_rounds_number (tournament_id, pod_id, bracket, round_number);

-- 'playing' sits between 'drafting' and 'completed' for a
-- grid_draft_pod_playoff pod only (drafting -> that pod's own bracket is
-- materialized and being played -> completed once its own bracket
-- resolves, winner_participant_id recorded). A grid_draft_pod/
-- booster_draft pod never passes through 'playing' at all -- drafting
-- finishes straight into 'completed' as before, no bracket of its own to
-- play.
--
-- kind distinguishes a REGULAR pod (one of the initial pods formed at
-- tournament start) from the single FINAL pod (formed once every
-- regular pod's own bracket has produced a winner, seating those
-- winners for the deciding draft+bracket) -- always 'regular' for
-- booster_draft/grid_draft_pod, which have no such two-stage structure.
--
-- winner_participant_id is this pod's own bracket champion, set the
-- moment that bracket resolves (TournamentService::onPodBracketFinished())
-- -- null until then, and always null for booster_draft/grid_draft_pod
-- pods (which have no bracket of their own to produce one).
ALTER TABLE tournament_pods
    MODIFY COLUMN status ENUM('drafting', 'playing', 'completed') NOT NULL DEFAULT 'drafting',
    ADD COLUMN kind ENUM('regular', 'final') NOT NULL DEFAULT 'regular' AFTER pod_number,
    ADD COLUMN winner_participant_id BIGINT UNSIGNED DEFAULT NULL AFTER completed_at,
    ADD CONSTRAINT fk_tournament_pods_winner FOREIGN KEY (winner_participant_id) REFERENCES tournament_participants (id) ON DELETE SET NULL;

-- "Pods with playoffs" is the one place a single participant legitimately
-- seats in TWO pods over the course of a tournament: once in whichever
-- regular pod they were dealt into, and again in the single FINAL pod if
-- they go on to win that regular pod's own bracket. Booster Draft/"Pod
-- draft (once)" both only ever seat a participant once (a single pod
-- stage, full stop), so migration 0335's own uq_tournament_pod_participants_participant
-- (participant_id alone) never had to account for this -- loosened here
-- to (pod_id, participant_id), which still catches the bug that key
-- actually existed to prevent (the same participant seated twice in the
-- very same pod) without also forbidding the second, legitimate case.
-- fk_tournament_pod_participants_participant is itself backed by
-- participant_id being the FIRST column of some index, which the old
-- unique key was and the new one (pod_id first) no longer is -- a plain
-- non-unique index takes over backing that FK.
ALTER TABLE tournament_pod_participants
    ADD KEY idx_tournament_pod_participants_participant (participant_id),
    ADD UNIQUE KEY uq_tournament_pod_participants_pod_participant (pod_id, participant_id),
    DROP KEY uq_tournament_pod_participants_participant;

UPDATE schema_version SET version = '1.50.0' WHERE id = 1;
