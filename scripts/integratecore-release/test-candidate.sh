#!/usr/bin/env bash
set -euo pipefail

# Port of the backend gate from successful UI Actions run 45858393855.
php artisan package:discover
php artisan migrate --force
php artisan db:seed --force

for file in \
    tests/Unit/TaskRoundingTest.php \
    tests/Unit/IntegrateCore/ConsultingHoursTest.php \
    tests/Unit/IntegrateCore/VendorSupportRoutingTest.php \
    tests/Unit/TaskRepositoryBulkUpdateTest.php
do
    vendor/bin/phpunit --fail-on-empty-test-suite "$file"
done

# Run each method independently: one matching test cannot hide another missing one.
test_method() {
    vendor/bin/phpunit --fail-on-empty-test-suite --filter "$2$" "$1"
}
task=tests/Feature/TaskApiTest.php
test_method "$task" testPutRejectsOverlapAfterFirstTimeLogPair
test_method "$task" testPutAllowsFinalRunningTimeLogEntry
test_method "$task" testBulkClientReassignmentMovesLegacyConsultingHoursViaApi
test_method tests/Feature/ClientApiTest.php testTaskConsultingHoursSnapshotDoesNotRunClientFileSync
test_method tests/Feature/Client/ClientMergeTest.php testMergeRetainsAlreadyChargedTaskTimeInCombinedBalance
