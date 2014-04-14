<?php

/**
 * @file
 * Fake third-party hook implementations for ConfigTest entities.
 *
 * Testing the module/hook system is not the purpose of this test helper module.
 * Therefore, this file implements hooks on behalf of config_test module for
 * config_test entity hooks themselves.
 */

/**
 * Implements HOOK_config_test_load().
 */
function config_test_config_test_load() {
  $GLOBALS['HOOK_config_test']['load'] = __FUNCTION__;
}

/**
 * Implements HOOK_config_test_presave().
 */
function config_test_config_test_presave() {
  $GLOBALS['HOOK_config_test']['presave'] = __FUNCTION__;
}

/**
 * Implements HOOK_config_test_insert().
 */
function config_test_config_test_insert() {
  $GLOBALS['HOOK_config_test']['insert'] = __FUNCTION__;
}

/**
 * Implements HOOK_config_test_update().
 */
function config_test_config_test_update() {
  $GLOBALS['HOOK_config_test']['update'] = __FUNCTION__;
}

/**
 * Implements HOOK_config_test_predelete().
 */
function config_test_config_test_predelete() {
  $GLOBALS['HOOK_config_test']['predelete'] = __FUNCTION__;
}

/**
 * Implements HOOK_config_test_delete().
 */
function config_test_config_test_delete() {
  $GLOBALS['HOOK_config_test']['delete'] = __FUNCTION__;
}
