#!/bin/bash
# Populated-upgrade gate: proves an OLD, POPULATED schema survives the
# migration chain to latest — the path the fresh-install lane can never
# exercise (audit: destructive historical migrations only matter on data).
#
# Pipeline (against the throwaway MySQL, separate database crm_upgrade_test):
#   1. fresh DB, migrate ONLY to the pre-drop era (Version20260211133000)
#   2. seed business rows (companies, contacts with legacy columns populated,
#      estimates rows)
#   3. app:migrations:preflight must FAIL (estimates populated → blocker)
#   4. archive estimates rows out of the way (the documented preservation
#      step), preflight must PASS
#   5. migrate to latest
#   6. assert business-history invariants: company/contact rows preserved
set -euo pipefail
cd "$(dirname "$0")/../.."

export APP_ENV=test
export LOCK_DSN=flock
export DATABASE_URL="mysql://crm_test:crm_test_pw@127.0.0.1:3309/crm_upgrade?charset=utf8mb4"
# effective DB name gets the _test suffix

UPGRADE_DB=crm_upgrade_test

step() { echo "── $* ──"; }

step "recreate $UPGRADE_DB"
APP_ENV=test php bin/console doctrine:database:drop --if-exists --force >/dev/null
APP_ENV=test php bin/console doctrine:database:create >/dev/null

step "migrate to pre-drop era (Version20260211133000)"
APP_ENV=test php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20260211133000' --no-interaction >/dev/null

step "seed populated business data"
docker exec -i crm-ci-mysql mysql -ucrm_test -pcrm_test_pw "$UPGRADE_DB" <<'SQL'
INSERT INTO companies (name, sector, account_tier, pipeline_stage, company_status, created_at)
VALUES ('Legacy Manufacturing SARL', 'Automotive', 'B', 'Prospect', 'approved', NOW()),
       ('Old-World Electronics', 'Industrial', 'C', 'MQL', 'approved', NOW());
INSERT INTO contacts (company_id, first_name, last_name, email, created_at)
SELECT id, 'Amine', 'Legacy', 'amine.legacy@example.com', NOW() FROM companies WHERE name = 'Legacy Manufacturing SARL';
INSERT INTO contacts (company_id, first_name, last_name, email, created_at)
SELECT id, 'Sara', 'Heritage', 'sara.heritage@example.com', NOW() FROM companies WHERE name = 'Old-World Electronics';
-- Legacy column data that a later migration drops: MUST be detected by preflight
-- (subscribed already exists at this schema era; populate it with real values)
UPDATE contacts SET subscribed = 1 WHERE email = 'amine.legacy@example.com';
-- Estimates rows that the historical DROP TABLE migration would destroy.
-- The table was created (and later dropped) across the chain; recreate the
-- era schema if this stop-point does not have it, then populate it.
CREATE TABLE IF NOT EXISTS estimates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  rfq_id INT DEFAULT NULL,
  estimate_number VARCHAR(50) NOT NULL,
  origin_country VARCHAR(100) NOT NULL,
  destination_country VARCHAR(100) NOT NULL,
  origin_port VARCHAR(100) DEFAULT NULL,
  destination_port VARCHAR(100) DEFAULT NULL,
  material_cost NUMERIC(15,2) NOT NULL,
  labor_cost NUMERIC(15,2) NOT NULL,
  freight_cost NUMERIC(15,2) NOT NULL,
  duty_cost NUMERIC(15,2) NOT NULL,
  other_costs NUMERIC(15,2) DEFAULT NULL,
  total_landed_cost NUMERIC(15,2) NOT NULL,
  currency VARCHAR(10) NOT NULL,
  duty_rate NUMERIC(5,2) DEFAULT NULL,
  fta_agreement VARCHAR(100) DEFAULT NULL,
  fta_qualified TINYINT(1) NOT NULL DEFAULT 0,
  bom_data LONGTEXT,
  sha256_hash VARCHAR(64) DEFAULT NULL,
  version_id VARCHAR(100) DEFAULT NULL,
  notes LONGTEXT,
  created_at DATETIME NOT NULL,
  updated_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_estimate_number (estimate_number),
  CONSTRAINT fk_est_company FOREIGN KEY (company_id) REFERENCES companies (id)
);
INSERT INTO estimates (company_id, estimate_number, origin_country, destination_country,
                       material_cost, labor_cost, freight_cost, duty_cost,
                       total_landed_cost, currency, fta_qualified, created_at)
SELECT id, 'EST-LEGACY-0001', 'MA', 'US',
       8000.00, 2000.00, 1500.00, 845.67,
       12345.67, 'USD', 0, NOW()
FROM companies WHERE name = 'Legacy Manufacturing SARL';
SQL

step "preflight must FAIL with populated estimates"
if APP_ENV=test php bin/console app:migrations:preflight >/dev/null 2>&1; then
  echo "✖ preflight PASSED but estimates rows exist — the guard is broken" >&2
  exit 1
fi
echo "✓ preflight correctly blocked the destructive upgrade"

step "preserve estimates data (documented operator step), then preflight must PASS"
docker exec -i crm-ci-mysql mysql -ucrm_test -pcrm_test_pw "$UPGRADE_DB" <<'SQL'
CREATE TABLE IF NOT EXISTS legacy_estimates_preserved AS SELECT * FROM estimates;
DELETE FROM estimates;
-- Preserve the legacy subscribed flag too (documented operator step): the
-- preflight blocks the column drop while real values remain.
CREATE TABLE IF NOT EXISTS legacy_contacts_subscribed AS SELECT id, email, subscribed FROM contacts WHERE subscribed IS NOT NULL AND subscribed <> 0;
UPDATE contacts SET subscribed = 0;  -- column is NOT NULL at this schema era
SQL
APP_ENV=test php bin/console app:migrations:preflight | tail -1

step "migrate populated schema to LATEST"
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration | tail -1

step "verify business-history invariants"
read -r COMPANIES CONTACTS < <(docker exec crm-ci-mysql mysql -N -ucrm_test -pcrm_test_pw "$UPGRADE_DB" -e "SELECT (SELECT COUNT(*) FROM companies), (SELECT COUNT(*) FROM contacts);" | awk '{print $1, $2}')
if [ "$COMPANIES" != "2" ] || [ "$CONTACTS" != "2" ]; then
  echo "✖ business history lost across upgrade: companies=$COMPANIES contacts=$CONTACTS (expected 2/2)" >&2
  exit 1
fi
PRESERVED=$(docker exec crm-ci-mysql mysql -N -ucrm_test -pcrm_test_pw "$UPGRADE_DB" -e "SELECT COUNT(*) FROM legacy_estimates_preserved;")
if [ "$PRESERVED" != "1" ]; then
  echo "✖ preserved estimates rows missing after upgrade ($PRESERVED)" >&2
  exit 1
fi

echo "✓ populated-upgrade gate passed: history preserved across pre-drop → latest"
