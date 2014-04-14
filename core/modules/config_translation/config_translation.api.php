<?php

/**
 * @file
 * Hooks provided by the Configuration Translation module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Introduce dynamic translation tabs for translation of configuration.
 *
 * This hook augments MODULE.config_translation.yml as well as
 * THEME.config_translation.yml files to collect dynamic translation mapper
 * information. If your information is static, just provide such a YAML file
 * with your module containing the mapping.
 *
 * Note that while themes can provide THEME.config_translation.yml files this
 * hook is not invoked for themes.
 *
 * @param array $info
 *   An associative array of configuration mapper information. Use an entity
 *   name for the key (for entity mapping) or a unique string for configuration
 *   name list mapping. The values of the associative array are arrays
 *   themselves in the same structure as the *.config_translation.yml files.
 *
 * @see HOOK_config_translation_info_alter()
 * @see \Drupal\config_translation\ConfigMapperManagerInterface
 * @see \Drupal\config_translation\Routing\RouteSubscriber::routes()
 */
function HOOK_config_translation_info(&$info) {
  $entity_manager = \Drupal::entityManager();
  $route_provider = \Drupal::service('router.route_provider');

  // If field UI is not enabled, the base routes of the type
  // "field_ui.instance_edit_$entity_type" are not defined.
  if (\Drupal::moduleHandler()->moduleExists('field_ui')) {
    // Add fields entity mappers to all fieldable entity types defined.
    foreach ($entity_manager->getDefinitions() as $entity_type_id => $entity_type) {
      $base_route = NULL;
      try {
        $base_route = $route_provider->getRouteByName('field_ui.instance_edit_' . $entity_type_id);
      }
      catch (RouteNotFoundException $e) {
        // Ignore non-existent routes.
      }

      // Make sure entity type is fieldable and has a base route.
      if ($entity_type->isFieldable() && !empty($base_route)) {
        $info[$entity_type_id . '_fields'] = array(
          'base_route_name' => 'field_ui.instance_edit_' . $entity_type_id,
          'entity_type' => 'field_instance_config',
          'title' => t('!label field'),
          'class' => '\Drupal\config_translation\ConfigFieldInstanceMapper',
          'base_entity_type' => $entity_type_id,
          'weight' => 10,
        );
      }
    }
  }
}

/**
 * Alter existing translation tabs for translation of configuration.
 *
 * This hook is useful to extend existing configuration mappers with new
 * configuration names, for example when altering existing forms with new
 * settings stored elsewhere. This allows the translation experience to also
 * reflect the compound form element in one screen.
 *
 * @param array $info
 *   An associative array of discovered configuration mappers. Use an entity
 *   name for the key (for entity mapping) or a unique string for configuration
 *   name list mapping. The values of the associative array are arrays
 *   themselves in the same structure as the *.config_translation.yml files.
 *
 * @see HOOK_translation_info()
 * @see \Drupal\config_translation\ConfigMapperManagerInterface
 */
function HOOK_config_translation_info_alter(&$info) {
  // Add additional site settings to the site information screen, so it shows
  // up on the translation screen. (Form alter in the elements whose values are
  // stored in this config file using regular form altering on the original
  // configuration form.)
  $info['system.site_information_settings']['names'][] = 'example.site.setting';
}

/**
 * Alter config typed data definitions.
 *
 * Used to automatically generate translation forms, you can alter the typed
 * data types representing each configuration schema type to change default
 * labels or form element renderers.
 *
 * @param $definitions
 *   Associative array of configuration type definitions keyed by schema type
 *   names. The elements are themselves array with information about the type.
 */
function HOOK_config_translation_type_info_alter(&$definitions) {
  // Enhance the text and date type definitions with classes to generate proper
  // form elements in ConfigTranslationFormBase. Other translatable types will
  // appear as a one line textfield.
  $definitions['text']['form_element_class'] = '\Drupal\config_translation\FormElement\Textarea';
  $definitions['date_format']['form_element_class'] = '\Drupal\config_translation\FormElement\DateFormat';
}

/**
 * @} End of "addtogroup hooks".
 */
