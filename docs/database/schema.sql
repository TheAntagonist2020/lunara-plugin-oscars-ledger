-- ============================================================================
-- Lunara Oscars Ledger: relational schema, version 1
-- ============================================================================
-- Every nomination the Academy has recorded, from the 1st ceremony (1927/28)
-- to the 98th (2025), keyed on IMDb IDs:
--   tt#######   a title (film, short, documentary)
--   nm#######   a person
--   co#######   a company (studio, sound department, laboratory)
--
-- Two kinds of name live side by side and must never be confused:
--   * the CANONICAL name of an entity (ledger_entities.name, ledger_titles.title)
--   * the name AS CREDITED on that nomination (…_as_credited), which is the
--     Academy's historical record: pseudonyms ("Roderick Jaynes"), birth names
--     of stage names ("Paul Hewson" = Bono), US release titles ("The Invaders" =
--     "49th Parallel"), and the Academy's own spellings.
--
-- A credit slot keeps its position (ordinal) even when no IMDb ID is known,
-- so a name can never slide onto its neighbour's ID. That positional drift
-- is the fault this schema exists to rule out.
--
-- Target: MySQL 8.0+ / MariaDB 10.6+, InnoDB, utf8mb4.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE ledger_dataset (
  dataset_version   VARCHAR(40)  NOT NULL,          -- e.g. '2026.09.24-1'
  source_name       VARCHAR(120) NOT NULL,          -- 'Academy Awards Database via DLu/oscar_data'
  source_sha256     CHAR(64)     NOT NULL,          -- hash of the source file before corrections
  corrected_sha256  CHAR(64)     NOT NULL,          -- hash of the corrected file this load came from
  nomination_count  INT UNSIGNED NOT NULL,
  winner_count      INT UNSIGNED NOT NULL,
  loaded_at         DATETIME     NOT NULL,
  PRIMARY KEY (dataset_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_ceremonies (
  ceremony_no       SMALLINT UNSIGNED NOT NULL,     -- 1 … 98
  year_label        VARCHAR(9)   NOT NULL,          -- '1927/28' … '2025'
  film_year_start   SMALLINT UNSIGNED NOT NULL,     -- 1927
  film_year_end     SMALLINT UNSIGNED NOT NULL,     -- 1928
  PRIMARY KEY (ceremony_no),
  UNIQUE KEY uq_ceremony_year_label (year_label),
  CONSTRAINT chk_ceremony_years CHECK (film_year_end >= film_year_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_classes (
  class_code        VARCHAR(16)  NOT NULL,          -- Acting, Directing, Writing, Title,
  sort_order        TINYINT UNSIGNED NOT NULL,      -- Production, Music, SciTech, Special
  PRIMARY KEY (class_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_categories (
  category_id       SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  canonical_name    VARCHAR(100) NOT NULL,          -- 'ACTOR IN A LEADING ROLE'
  slug              VARCHAR(100) NOT NULL,          -- 'actor-in-a-leading-role'
  class_code        VARCHAR(16)  NOT NULL,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_category_name (canonical_name),
  UNIQUE KEY uq_category_slug (slug),
  CONSTRAINT fk_category_class FOREIGN KEY (class_code) REFERENCES ledger_classes (class_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_titles (
  imdb_id           VARCHAR(10)  NOT NULL,
  title             VARCHAR(255) NOT NULL,          -- canonical title
  release_year      SMALLINT UNSIGNED NULL,
  wikidata_qid      VARCHAR(12)  NULL,
  PRIMARY KEY (imdb_id),
  KEY k_title_name (title(100)),
  CONSTRAINT chk_title_imdb_id CHECK (imdb_id REGEXP '^tt[0-9]{7,8}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_entities (
  imdb_id           VARCHAR(10)  NOT NULL,
  kind              ENUM('person','company') NOT NULL,
  name              VARCHAR(255) NOT NULL,          -- canonical name
  birth_year        SMALLINT UNSIGNED NULL,         -- people only, when known to the year
  wikidata_qid      VARCHAR(12)  NULL,
  PRIMARY KEY (imdb_id),
  KEY k_entity_name (name(100)),
  CONSTRAINT chk_entity_imdb_id CHECK (imdb_id REGEXP '^(nm|co)[0-9]{7,8}$'),
  CONSTRAINT chk_entity_kind CHECK ((kind = 'person'  AND imdb_id LIKE 'nm%')
                                 OR (kind = 'company' AND imdb_id LIKE 'co%'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

CREATE TABLE ledger_nominations (
  nomination_id     INT UNSIGNED NOT NULL,          -- stable: source row order, 1-based
  ceremony_no       SMALLINT UNSIGNED NOT NULL,
  category_id       SMALLINT UNSIGNED NOT NULL,
  category_as_given VARCHAR(160) NOT NULL,          -- the category's name that year
  is_winner         TINYINT(1)   NOT NULL DEFAULT 0,
  is_official       TINYINT(1)   NOT NULL DEFAULT 1,-- 0 when the Academy notes it was not an official nomination
  credit_line       VARCHAR(600) NULL,              -- the Academy's credit, verbatim
  detail            VARCHAR(600) NULL,              -- roles, song titles or technical field, verbatim
  note              TEXT         NULL,
  citation          TEXT         NULL,
  PRIMARY KEY (nomination_id),
  KEY k_nom_ceremony_category (ceremony_no, category_id, is_winner),
  KEY k_nom_category_ceremony (category_id, ceremony_no),
  KEY k_nom_winners (is_winner, ceremony_no),
  CONSTRAINT fk_nom_ceremony FOREIGN KEY (ceremony_no) REFERENCES ledger_ceremonies (ceremony_no),
  CONSTRAINT fk_nom_category FOREIGN KEY (category_id) REFERENCES ledger_categories (category_id),
  CONSTRAINT chk_nom_flags CHECK (is_winner IN (0,1) AND is_official IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- The films a nomination is for, in credit order. A title slot with no known
-- IMDb ID keeps its text and its position; imdb_id is simply NULL.
CREATE TABLE ledger_nomination_titles (
  nomination_id     INT UNSIGNED NOT NULL,
  ordinal           TINYINT UNSIGNED NOT NULL,      -- 1-based
  imdb_id           VARCHAR(10)  NULL,
  title_as_credited VARCHAR(255) NOT NULL,
  detail            VARCHAR(300) NULL,              -- the role or song for this title, when the
                                                    -- source aligns one detail per title
  PRIMARY KEY (nomination_id, ordinal),
  KEY k_nt_title (imdb_id),
  CONSTRAINT fk_nt_nomination FOREIGN KEY (nomination_id) REFERENCES ledger_nominations (nomination_id) ON DELETE CASCADE,
  CONSTRAINT fk_nt_title FOREIGN KEY (imdb_id) REFERENCES ledger_titles (imdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- The named nominees, in credit order, exactly as the Academy credits them.
CREATE TABLE ledger_nomination_credits (
  nomination_id     INT UNSIGNED NOT NULL,
  ordinal           TINYINT UNSIGNED NOT NULL,      -- 1-based
  name_as_credited  VARCHAR(255) NOT NULL,
  PRIMARY KEY (nomination_id, ordinal),
  CONSTRAINT fk_nc_nomination FOREIGN KEY (nomination_id) REFERENCES ledger_nominations (nomination_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Who a credit denotes. Usually one entity; sometimes more ("Roderick Jaynes"
-- is Joel and Ethan Coen); sometimes none known (no row here).
CREATE TABLE ledger_credit_identities (
  nomination_id     INT UNSIGNED NOT NULL,
  ordinal           TINYINT UNSIGNED NOT NULL,
  imdb_id           VARCHAR(10)  NOT NULL,
  PRIMARY KEY (nomination_id, ordinal, imdb_id),
  KEY k_ci_entity (imdb_id, nomination_id),
  CONSTRAINT fk_ci_credit FOREIGN KEY (nomination_id, ordinal) REFERENCES ledger_nomination_credits (nomination_id, ordinal) ON DELETE CASCADE,
  CONSTRAINT fk_ci_entity FOREIGN KEY (imdb_id) REFERENCES ledger_entities (imdb_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Every change made to the source, with its evidence. Nothing is corrected
-- silently.
CREATE TABLE ledger_corrections (
  correction_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nomination_id     INT UNSIGNED NULL,              -- NULL for dataset-wide fixes
  field             VARCHAR(40)  NOT NULL,          -- 'NomineeIds', 'Nominees', 'Film', …
  before_value      TEXT         NULL,
  after_value       TEXT         NULL,
  reason            VARCHAR(60)  NOT NULL,          -- 'wrong_id', 'encoding', 'typo', …
  evidence          TEXT         NOT NULL,
  verification      VARCHAR(255) NOT NULL,          -- how it was confirmed
  PRIMARY KEY (correction_id),
  KEY k_corr_nomination (nomination_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ----------------------------------------------------------------------------
-- Read views
-- ----------------------------------------------------------------------------

-- One row per nomination, with its ceremony, category and class.
CREATE OR REPLACE VIEW ledger_v_nominations AS
SELECT n.nomination_id, n.ceremony_no, c.year_label, cat.canonical_name AS category,
       cat.slug AS category_slug, cat.class_code, n.category_as_given, n.is_winner,
       n.is_official, n.credit_line, n.detail, n.note, n.citation
FROM ledger_nominations n
JOIN ledger_ceremonies c   ON c.ceremony_no  = n.ceremony_no
JOIN ledger_categories cat ON cat.category_id = n.category_id;

-- Every (nomination, person/company) pair: the filmography bridge for nm/co IDs.
CREATE OR REPLACE VIEW ledger_v_entity_nominations AS
SELECT ci.imdb_id, e.kind, e.name AS canonical_name, nc.name_as_credited,
       n.nomination_id, n.ceremony_no, n.category_id, n.is_winner, n.is_official
FROM ledger_credit_identities ci
JOIN ledger_nomination_credits nc ON nc.nomination_id = ci.nomination_id AND nc.ordinal = ci.ordinal
JOIN ledger_entities e             ON e.imdb_id       = ci.imdb_id
JOIN ledger_nominations n          ON n.nomination_id = ci.nomination_id;

-- Every (nomination, title) pair: the bridge for tt IDs.
CREATE OR REPLACE VIEW ledger_v_title_nominations AS
SELECT nt.imdb_id, t.title AS canonical_title, nt.title_as_credited, nt.detail,
       n.nomination_id, n.ceremony_no, n.category_id, n.is_winner, n.is_official
FROM ledger_nomination_titles nt
JOIN ledger_titles t       ON t.imdb_id       = nt.imdb_id
JOIN ledger_nominations n  ON n.nomination_id = nt.nomination_id;
