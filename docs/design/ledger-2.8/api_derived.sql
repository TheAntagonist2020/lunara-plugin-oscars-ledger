-- Prototype of the derived read tables the read API needs (built by the loader
-- inside the same staged swap as the base ledger tables).

DROP TABLE IF EXISTS ledger_group_stats, ledger_entity_stats, ledger_search, ledger_nomination_text;

CREATE TABLE ledger_group_stats (
  ceremony_no   SMALLINT UNSIGNED NOT NULL,
  category_id   SMALLINT UNSIGNED NOT NULL,
  nominations   SMALLINT UNSIGNED NOT NULL,
  winners       SMALLINT UNSIGNED NOT NULL,
  official      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (ceremony_no, category_id),
  KEY k_gs_category (category_id, ceremony_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO ledger_group_stats
SELECT ceremony_no, category_id, COUNT(*), SUM(is_winner), SUM(is_official)
FROM ledger_nominations GROUP BY ceremony_no, category_id;

CREATE TABLE ledger_entity_stats (
  imdb_id         VARCHAR(10)  NOT NULL,
  kind            ENUM('title','person','company') NOT NULL,
  name            VARCHAR(255) NOT NULL,
  nominations     SMALLINT UNSIGNED NOT NULL,
  wins            SMALLINT UNSIGNED NOT NULL,
  official_nominations SMALLINT UNSIGNED NOT NULL,
  first_ceremony  SMALLINT UNSIGNED NOT NULL,
  last_ceremony   SMALLINT UNSIGNED NOT NULL,
  categories      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (imdb_id),
  KEY k_es_kind_wins (kind, wins, nominations),
  KEY k_es_kind_noms (kind, nominations, wins)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO ledger_entity_stats
SELECT t.imdb_id, 'title', t.title, COUNT(*), SUM(n.is_winner), SUM(n.is_official),
       MIN(n.ceremony_no), MAX(n.ceremony_no), COUNT(DISTINCT n.category_id)
FROM ledger_titles t
JOIN (SELECT DISTINCT nomination_id, imdb_id FROM ledger_nomination_titles WHERE imdb_id IS NOT NULL) nt ON nt.imdb_id = t.imdb_id
JOIN ledger_nominations n ON n.nomination_id = nt.nomination_id
GROUP BY t.imdb_id, t.title;

INSERT INTO ledger_entity_stats
SELECT e.imdb_id, e.kind, e.name, COUNT(*), SUM(n.is_winner), SUM(n.is_official),
       MIN(n.ceremony_no), MAX(n.ceremony_no), COUNT(DISTINCT n.category_id)
FROM ledger_entities e
JOIN (SELECT DISTINCT nomination_id, imdb_id FROM ledger_credit_identities) ci ON ci.imdb_id = e.imdb_id
JOIN ledger_nominations n ON n.nomination_id = ci.nomination_id
GROUP BY e.imdb_id, e.kind, e.name;

-- One row per (IMDb ID, distinct label): the canonical name plus every
-- as-credited variant, so "Roderick Jaynes" finds both Coens and "Bono"
-- finds Paul Hewson's ID.
CREATE TABLE ledger_search (
  imdb_id      VARCHAR(10)  NOT NULL,
  kind         ENUM('title','person','company') NOT NULL,
  label        VARCHAR(255) NOT NULL,
  folded       VARCHAR(255) NOT NULL,
  is_canonical TINYINT(1)   NOT NULL,
  nominations  SMALLINT UNSIGNED NOT NULL,
  wins         SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (imdb_id, folded(191)),
  KEY k_search_folded (folded(64), nominations)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT IGNORE INTO ledger_search
SELECT s.imdb_id, s.kind, s.name, LOWER(s.name), 1, s.nominations, s.wins FROM ledger_entity_stats s;
INSERT IGNORE INTO ledger_search
SELECT DISTINCT nt.imdb_id, 'title', nt.title_as_credited, LOWER(nt.title_as_credited), 0, s.nominations, s.wins
FROM ledger_nomination_titles nt JOIN ledger_entity_stats s ON s.imdb_id = nt.imdb_id WHERE nt.imdb_id IS NOT NULL;
INSERT IGNORE INTO ledger_search
SELECT DISTINCT ci.imdb_id, s.kind, nc.name_as_credited, LOWER(nc.name_as_credited), 0, s.nominations, s.wins
FROM ledger_credit_identities ci
JOIN ledger_nomination_credits nc ON nc.nomination_id = ci.nomination_id AND nc.ordinal = ci.ordinal
JOIN ledger_entity_stats s ON s.imdb_id = ci.imdb_id;

-- One folded haystack per nomination: credit line, credited names, titles,
-- detail and the historical category name. Reaches the rows with no IMDb ID.
CREATE TABLE ledger_nomination_text (
  nomination_id INT UNSIGNED NOT NULL,
  haystack      TEXT NOT NULL,
  PRIMARY KEY (nomination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

INSERT INTO ledger_nomination_text
SELECT n.nomination_id, LOWER(CONCAT_WS(' | ', n.credit_line, n.detail, n.category_as_given,
  (SELECT GROUP_CONCAT(nc.name_as_credited SEPARATOR ' | ') FROM ledger_nomination_credits nc WHERE nc.nomination_id = n.nomination_id),
  (SELECT GROUP_CONCAT(nt.title_as_credited SEPARATOR ' | ') FROM ledger_nomination_titles nt WHERE nt.nomination_id = n.nomination_id),
  n.citation))
FROM ledger_nominations n;
