#!/bin/bash
# Regenerate database-specific SQL from abstract schema
#
# This script uses MediaWiki's generateSchemaSql.php to convert the abstract
# schema (sql/tables.json) into database-specific SQL files.
#
# Requirements:
#   - PHP XML extension (install with: sudo apt-get install php-xml)
#   - MediaWiki core available via composer or adjacent directory
#
# Usage:
#   ./maintenance/regenerateSchema.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(dirname "$SCRIPT_DIR")"

# Try vendor/mediawiki/core first, then ../mediawiki
if [ -f "$EXT_DIR/vendor/mediawiki/core/maintenance/run.php" ]; then
	MW_DIR="$EXT_DIR/vendor/mediawiki/core"
	echo "Using vendor MediaWiki core: $MW_DIR"

	if [ ! -d "$MW_DIR/vendor/wikimedia" ]; then
		echo "Installing MediaWiki core dependencies..."
		cd "$MW_DIR"
		composer install --quiet
		cd "$EXT_DIR"
	fi
elif [ -f "$EXT_DIR/../mediawiki/maintenance/run.php" ]; then
	MW_DIR="$EXT_DIR/../mediawiki"
	echo "Using adjacent MediaWiki core: $MW_DIR"
else
	echo "Error: MediaWiki not found."
	echo ""
	echo "Please install MediaWiki in one of these locations:"
	echo "  - $EXT_DIR/vendor/mediawiki/core (via 'composer install')"
	echo "  - $EXT_DIR/../mediawiki (adjacent clone)"
	exit 1
fi

# Check for PHP XML extension
if ! php -m | grep -q "^xml$"; then
	echo "Warning: PHP XML extension not found."
	echo "Install with: sudo apt-get install php-xml"
	exit 1
fi

echo "Generating MySQL schema from sql/tables.json..."
php "$MW_DIR/maintenance/run.php" generateSchemaSql \
	--json="$EXT_DIR/sql/tables.json" \
	--sql="$EXT_DIR/sql/mysql/tables-generated.sql" \
	--type=mysql

echo "Generating SQLite schema from sql/tables.json..."
php "$MW_DIR/maintenance/run.php" generateSchemaSql \
	--json="$EXT_DIR/sql/tables.json" \
	--sql="$EXT_DIR/sql/sqlite/tables-generated.sql" \
	--type=sqlite

# Normalize source paths for consistent CI output
sed -i "2s|Source: .*sql/tables.json|Source: sql/tables.json|" "$EXT_DIR/sql/mysql/tables-generated.sql"
sed -i "2s|Source: .*sql/tables.json|Source: sql/tables.json|" "$EXT_DIR/sql/sqlite/tables-generated.sql"

echo ""
echo "Schema files regenerated successfully!"
echo "  - sql/mysql/tables-generated.sql"
echo "  - sql/sqlite/tables-generated.sql"
echo ""
echo "Don't forget to commit these files to version control."
