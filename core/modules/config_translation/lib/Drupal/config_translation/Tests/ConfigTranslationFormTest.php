<?php

/**
 * @file
 * Contains \Drupal\config_translation\Tests\ConfigTranslationUiTest.
 */

namespace Drupal\config_translation\Tests;

use Drupal\Core\Language\Language;
use Drupal\simpletest\WebTestBase;

/**
 * Tests for altering configuration translation forms.
 */
class ConfigTranslationFormTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('config_translation', 'config_translation_test');

  /**
   * The plugin ID of the mapper to test.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * The language code of the language to use for testing.
   *
   * @var string
   */
  protected $langcode;

  public static function getInfo() {
    return array(
      'name' => 'Configuration Translation forms',
      'description' => 'Test form altering of configuration translation forms',
      'group' => 'Configuration Translation',
    );
  }

  public function setUp() {
    parent::setUp();

    $definitions = \Drupal::service('plugin.manager.config_translation.mapper')->getDefinitions();
    $this->pluginId = key($definitions);

    $this->langcode = 'xx';
    $language = new Language(array('id' => $this->langcode, 'name' => 'XX'));
    language_save($language);

    \Drupal::state()->set('config_translation_test_alter_form_alter', TRUE);
  }

  /**
   * Tests altering of the configuration translation forms.
   */
  public function testConfigTranslationFormAlter() {
    $form_builder = \Drupal::formBuilder();
    $add_form = $form_builder->getForm('Drupal\config_translation\Form\ConfigTranslationAddForm', \Drupal::request(), $this->pluginId, $this->langcode);
    $edit_form = $form_builder->getForm('Drupal\config_translation\Form\ConfigTranslationEditForm', \Drupal::request(), $this->pluginId, $this->langcode);

    // Test that HOOK_form_BASE_FORM_ID_alter() was called for the base form ID
    // 'config_translation_form'.
    $this->assertTrue($add_form['#base_altered']);
    $this->assertTrue($edit_form['#base_altered']);

    // Test that HOOK_form_FORM_ID_alter() was called for the form IDs
    // 'config_translation_add_form' and 'config_translation_edit_form'.
    $this->assertTrue($add_form['#altered']);
    $this->assertTrue($edit_form['#altered']);
  }

}
