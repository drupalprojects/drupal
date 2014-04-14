<?php

/**
 * @file
 * Contains Drupal\system\Tests\File\UrlRewritingTest.
 */

namespace Drupal\system\Tests\File;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for file URL rewriting.
 */
class UrlRewritingTest extends FileTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('file_test');

  public static function getInfo() {
    return array(
      'name' => 'File URL rewriting',
      'description' => 'Tests for file URL rewriting.',
      'group' => 'File API',
    );
  }

  /**
   * Tests the rewriting of shipped file URLs by HOOK_file_url_alter().
   */
  function testShippedFileURL()  {
    // Test generating an URL to a shipped file (i.e. a file that is part of
    // Drupal core, a module or a theme, for example a JavaScript file).

    // Test alteration of file URLs to use a CDN.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'cdn');
    $filepath = 'core/assets/vendor/jquery/jquery.js';
    $url = file_create_url($filepath);
    $this->assertEqual(FILE_URL_TEST_CDN_1 . '/' . $filepath, $url, 'Correctly generated a CDN URL for a shipped file.');
    $filepath = 'core/misc/favicon.ico';
    $url = file_create_url($filepath);
    $this->assertEqual(FILE_URL_TEST_CDN_2 . '/' . $filepath, $url, 'Correctly generated a CDN URL for a shipped file.');

    // Test alteration of file URLs to use root-relative URLs.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'root-relative');
    $filepath = 'core/assets/vendor/jquery/jquery.js';
    $url = file_create_url($filepath);
    $this->assertEqual(base_path() . '/' . $filepath, $url, 'Correctly generated a root-relative URL for a shipped file.');
    $filepath = 'core/misc/favicon.ico';
    $url = file_create_url($filepath);
    $this->assertEqual(base_path() . '/' . $filepath, $url, 'Correctly generated a root-relative URL for a shipped file.');

    // Test alteration of file URLs to use protocol-relative URLs.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'protocol-relative');
    $filepath = 'core/assets/vendor/jquery/jquery.js';
    $url = file_create_url($filepath);
    $this->assertEqual('/' . base_path() . '/' . $filepath, $url, 'Correctly generated a protocol-relative URL for a shipped file.');
    $filepath = 'core/misc/favicon.ico';
    $url = file_create_url($filepath);
    $this->assertEqual('/' . base_path() . '/' . $filepath, $url, 'Correctly generated a protocol-relative URL for a shipped file.');
  }

  /**
   * Tests the rewriting of public managed file URLs by HOOK_file_url_alter().
   */
  function testPublicManagedFileURL() {
    // Test generating an URL to a managed file.

    // Test alteration of file URLs to use a CDN.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'cdn');
    $uri = $this->createUri();
    $url = file_create_url($uri);
    $public_directory_path = file_stream_wrapper_get_instance_by_scheme('public')->getDirectoryPath();
    $this->assertEqual(FILE_URL_TEST_CDN_2 . '/' . $public_directory_path . '/' . drupal_basename($uri), $url, 'Correctly generated a CDN URL for a created file.');

    // Test alteration of file URLs to use root-relative URLs.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'root-relative');
    $uri = $this->createUri();
    $url = file_create_url($uri);
    $this->assertEqual(base_path() . '/' . $public_directory_path . '/' . drupal_basename($uri), $url, 'Correctly generated a root-relative URL for a created file.');

    // Test alteration of file URLs to use a protocol-relative URLs.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', 'protocol-relative');
    $uri = $this->createUri();
    $url = file_create_url($uri);
    $this->assertEqual('/' . base_path() . '/' . $public_directory_path . '/' . drupal_basename($uri), $url, 'Correctly generated a protocol-relative URL for a created file.');
  }

  /**
   * Test file_url_transform_relative().
   */
  function testRelativeFileURL() {
    // Disable file_test.module's HOOK_file_url_alter() implementation.
    \Drupal::state()->set('file_test.HOOK_file_url_alter', NULL);

    // Create a mock Request for file_url_transform_relative().
    $request = Request::create($GLOBALS['base_url']);
    $this->container->set('request', $request);
    \Drupal::setContainer($this->container);

    // Shipped file.
    $filepath = 'core/assets/vendor/jquery/jquery.js';
    $url = file_create_url($filepath);
    $this->assertIdentical(base_path() . $filepath, file_url_transform_relative($url));

    // Managed file.
    $uri = $this->createUri();
    $url = file_create_url($uri);
    $public_directory_path = file_stream_wrapper_get_instance_by_scheme('public')->getDirectoryPath();
    $this->assertIdentical(base_path() . $public_directory_path . '/' . rawurlencode(drupal_basename($uri)), file_url_transform_relative($url));
  }

}
