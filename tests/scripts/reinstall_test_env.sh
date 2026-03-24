#!/bin/bash
#
# Reinstall the OntologySync Docker test environment from scratch.
#
# Tears down all containers/volumes and rebuilds a fresh MediaWiki instance
# with SMW, SemanticSchemas, and OntologySync.
#
# Flags:
#   --skip-install   Skip update.php — wiki starts with no base config.
#   --run-jobs       Drain the job queue after install.
#   --no-jobrunner   Stop the background jobrunner container after setup.
#
# Examples:
#   ./reinstall_test_env.sh                          # Default: install + jobrunner running
#   ./reinstall_test_env.sh --run-jobs --no-jobrunner # Full setup, drain jobs, no background runner
#   ./reinstall_test_env.sh --skip-install --no-jobrunner  # Bare wiki for testing
#
set -e

SKIP_INSTALL=false
NO_JOBRUNNER=false
RUN_JOBS=false
for arg in "$@"; do
	case "$arg" in
		--skip-install) SKIP_INSTALL=true ;;
		--no-jobrunner) NO_JOBRUNNER=true ;;
		--run-jobs) RUN_JOBS=true ;;
		--help|-h) sed -n '2,/^set -e/{ /^#/s/^# \?//p }' "$0"; exit 0 ;;
	esac
done

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Building images..."
docker compose build

echo "==> Starting new environment..."
docker compose up -d

echo "==> Waiting for MW to be ready..."
for i in $(seq 1 60); do
	if docker compose exec -T wiki curl -sf http://localhost/api.php?action=query > /dev/null 2>&1; then
		echo "MW is ready."
		break
	fi
	if [ "$i" -eq 60 ]; then
		echo "ERROR: MediaWiki did not become ready in time."
		docker compose logs wiki
		exit 1
	fi
	sleep 2
done

if [ "$SKIP_INSTALL" = true ]; then
	echo "==> Skipping update.php (--skip-install)."
else
	echo "==> Running update.php..."
	docker compose exec wiki php maintenance/run.php update --quick
fi

if [ "$RUN_JOBS" = true ]; then
	echo "==> Running job queue (--run-jobs)..."
	docker compose exec wiki php maintenance/run.php runJobs
fi

if [ "$NO_JOBRUNNER" = true ]; then
	echo "==> Stopping jobrunner (--no-jobrunner)..."
	docker compose stop jobrunner
fi

echo "==> Environment ready!"
echo "Visit http://localhost:8890"
