<?php

/**
 * @file
 * Contains \Drupal\entity\Entity\EntityFormDisplay.
 */

namespace Drupal\entity\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\entity\EntityDisplayBase;

/**
 * Configuration entity that contains widget options for all components of a
 * entity form in a given form mode.
 *
 * @ConfigEntityType(
 *   id = "entity_form_display",
 *   label = @Translation("Entity form display"),
 *   config_prefix = "form_display",
 *   entity_keys = {
 *     "id" = "id",
 *     "status" = "status"
 *   }
 * )
 */
class EntityFormDisplay extends EntityDisplayBase implements EntityFormDisplayInterface {

  /**
   * {@inheritdoc}
   */
  protected $displayContext = 'form';

  /**
   * Returns the entity_form_display object used to build an entity form.
   *
   * Depending on the configuration of the form mode for the entity bundle, this
   * can be either the display object associated to the form mode, or the
   * 'default' display.
   *
   * This method should only be used internally when rendering an entity form.
   * When assigning suggested display options for a component in a given form
   * mode, entity_get_form_display() should be used instead, in order to avoid
   * inadvertently modifying the output of other form modes that might happen to
   * use the 'default' display too. Those options will then be effectively
   * applied only if the form mode is configured to use them.
   *
   * HOOK_entity_form_display_alter() is invoked on each display, allowing 3rd
   * party code to alter the display options held in the display before they are
   * used to generate render arrays.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity for which the form is being built.
   * @param string $form_mode
   *   The form mode.
   *
   * @return \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   *   The display object that should be used to build the entity form.
   *
   * @see entity_get_form_display()
   * @see HOOK_entity_form_display_alter()
   */
  public static function collectRenderDisplay(ContentEntityInterface $entity, $form_mode) {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    // Check the existence and status of:
    // - the display for the form mode,
    // - the 'default' display.
    if ($form_mode != 'default') {
      $candidate_ids[] = $entity_type . '.' . $bundle . '.' . $form_mode;
    }
    $candidate_ids[] = $entity_type . '.' . $bundle . '.default';
    $results = \Drupal::entityQuery('entity_form_display')
      ->condition('id', $candidate_ids)
      ->condition('status', TRUE)
      ->execute();

    // Load the first valid candidate display, if any.
    $storage = \Drupal::entityManager()->getStorage('entity_form_display');
    foreach ($candidate_ids as $candidate_id) {
      if (isset($results[$candidate_id])) {
        $display = $storage->load($candidate_id);
        break;
      }
    }
    // Else create a fresh runtime object.
    if (empty($display)) {
      $display = $storage->create(array(
        'targetEntityType' => $entity_type,
        'bundle' => $bundle,
        'mode' => $form_mode,
        'status' => TRUE,
      ));
    }

    // Let the display know which form mode was originally requested.
    $display->originalMode = $form_mode;

    // Let modules alter the display.
    $display_context = array(
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'form_mode' => $form_mode,
    );
    \Drupal::moduleHandler()->alter('entity_form_display', $display, $display_context);

    return $display;
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    $this->pluginManager = \Drupal::service('plugin.manager.field.widget');

    parent::__construct($values, $entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public function getRenderer($field_name) {
    if (isset($this->plugins[$field_name])) {
      return $this->plugins[$field_name];
    }

    // Instantiate the widget object from the stored display properties.
    if (($configuration = $this->getComponent($field_name)) && isset($configuration['type']) && ($definition = $this->getFieldDefinition($field_name))) {
      $widget = $this->pluginManager->getInstance(array(
        'field_definition' => $definition,
        'form_mode' => $this->originalMode,
        // No need to prepare, defaults have been merged in setComponent().
        'prepare' => FALSE,
        'configuration' => $configuration
      ));
    }
    else {
      $widget = NULL;
    }

    // Persist the widget object.
    $this->plugins[$field_name] = $widget;
    return $widget;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(ContentEntityInterface $entity, array &$form, array &$form_state) {
    // Set #parents to 'top-level' by default.
    $form += array('#parents' => array());

    // Let each widget generate the form elements.
    foreach ($entity as $name => $items) {
      if ($widget = $this->getRenderer($name)) {
        $items->filterEmptyItems();
        $form[$name] = $widget->form($items, $form, $form_state);

        // Assign the correct weight. This duplicates the reordering done in
        // processForm(), but is needed for other forms calling this method
        // directly.
        $form[$name]['#weight'] = $this->getComponent($name)['weight'];
      }
    }

    // Add a process callback so we can assign weights and hide extra fields.
    $form['#process'][] = array($this, 'processForm');
  }

  /**
   * Process callback: assigns weights and hides extra fields.
   *
   * @see \Drupal\entity\Entity\EntityFormDisplay::buildForm()
   */
  public function processForm($element, $form_state, $form) {
    // Assign the weights configured in the form display.
    foreach ($this->getComponents() as $name => $options) {
      if (isset($element[$name])) {
        $element[$name]['#weight'] = $options['weight'];
      }
    }

    // Hide extra fields.
    $extra_fields = field_info_extra_fields($this->targetEntityType, $this->bundle, 'form');
    foreach ($extra_fields as $extra_field => $info) {
      if (!$this->getComponent($extra_field)) {
        $element[$extra_field]['#access'] = FALSE;
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractFormValues(ContentEntityInterface $entity, array &$form, array &$form_state) {
    $extracted = array();
    foreach ($entity as $name => $items) {
      if ($widget = $this->getRenderer($name)) {
        $widget->extractFormValues($items, $form, $form_state);
        $extracted[$name] = $name;
      }
    }
    return $extracted;
  }

  /**
   * {@inheritdoc}
   */
  public function validateFormValues(ContentEntityInterface $entity, array &$form, array &$form_state) {
    foreach ($entity as $field_name => $items) {
      // Only validate the fields that actually appear in the form, and let the
      // widget assign the violations to the right form elements.
      if ($widget = $this->getRenderer($field_name)) {
        $violations = $items->validate();
        if (count($violations)) {
          $widget->flagErrors($items, $violations, $form, $form_state);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    // Only store the definition, not external objects or derived data.
    $keys = array_keys($this->toArray());
    $keys[] = 'entityTypeId';
    return $keys;
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup() {
    // Run the values from self::toArray() through __construct().
    $values = array_intersect_key($this->toArray(), get_object_vars($this));
    $this->__construct($values, $this->entityTypeId);
  }

}
