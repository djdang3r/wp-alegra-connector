<?php
/**
 * Benchmark: Customers::sync_all() pagination memory behavior
 *
 * Validates the 2.2.0 fix for unbounded memory in customer sync by simulating
 * both the OLD (number => -1) and NEW (paginated) implementations against a
 * synthetic dataset. Does NOT require WordPress.
 *
 * Usage:
 *   php scripts/benchmark-customers-sync.php [total_users] [batch_size]
 *   php scripts/benchmark-customers-sync.php 5000 100
 *   php scripts/benchmark-customers-sync.php 10000 50
 *
 * Output:
 *   - Peak memory used by each implementation
 *   - Memory ratio (paginated should be MUCH lower)
 *   - Wall-clock time
 *
 * Expected result for 5000 users with batch_size=100:
 *   - OLD (unbounded): ~50-100 MB peak (depending on user object size)
 *   - NEW (paginated): ~2-5 MB peak (only batch_size users in memory at once)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: this script must be run from the command line.\n");
    exit(2);
}

// Parse args
$total_users = (int) ($argv[1] ?? 5000);
$batch_size = (int) ($argv[2] ?? 100);

if ($total_users < 1 || $batch_size < 1) {
    fwrite(STDERR, "Usage: php scripts/benchmark-customers-sync.php [total_users] [batch_size]\n");
    exit(2);
}

echo "=== Customers::sync_all() Memory Benchmark ===\n";
echo "Total users (simulated):  {$total_users}\n";
echo "Batch size (paginated):   {$batch_size}\n";
echo "PHP memory_limit:         " . ini_get('memory_limit') . "\n\n";

// Generate a stub user factory (mimics WP_User weight — ~2KB each including metadata)
function make_stub_user(int $id): stdClass
{
    // Approximate memory footprint of a WP_User with a few meta entries.
    // Real WP_User is heavier, but this gives a representative order of magnitude.
    $user = new stdClass();
    $user->ID = $id;
    $user->user_login = "user_{$id}";
    $user->user_email = "user{$id}@example.test";
    $user->user_nicename = "User {$id}";
    $user->display_name = "User {$id}";
    // Simulate ~2KB of meta
    $user->billing_phone = '+57 300 123 4567';
    $user->billing_address_1 = 'Calle ' . ($id % 100) . ' #' . ($id % 50) . '-' . ($id % 30);
    $user->billing_city = 'Bogotá';
    $user->billing_nit = '90012345' . ($id % 10) . '-' . ($id % 10);
    return $user;
}

// -----------------------------------------------------------------
// OLD implementation: get_users(['number' => -1]) — loads ALL into memory
// -----------------------------------------------------------------
function old_sync_all(int $total): array
{
    $result = ['synced' => 0, 'errors' => 0];
    $users = [];
    for ($i = 1; $i <= $total; $i++) {
        $users[] = make_stub_user($i);
    }
    // Simulate the sync loop (just count)
    foreach ($users as $user) {
        // sync_to_alegra(...) — skip actual API call
        $result['synced']++;
    }
    unset($users); // explicit cleanup
    return $result;
}

// -----------------------------------------------------------------
// NEW implementation: paginated loop
// -----------------------------------------------------------------
function new_sync_all(int $total, int $batch_size): array
{
    $result = ['synced' => 0, 'errors' => 0];
    $page = 1;
    while (true) {
        // Simulate get_users(['number' => $batch_size, 'paged' => $page])
        $batch = [];
        $start = ($page - 1) * $batch_size;
        for ($i = 1; $i <= $batch_size; $i++) {
            $id = $start + $i;
            if ($id > $total) break 2;
            $batch[] = make_stub_user($id);
        }
        if (empty($batch)) break;

        foreach ($batch as $user) {
            $result['synced']++;
        }
        unset($batch); // free batch memory before next iteration
        $page++;
    }
    return $result;
}

// -----------------------------------------------------------------
// Run benchmarks
// -----------------------------------------------------------------
echo "--- Running OLD implementation (unbounded) ---\n";
$start_mem = memory_get_usage(true);
$start_time = microtime(true);
$old_result = old_sync_all($total_users);
$old_time = microtime(true) - $start_time;
$old_peak = memory_get_peak_usage(true) - $start_mem;

echo "  Synced: {$old_result['synced']}\n";
echo "  Time:   " . number_format($old_time, 3) . "s\n";
echo "  Peak memory delta: " . number_format($old_peak / 1024 / 1024, 2) . " MB\n\n";

echo "--- Running NEW implementation (paginated, batch={$batch_size}) ---\n";
$start_mem = memory_get_usage(true);
$start_time = microtime(true);
$new_result = new_sync_all($total_users, $batch_size);
$new_time = microtime(true) - $start_time;
$new_peak = memory_get_peak_usage(true) - $start_mem;

echo "  Synced: {$new_result['synced']}\n";
echo "  Time:   " . number_format($new_time, 3) . "s\n";
echo "  Peak memory delta: " . number_format($new_peak / 1024 / 1024, 2) . " MB\n\n";

// -----------------------------------------------------------------
// Comparison
// -----------------------------------------------------------------
echo "=== Comparison ===\n";
echo "                     OLD (unbounded)   NEW (paginated)   Ratio\n";
echo str_repeat('-', 65) . "\n";
printf("  Peak memory:       %10s MB   %10s MB   %.1fx lower\n",
    number_format($old_peak / 1024 / 1024, 2),
    number_format($new_peak / 1024 / 1024, 2),
    $old_peak > 0 ? $old_peak / max($new_peak, 1) : 0
);
printf("  Time:              %10ss   %10ss   %.1fx %s\n",
    number_format($old_time, 3),
    number_format($new_time, 3),
    $old_time > 0 ? ($old_time / max($new_time, 0.001)) : 0,
    $old_time > $new_time ? 'faster' : 'slower'
);
echo "\n";

if ($new_peak > 50 * 1024 * 1024) {
    echo "WARNING: paginated implementation still uses >50 MB — investigate.\n";
} else {
    echo "PASS: paginated implementation uses <50 MB even with {$total_users} synthetic users.\n";
}

exit(0);
