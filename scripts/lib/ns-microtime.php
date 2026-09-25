<?php
/**
 * Namespaced `microtime()` overrides for the execution harness.
 *
 * PHP resolves an unqualified function call in a namespace against that
 * namespace's function table before falling back to the global one. Declaring
 * these here lets a test drive the wall-clock budgets of the chunked page
 * (Alegra\Connector\Admin) and of Products::import_from_alegra
 * (Alegra\Connector\Sync) deterministically, without a real sleep.
 *
 * This file is harness-only: it is never shipped in the release ZIP, and in
 * production no namespaced `microtime()` exists, so the plugin calls the real
 * global one.
 *
 * The fake is controlled by $GLOBALS['alegra_test_fake_microtime'] (null =
 * real clock); see wp-stubs.php and alegra_test_reset().
 */

declare(strict_types=1);

namespace Alegra\Connector\Admin;

function microtime(bool $as_float = false): string|float
{
    return \alegra_test_microtime($as_float);
}

namespace Alegra\Connector\Sync;

function microtime(bool $as_float = false): string|float
{
    return \alegra_test_microtime($as_float);
}
