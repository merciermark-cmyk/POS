#!/bin/bash
# Monthly archive — creates permanent record of PREVIOUS month for CRA 7-year compliance.
# Runs on the 2nd of each month. Archives:
#   - A fresh DB dump of the full inventory DB (includes all POS tables)
#   - A fresh DB dump of the schedule DB
#   - A fresh DB dump of the PrestaShop DB
#   - Monthly financial reports (CSV + HTML) for the previous month
# Output: /home/gitte512/backups/archive/YYYY-MM/
# Archives are NEVER auto-deleted. Retain for at least 7 years (CRA requirement).

set -e

ARCHIVE_ROOT="/home/gitte512/backups/archive"
SCRIPTS_DIR="/home/gitte512/scripts"

# Previous month (run on 2nd of month)
YEAR=$(date -d "last month" +%Y)
MONTH=$(date -d "last month" +%m)
ARCHIVE_DIR="$ARCHIVE_ROOT/$YEAR-$MONTH"

# DB credentials (each DB has its own user — match nightly backup scripts)
INV_DB="gitte512_git_inventory"
INV_USER="gitte512_inventory_manager"
INV_PASS="Starlifter44*"

SCH_DB="gitte512_schedule"
SCH_USER="gitte512_schedule_manager"
SCH_PASS="8q4grp0mdf"

PS_DB="gitte512_dev_staging"
PS_USER="gitte512_mark"
PS_PASS="8q4grp0mdf"

mkdir -p "$ARCHIVE_DIR"

echo "=== Monthly archive for $YEAR-$MONTH ==="
echo "Archive dir: $ARCHIVE_DIR"

# ── 1. Database dumps (gzipped) ─────────────────────────────────────────────
echo "Dumping inventory DB..."
mysqldump -u"$INV_USER" -p"$INV_PASS" --single-transaction "$INV_DB" | gzip > "$ARCHIVE_DIR/inventory_$YEAR-$MONTH.sql.gz"

echo "Dumping schedule DB..."
mysqldump -u"$SCH_USER" -p"$SCH_PASS" --single-transaction "$SCH_DB" | gzip > "$ARCHIVE_DIR/schedule_$YEAR-$MONTH.sql.gz"

echo "Dumping prestashop DB..."
mysqldump -u"$PS_USER" -p"$PS_PASS" --single-transaction "$PS_DB" | gzip > "$ARCHIVE_DIR/prestashop_$YEAR-$MONTH.sql.gz"

# ── 2. Financial reports (CSV + HTML) ───────────────────────────────────────
echo "Generating monthly financial report..."
php "$SCRIPTS_DIR/generate_monthly_report.php" "$YEAR" "$MONTH" "$ARCHIVE_DIR"

# ── 3. Manifest with checksums ──────────────────────────────────────────────
echo "Writing manifest..."
cd "$ARCHIVE_DIR"
{
    echo "Monthly Archive: $YEAR-$MONTH"
    echo "Generated: $(date)"
    echo "Host: $(hostname)"
    echo ""
    echo "=== File sizes ==="
    ls -lh *.sql.gz *.csv *.html 2>/dev/null
    echo ""
    echo "=== SHA-256 checksums ==="
    sha256sum *.sql.gz *.csv *.html 2>/dev/null
} > manifest.txt

echo "=== Done. Archive contents: ==="
ls -la "$ARCHIVE_DIR"
