<?php
/**
 * @file
 * Definition of Drupal\search\Tests\SearchPreprocessLangcodeTest.
 */

namespace Drupal\search\Tests;

/**
 * Tests that the search prepocessing uses the correct language code.
 */
class SearchPreprocessLangcodeTest extends SearchTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('search_langcode_test');

  public static function getInfo() {
    return array(
      'name' => 'Search preprocess langcode',
      'description' => 'Tests that the search prepocessing uses the correct language code.',
      'group' => 'Search',
    );
  }

  function setUp() {
    parent::setUp();

    $web_user = $this->drupalCreateUser(array(
      'create page content',
      'edit own page content',
      'search content',
      'use advanced search',
    ));
    $this->drupalLogin($web_user);
  }

  /**
   * Tests that HOOK_search_preprocess() returns the correct langcode.
   */
  function testPreprocessLangcode() {
    // Create a node.
    $this->node = $this->drupalCreateNode(array('body' => array(array()), 'langcode' => 'en'));

    // First update the index. This does the initial processing.
    $this->container->get('plugin.manager.search')->createInstance('node_search')->updateIndex();

    // Then, run the shutdown function. Testing is a unique case where indexing
    // and searching has to happen in the same request, so running the shutdown
    // function manually is needed to finish the indexing process.
    search_update_totals();

    // Search for the additional text that is added by the preprocess
    // function. If you search for text that is in the node, preprocess is
    // not invoked on the node during the search excerpt generation.
    $edit = array('or' => 'Additional text');
    $this->drupalPostForm('search/node', $edit, t('Advanced search'));

    // Checks if the langcode message has been set by HOOK_search_preprocess().
    $this->assertText('Langcode Preprocess Test: en');
  }

  /**
   * Tests stemming for HOOK_search_preprocess().
   */
  function testPreprocessStemming() {
    // Create a node.
    $this->node = $this->drupalCreateNode(array(
      'title' => 'we are testing',
      'body' => array(array()),
      'langcode' => 'en',
    ));

    // First update the index. This does the initial processing.
    $this->container->get('plugin.manager.search')->createInstance('node_search')->updateIndex();

    // Then, run the shutdown function. Testing is a unique case where indexing
    // and searching has to happen in the same request, so running the shutdown
    // function manually is needed to finish the indexing process.
    search_update_totals();

    // Search for the title of the node with a POST query.
    $edit = array('or' => 'testing');
    $this->drupalPostForm('search/node', $edit, t('Advanced search'));

    // Check if the node has been found.
    $this->assertText('Search results');
    $this->assertText('we are testing');

    // Search for the same node using a different query.
    $edit = array('or' => 'test');
    $this->drupalPostForm('search/node', $edit, t('Advanced search'));

    // Check if the node has been found.
    $this->assertText('Search results');
    $this->assertText('we are testing');
  }
}
