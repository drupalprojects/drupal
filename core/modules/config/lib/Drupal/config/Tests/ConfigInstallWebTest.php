<?php

/**
 * @file
 * Definition of Drupal\config\Tests\ConfigInstallTest.
 */

namespace Drupal\config\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\Core\Config\FileStorage;

/**
 * Tests installation of configuration objects in installation functionality.
 */
class ConfigInstallWebTest extends WebTestBase {
  public static function getInfo() {
    return array(
      'name' => 'Install, disable and uninstall functionality',
      'description' => 'Tests installation and removal of configuration objects in install, disable and uninstall functionality.',
      'group' => 'Configuration',
    );
  }

  function setUp() {
    parent::setUp();

    // Ensure the global variable being asserted by this test does not exist;
    // a previous test executed in this request/process might have set it.
    unset($GLOBALS['HOOK_config_test']);
  }

  /**
   * Tests module re-installation.
   */
  function testIntegrationModuleReinstallation() {
    $default_config = 'config_integration_test.settings';
    $default_configuration_entity = 'config_test.dynamic.config_integration_test';

    // Install the config_test module we're integrating with.
    \Drupal::moduleHandler()->install(array('config_test'));

    // Verify the configuration does not exist prior to installation.
    $config_static = \Drupal::config($default_config);
    $this->assertIdentical($config_static->isNew(), TRUE);
    $config_entity = \Drupal::config($default_configuration_entity);
    $this->assertIdentical($config_entity->isNew(), TRUE);

    // Install the integration module.
    \Drupal::moduleHandler()->install(array('config_integration_test'));

    // Verify that default module config exists.
    \Drupal::configFactory()->reset($default_config);
    \Drupal::configFactory()->reset($default_configuration_entity);
    $config_static = \Drupal::config($default_config);
    $this->assertIdentical($config_static->isNew(), FALSE);
    $this->assertIdentical($config_static->get('foo'), 'default setting');
    $config_entity = \Drupal::config($default_configuration_entity);
    $this->assertIdentical($config_entity->isNew(), FALSE);
    $this->assertIdentical($config_entity->get('label'), 'Default integration config label');

    // Customize both configuration objects.
    $config_static->set('foo', 'customized setting')->save();
    $config_entity->set('label', 'Customized integration config label')->save();

    // @todo FIXME: Setting config keys WITHOUT SAVING retains the changed config
    //   object in memory. Every new call to \Drupal::config() MUST revert in-memory changes
    //   that haven't been saved!
    //   In other words: This test passes even without this reset, but it shouldn't.
    $this->container->get('config.factory')->reset();

    // Disable and uninstall the integration module.
    module_uninstall(array('config_integration_test'));

    // Verify the integration module's config was uninstalled.
    $config_static = \Drupal::config($default_config);
    $this->assertIdentical($config_static->isNew(), TRUE);

    // Verify the integration config still exists.
    $config_entity = \Drupal::config($default_configuration_entity);
    $this->assertIdentical($config_entity->isNew(), FALSE);
    $this->assertIdentical($config_entity->get('label'), 'Customized integration config label');

    // Reinstall the integration module.
    \Drupal::moduleHandler()->install(array('config_integration_test'));

    // Verify the integration module's config was re-installed.
    \Drupal::configFactory()->reset($default_config);
    \Drupal::configFactory()->reset($default_configuration_entity);
    $config_static = \Drupal::config($default_config);
    $this->assertIdentical($config_static->isNew(), FALSE);
    $this->assertIdentical($config_static->get('foo'), 'default setting');

    // Verify the customized integration config still exists.
    $config_entity = \Drupal::config($default_configuration_entity);
    $this->assertIdentical($config_entity->isNew(), FALSE);
    $this->assertIdentical($config_entity->get('label'), 'Customized integration config label');
  }

  /**
   * Tests install profile config changes.
   */
  function testInstallProfileConfigOverwrite() {
    $config_name = 'system.cron';
    // The expected configuration from the system module.
    $expected_original_data = array(
      'threshold' => array(
        'autorun' => 0,
        'requirements_warning' => 172800,
        'requirements_error' => 1209600,
      ),
    );
    // The expected active configuration altered by the install profile.
    $expected_profile_data = array(
      'threshold' => array(
        'autorun' => 0,
        'requirements_warning' => 259200,
        'requirements_error' => 1209600,
      ),
    );

    // Verify that the original data matches. We have to read the module config
    // file directly, because the install profile default system.cron.yml
    // configuration file was used to create the active configuration.
    $config_dir = drupal_get_path('module', 'system') . '/config';
    $this->assertTrue(is_dir($config_dir));
    $source_storage = new FileStorage($config_dir);
    $data = $source_storage->read($config_name);
    $this->assertIdentical($data, $expected_original_data);

    // Verify that active configuration matches the expected data, which was
    // created from the testing install profile's system.cron.yml file.
    $config = \Drupal::config($config_name);
    $this->assertIdentical($config->get(), $expected_profile_data);

    // Turn on the test module, which will attempt to replace the
    // configuration data. This attempt to replace the active configuration
    // should be ignored.
    \Drupal::moduleHandler()->install(array('config_override_test'));

    // Verify that the test module has not been able to change the data.
    $config = \Drupal::config($config_name);
    $this->assertIdentical($config->get(), $expected_profile_data);

    // Disable and uninstall the test module.
    \Drupal::moduleHandler()->uninstall(array('config_override_test'));

    // Verify that the data hasn't been altered by removing the test module.
    $config = \Drupal::config($config_name);
    $this->assertIdentical($config->get(), $expected_profile_data);
  }
}
