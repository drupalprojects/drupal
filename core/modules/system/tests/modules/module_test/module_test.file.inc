<?php

/**
 * @file
 * A file to test \Drupal::moduleHandler()->getImplementations() loading includes.
 */

/**
 * Implements HOOK_test_hook().
 */
function module_test_test_hook() {
  return array('module_test' => 'success!');
}
