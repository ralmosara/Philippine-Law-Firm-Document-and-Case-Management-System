-- Settings that only the superuser can make, applied by the db-setup service
-- on every "docker compose up" (each statement is safe to repeat). On a
-- managed database, run this file once as the administrator.

-- Full-text search under row-level security.
--
-- The app connects as an ordinary role, so every query on a firm's table
-- carries the tenant policy. PostgreSQL then uses an index for a condition
-- only if its function is "leakproof" (cannot reveal anything about rows
-- the policy hides, e.g. through an error message). The full-text match
-- operator (tsvector @@ tsquery) is not marked so by default, so file and
-- knowledge-bank searches scanned every row of the table instead of using
-- their indexes: 650 ms for 20,000 files, growing with every upload.
--
-- ts_match_vq only compares a stored tsvector with the search query and
-- returns true or false; it raises no error that depends on a row's
-- content. Marking it leakproof lets the index serve the search (under a
-- millisecond for a specific term) while the tenant policy still filters
-- every row returned.
ALTER FUNCTION ts_match_vq(tsvector, tsquery) LEAKPROOF;
