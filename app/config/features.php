<?php
/**
 * Feature flags — flip ON when ready to activate.
 *
 * Edit this file directly in production to toggle a feature live.
 * Each flag should be defaulted OFF until staff/process is ready.
 */

// SAFE_COIN_SYSTEM — Phase 2 of the coin rollout.
// ON: tills target $100 bills + $100 coin; coin overage flows to safe_coin_ledger;
//     /safe-coins admin page becomes available.
// OFF: original behavior (R1/R2 bills_fixed @ $100, R3 total_fixed @ $150);
//     no overage tracking; /safe-coins returns 404.
define('FEATURE_SAFE_COIN_SYSTEM', false);
