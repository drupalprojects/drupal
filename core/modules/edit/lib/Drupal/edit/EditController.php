<?php

/**
 * @file
 * Contains \Drupal\edit\EditController.
 */

namespace Drupal\edit;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityInterface;
use Drupal\field\FieldInfo;
use Drupal\edit\Ajax\FieldFormCommand;
use Drupal\edit\Ajax\FieldFormSavedCommand;
use Drupal\edit\Ajax\FieldFormValidationErrorsCommand;
use Drupal\edit\Ajax\EntitySavedCommand;
use Drupal\user\TempStoreFactory;

/**
 * Returns responses for Edit module routes.
 */
class EditController extends ControllerBase {

  /**
   * The TempStore factory.
   *
   * @var \Drupal\user\TempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The in-place editing metadata generator.
   *
   * @var \Drupal\edit\MetadataGeneratorInterface
   */
  protected $metadataGenerator;

  /**
   * The in-place editor selector.
   *
   * @var \Drupal\edit\EditorSelectorInterface
   */
  protected $editorSelector;

  /**
   * The field info service.
   *
   * @var \Drupal\field\FieldInfo
   */
  protected $fieldInfo;

  /**
   * Constructs a new EditController.
   *
   * @param \Drupal\user\TempStoreFactory $temp_store_factory
   *   The TempStore factory.
   * @param \Drupal\edit\MetadataGeneratorInterface $metadata_generator
   *   The in-place editing metadata generator.
   * @param \Drupal\edit\EditorSelectorInterface $editor_selector
   *   The in-place editor selector.
   * @param \Drupal\field\FieldInfo $field_info
   *   The field info service.
   */
  public function __construct(TempStoreFactory $temp_store_factory, MetadataGeneratorInterface $metadata_generator, EditorSelectorInterface $editor_selector, FieldInfo $field_info) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->metadataGenerator = $metadata_generator;
    $this->editorSelector = $editor_selector;
    $this->fieldInfo = $field_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.tempstore'),
      $container->get('edit.metadata.generator'),
      $container->get('edit.editor.selector'),
      $container->get('field.info')
    );
  }

  /**
   * Returns the metadata for a set of fields.
   *
   * Given a list of field edit IDs as POST parameters, run access checks on the
   * entity and field level to determine whether the current user may edit them.
   * Also retrieves other metadata.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function metadata(Request $request) {
    $fields = $request->request->get('fields');
    if (!isset($fields)) {
      throw new NotFoundHttpException();
    }
    $entities = $request->request->get('entities');

    $metadata = array();
    foreach ($fields as $field) {
      list($entity_type, $entity_id, $field_name, $langcode, $view_mode) = explode('/', $field);

      // Load the entity.
      if (!$entity_type || !$this->entityManager()->getDefinition($entity_type)) {
        throw new NotFoundHttpException();
      }
      $entity = $this->entityManager()->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        throw new NotFoundHttpException();
      }

      // Validate the field name and language.
      if (!$field_name || !$entity->hasField($field_name)) {
        throw new NotFoundHttpException();
      }
      if (!$langcode || !$entity->hasTranslation($langcode)) {
        throw new NotFoundHttpException();
      }

      $entity = $entity->getTranslation($langcode);

      // If the entity information for this field is requested, include it.
      $entity_id = $entity->getEntityTypeId() . '/' . $entity_id;
      if (is_array($entities) && in_array($entity_id, $entities) && !isset($metadata[$entity_id])) {
        $metadata[$entity_id] = $this->metadataGenerator->generateEntityMetadata($entity);
      }

      $metadata[$field] = $this->metadataGenerator->generateFieldMetadata($entity->get($field_name), $view_mode);
    }

    return new JsonResponse($metadata);
  }

  /**
   * Returns AJAX commands to load in-place editors' attachments.
   *
   * Given a list of in-place editor IDs as POST parameters, render AJAX
   * commands to load those in-place editors.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response.
   */
  public function attachments(Request $request) {
    $response = new AjaxResponse();
    $editors = $request->request->get('editors');
    if (!isset($editors)) {
      throw new NotFoundHttpException();
    }

    $elements['#attached'] = $this->editorSelector->getEditorAttachments($editors);
    drupal_process_attached($elements);

    return $response;
  }

  /**
   * Returns a single field edit form as an Ajax response.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   * @param string $field_name
   *   The name of the field that is being edited.
   * @param string $langcode
   *   The name of the language for which the field is being edited.
   * @param string $view_mode_id
   *   The view mode the field should be rerendered in.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object containing the search string.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response.
   */
  public function fieldForm(EntityInterface $entity, $field_name, $langcode, $view_mode_id, Request $request) {
    $response = new AjaxResponse();

    // Replace entity with TempStore copy if available and not resetting, init
    // TempStore copy otherwise.
    $tempstore_entity = $this->tempStoreFactory->get('edit')->get($entity->uuid());
    if ($tempstore_entity && $request->request->get('reset') !== 'true') {
      $entity = $tempstore_entity;
    }
    else {
      $this->tempStoreFactory->get('edit')->set($entity->uuid(), $entity);
    }

    $form_state = array(
      'langcode' => $langcode,
      'no_redirect' => TRUE,
      'build_info' => array(
        'args' => array($entity, $field_name),
      ),
    );
    $form = $this->formBuilder()->buildForm('Drupal\edit\Form\EditFieldForm', $form_state);

    if (!empty($form_state['executed'])) {
      // The form submission saved the entity in TempStore. Return the
      // updated view of the field from the TempStore copy.
      $entity = $this->tempStoreFactory->get('edit')->get($entity->uuid());

      // Closure to render the field given a view mode.
      $render_field_in_view_mode = function ($view_mode_id) use ($entity, $field_name, $langcode) {
        return $this->renderField($entity, $field_name, $langcode, $view_mode_id);
      };

      // Re-render the updated field.
      $output = $render_field_in_view_mode($view_mode_id);

      // Re-render the updated field for other view modes (i.e. for other
      // instances of the same logical field on the user's page).
      $other_view_mode_ids = $request->request->get('other_view_modes') ?: array();
      $other_view_modes = array_map($render_field_in_view_mode, array_combine($other_view_mode_ids, $other_view_mode_ids));

      $response->addCommand(new FieldFormSavedCommand($output, $other_view_modes));
    }
    else {
      $response->addCommand(new FieldFormCommand(drupal_render($form)));

      $errors = $this->formBuilder()->getErrors($form_state);
      if (count($errors)) {
        $status_messages = array(
          '#theme' => 'status_messages'
        );
        $response->addCommand(new FieldFormValidationErrorsCommand(drupal_render($status_messages)));
      }
    }

    // When working with a hidden form, we don't want any CSS or JS to be loaded.
    if ($request->request->get('nocssjs') === 'true') {
      drupal_static_reset('_drupal_add_css');
      drupal_static_reset('_drupal_add_js');
    }

    return $response;
  }

  /**
   * Renders a field.
   *
   * If the view mode ID is not an Entity Display view mode ID, then the field
   * was rendered using a custom render pipeline (not the Entity/Field API
   * render pipeline).
   *
   * An example could be Views' render pipeline. In that case, the view mode ID
   * would probably contain the View's ID, display and the row index.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   * @param string $field_name
   *   The name of the field that is being edited.
   * @param string $langcode
   *   The name of the language for which the field is being edited.
   * @param string $view_mode_id
   *   The view mode the field should be rerendered in. Either an Entity Display
   *   view mode ID, or a custom one. See HOOK_edit_render_field().
   *
   * @return string
   *   Rendered HTML.
   *
   * @see HOOK_edit_render_field()
   */
  protected function renderField(EntityInterface $entity, $field_name, $langcode, $view_mode_id) {
    $entity_view_mode_ids = array_keys($this->entityManager()->getViewModes($entity->getEntityTypeId()));
    if (in_array($view_mode_id, $entity_view_mode_ids)) {
      $entity = \Drupal::entityManager()->getTranslationFromContext($entity, $langcode);
      $output = $entity->get($field_name)->view($view_mode_id);
    }
    else {
      // Each part of a custom (non-Entity Display) view mode ID is separated
      // by a dash; the first part must be the module name.
      $mode_id_parts = explode('-', $view_mode_id, 2);
      $module = reset($mode_id_parts);
      $args = array($entity, $field_name, $view_mode_id, $langcode);
      $output = $this->moduleHandler()->invoke($module, 'edit_render_field', $args);
    }

    return drupal_render($output);
  }

  /**
   * Saves an entity into the database, from TempStore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being edited.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response.
   */
  public function entitySave(EntityInterface $entity) {
    // Take the entity from TempStore and save in entity storage. fieldForm()
    // ensures that the TempStore copy exists ahead.
    $tempstore = $this->tempStoreFactory->get('edit');
    $tempstore->get($entity->uuid())->save();
    $tempstore->delete($entity->uuid());

    // Return information about the entity that allows a front end application
    // to identify it.
    $output = array(
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id()
    );

    // Respond to client that the entity was saved properly.
    $response = new AjaxResponse();
    $response->addCommand(new EntitySavedCommand($output));
    return $response;
  }

}
