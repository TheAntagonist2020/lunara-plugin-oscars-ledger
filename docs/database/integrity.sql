-- Integrity checks run against a freshly loaded ledger. Every row returned by
-- a "should be empty" query is a failure.

-- Expected counts match the Academy Awards Database (checked 2026-09-24): it lists
-- 12,139 records and 3,517 winning records, and by the Academy's own note the two
-- Sunrise cinematography records (Rosher, Struss) are one nomination.
SELECT 'nominations' AS check_name, COUNT(*) AS value, 12138 AS expected FROM ledger_nominations
UNION ALL SELECT 'winners', SUM(is_winner), 3516 FROM ledger_nominations
UNION ALL SELECT 'ceremonies', COUNT(*), 98 FROM ledger_ceremonies
UNION ALL SELECT 'categories', COUNT(*), 66 FROM ledger_categories
UNION ALL SELECT 'unofficial nominations', SUM(is_official = 0), 90 FROM ledger_nominations;

-- should be empty: ceremony x category groups with no winner
SELECT n.ceremony_no, c.canonical_name
FROM ledger_nominations n JOIN ledger_categories c ON c.category_id = n.category_id
GROUP BY n.ceremony_no, c.canonical_name HAVING SUM(n.is_winner) = 0;

-- the five documented ties / multi-award groups in single-winner categories
SELECT n.ceremony_no, n.category_as_given, COUNT(*) AS winners
FROM ledger_nominations n JOIN ledger_categories c ON c.category_id = n.category_id
WHERE n.is_winner = 1 AND c.canonical_name IN ('ACTOR IN A LEADING ROLE','ACTRESS IN A LEADING ROLE','ACTOR IN A SUPPORTING ROLE',
  'ACTRESS IN A SUPPORTING ROLE','BEST PICTURE','DIRECTING','DOCUMENTARY (Feature)','SOUND EDITING','CINEMATOGRAPHY','FILM EDITING')
GROUP BY n.ceremony_no, n.category_as_given HAVING COUNT(*) > 1 ORDER BY n.ceremony_no;

-- should be empty: an acting nomination without exactly one credited person
SELECT n.nomination_id
FROM ledger_nominations n JOIN ledger_categories c ON c.category_id = n.category_id
LEFT JOIN ledger_nomination_credits nc ON nc.nomination_id = n.nomination_id
WHERE c.class_code = 'Acting'
GROUP BY n.nomination_id HAVING COUNT(nc.ordinal) <> 1;

-- should be empty: a credit identity that is a company on an acting nomination
SELECT ci.nomination_id, ci.imdb_id
FROM ledger_credit_identities ci
JOIN ledger_nominations n ON n.nomination_id = ci.nomination_id
JOIN ledger_categories c ON c.category_id = n.category_id
WHERE c.class_code = 'Acting' AND ci.imdb_id NOT LIKE 'nm%';

-- should be empty: an entity or title nothing refers to
SELECT e.imdb_id FROM ledger_entities e
LEFT JOIN ledger_credit_identities ci ON ci.imdb_id = e.imdb_id WHERE ci.imdb_id IS NULL;
SELECT t.imdb_id FROM ledger_titles t
LEFT JOIN ledger_nomination_titles nt ON nt.imdb_id = t.imdb_id WHERE nt.imdb_id IS NULL;

-- should be empty: two slots of one nomination linked to the same entity
SELECT nomination_id, imdb_id, COUNT(*) FROM ledger_credit_identities
GROUP BY nomination_id, imdb_id HAVING COUNT(*) > 1;

-- the pseudonym model: Roderick Jaynes resolves to both Coens
SELECT nc.nomination_id, nc.name_as_credited, GROUP_CONCAT(e.name ORDER BY e.name) AS identities
FROM ledger_nomination_credits nc
JOIN ledger_credit_identities ci ON ci.nomination_id = nc.nomination_id AND ci.ordinal = nc.ordinal
JOIN ledger_entities e ON e.imdb_id = ci.imdb_id
WHERE nc.name_as_credited = 'Roderick Jaynes' GROUP BY nc.nomination_id, nc.name_as_credited;

-- every correction in the ledger
SELECT reason, COUNT(*) FROM ledger_corrections GROUP BY reason;
