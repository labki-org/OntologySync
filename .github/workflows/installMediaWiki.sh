#!/bin/bash
#
# Download MediaWiki core and install SMW + PageForms + SemanticSchemas via Composer.
#
# Usage:  bash installMediaWiki.sh REL1_44 6.0 6.0
#   $1 = MW branch (e.g. REL1_43, REL1_44)
#   $2 = SMW version constraint (e.g. 6.0)
#   $3 = PageForms version constraint (e.g. 6.0)
#
set -euo pipefail

MW_BRANCH="${1:?Usage: installMediaWiki.sh <MW_BRANCH> <SMW_VERSION> <PF_VERSION>}"
SMW_VERSION="${2:?Missing SMW version}"
PF_VERSION="${3:?Missing PageForms version}"

MW_DIR="$HOME/mediawiki"

echo "==> Downloading MediaWiki core ($MW_BRANCH)..."
curl -sL "https://github.com/wikimedia/mediawiki/archive/refs/heads/${MW_BRANCH}.tar.gz" | tar xz
mv "mediawiki-${MW_BRANCH}" "$MW_DIR"

cd "$MW_DIR"

echo "==> Creating composer.local.json (SMW ~${SMW_VERSION}, PageForms ~${PF_VERSION})..."
cat > composer.local.json <<EOF
{
	"require": {
		"mediawiki/semantic-media-wiki": "~${SMW_VERSION}",
		"mediawiki/page-forms": "~${PF_VERSION}",
		"symfony/yaml": "^6.0"
	}
}
EOF

echo "==> Installing all Composer dependencies (core + SMW + PageForms)..."
composer update --no-progress --prefer-dist

echo "==> Cloning ParserFunctions..."
git clone --depth 1 -b "$MW_BRANCH" \
	https://github.com/wikimedia/mediawiki-extensions-ParserFunctions.git \
	extensions/ParserFunctions

echo "==> Cloning SemanticSchemas..."
git clone --depth 1 \
	https://github.com/labki-org/SemanticSchemas.git \
	extensions/SemanticSchemas

echo "==> MediaWiki installation complete at $MW_DIR"
