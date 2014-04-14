<?php

/**
 * @file
 * Definition of Drupal\node\NodeFormController.
 */

namespace Drupal\node;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityFormController;
use Drupal\Core\Language\Language;
use Drupal\Component\Utility\String;

/**
 * Form controller for the node edit forms.
 */
class NodeFormController extends ContentEntityFormController {

  /**
   * Default settings for this content/node type.
   *
   * @var array
   */
  protected $settings;

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entity;
    // Set up default values, if required.
    $type = entity_load('node_type', $node->bundle());
    $this->settings = $type->getModuleSettings('node');

    // If this is a new node, fill in the default values.
    if ($node->isNew()) {
      foreach (array('status', 'promote', 'sticky') as $key) {
        // Multistep node forms might have filled in something already.
        if ($node->$key->isEmpty()) {
          $node->$key = (int) !empty($this->settings['options'][$key]);
        }
      }
    }
    else {
      $node->date = format_date($node->getCreatedTime(), 'custom', 'Y-m-d H:i:s O');
      // Remove the log message from the original node entity.
      $node->log = NULL;
    }
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   */
  public function form(array $form, array &$form_state) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entity;

    if ($this->operation == 'edit') {
      $form['#title'] = $this->t('<em>Edit @type</em> @title', array('@type' => node_get_type_label($node), '@title' => $node->label()));
    }

    $user_config = \Drupal::config('user.settings');
    // Some special stuff when previewing a node.
    if (isset($form_state['node_preview'])) {
      $form['#prefix'] = $form_state['node_preview'];
      $node->in_preview = TRUE;
      $form['#title'] = $this->t('Preview');
    }
    else {
      unset($node->in_preview);
    }

    // Override the default CSS class name, since the user-defined node type
    // name in 'TYPE-node-form' potentially clashes with third-party class
    // names.
    $form['#attributes']['class'][0] = drupal_html_class('node-' . $node->getType() . '-form');

    // Changed must be sent to the client, for later overwrite error checking.
    $form['changed'] = array(
      '#type' => 'hidden',
      '#default_value' => $node->getChangedTime(),
    );

    $language_configuration = \Drupal::moduleHandler()->invoke('language', 'get_default_configuration', array('node', $node->getType()));
    $form['langcode'] = array(
      '#title' => t('Language'),
      '#type' => 'language_select',
      '#default_value' => $node->getUntranslated()->language()->id,
      '#languages' => Language::STATE_ALL,
      '#access' => isset($language_configuration['language_show']) && $language_configuration['language_show'],
    );

    $form['advanced'] = array(
      '#type' => 'vertical_tabs',
      '#attributes' => array('class' => array('entity-meta')),
      '#weight' => 99,
    );

    // Add a log field if the "Create new revision" option is checked, or if
    // the current user has the ability to check that option.
    $form['revision_information'] = array(
      '#type' => 'details',
      '#group' => 'advanced',
      '#title' => t('Revision information'),
      // Open by default when "Create new revision" is checked.
      '#open' => $node->isNewRevision(),
      '#attributes' => array(
        'class' => array('node-form-revision-information'),
      ),
      '#attached' => array(
        'library' => array('node/drupal.node'),
      ),
      '#weight' => 20,
      '#optional' => TRUE,
    );

    $form['revision'] = array(
      '#type' => 'checkbox',
      '#title' => t('Create new revision'),
      '#default_value' => !empty($this->settings['options']['revision']),
      '#access' => $node->isNewRevision() || user_access('administer nodes'),
      '#group' => 'revision_information',
    );

    $form['log'] = array(
      '#type' => 'textarea',
      '#title' => t('Revision log message'),
      '#rows' => 4,
      '#default_value' => !empty($node->log->value) ? $node->log->value : '',
      '#description' => t('Briefly describe the changes you have made.'),
      '#states' => array(
        'visible' => array(
          ':input[name="revision"]' => array('checked' => TRUE),
        ),
      ),
      '#group' => 'revision_information',
      '#access' => $node->isNewRevision() || user_access('administer nodes'),
    );

    // Node author information for administrators.
    $form['author'] = array(
      '#type' => 'details',
      '#title' => t('Authoring information'),
      '#group' => 'advanced',
      '#attributes' => array(
        'class' => array('node-form-author'),
      ),
      '#attached' => array(
        'library' => array('node/drupal.node'),
        'js' => array(
          array(
            'type' => 'setting',
            'data' => array('anonymous' => $user_config->get('anonymous')),
          ),
        ),
      ),
      '#weight' => 90,
      '#optional' => TRUE,
    );

    $form['uid'] = array(
      '#type' => 'textfield',
      '#title' => t('Authored by'),
      '#maxlength' => 60,
      '#autocomplete_route_name' => 'user.autocomplete',
      '#default_value' => $node->getOwnerId()? $node->getOwner()->getUsername() : '',
      '#weight' => -1,
      '#description' => t('Leave blank for %anonymous.', array('%anonymous' => $user_config->get('anonymous'))),
      '#group' => 'author',
      '#access' => user_access('administer nodes'),
    );
    $form['created'] = array(
      '#type' => 'textfield',
      '#title' => t('Authored on'),
      '#maxlength' => 25,
      '#description' => t('Format: %time. The date format is YYYY-MM-DD and %timezone is the time zone offset from UTC. Leave blank to use the time of form submission.', array('%time' => !empty($node->date) ? date_format(date_create($node->date), 'Y-m-d H:i:s O') : format_date($node->getCreatedTime(), 'custom', 'Y-m-d H:i:s O'), '%timezone' => !empty($node->date) ? date_format(date_create($node->date), 'O') : format_date($node->getCreatedTime(), 'custom', 'O'))),
      '#default_value' => !empty($node->date) ? $node->date : '',
      '#group' => 'author',
      '#access' => user_access('administer nodes'),
    );

    // Node options for administrators.
    $form['options'] = array(
      '#type' => 'details',
      '#title' => t('Promotion options'),
      '#group' => 'advanced',
      '#attributes' => array(
        'class' => array('node-form-options'),
      ),
      '#attached' => array(
        'library' => array('node/drupal.node'),
      ),
      '#weight' => 95,
      '#optional' => TRUE,
    );

    $form['promote'] = array(
      '#type' => 'checkbox',
      '#title' => t('Promoted to front page'),
      '#default_value' => $node->isPromoted(),
      '#group' => 'options',
      '#access' => user_access('administer nodes'),
    );

    $form['sticky'] = array(
      '#type' => 'checkbox',
      '#title' => t('Sticky at top of lists'),
      '#default_value' => $node->isSticky(),
      '#group' => 'options',
      '#access' => user_access('administer nodes'),
    );

    return parent::form($form, $form_state, $node);
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::actions().
   */
  protected function actions(array $form, array &$form_state) {
    $element = parent::actions($form, $form_state);
    $node = $this->entity;
    $preview_mode = $this->settings['preview'];

    $element['submit']['#access'] = $preview_mode != DRUPAL_REQUIRED || (!form_get_errors($form_state) && isset($form_state['node_preview']));

    // If saving is an option, privileged users get dedicated form submit
    // buttons to adjust the publishing status while saving in one go.
    // @todo This adjustment makes it close to impossible for contributed
    //   modules to integrate with "the Save operation" of this form. Modules
    //   need a way to plug themselves into 1) the ::submit() step, and
    //   2) the ::save() step, both decoupled from the pressed form button.
    if ($element['submit']['#access'] && user_access('administer nodes')) {
      // isNew | prev status » default   & publish label             & unpublish label
      // 1     | 1           » publish   & Save and publish          & Save as unpublished
      // 1     | 0           » unpublish & Save and publish          & Save as unpublished
      // 0     | 1           » publish   & Save and keep published   & Save and unpublish
      // 0     | 0           » unpublish & Save and keep unpublished & Save and publish

      // Add a "Publish" button.
      $element['publish'] = $element['submit'];
      $element['publish']['#dropbutton'] = 'save';
      if ($node->isNew()) {
        $element['publish']['#value'] = t('Save and publish');
      }
      else {
        $element['publish']['#value'] = $node->isPublished() ? t('Save and keep published') : t('Save and publish');
      }
      $element['publish']['#weight'] = 0;
      array_unshift($element['publish']['#submit'], array($this, 'publish'));

      // Add a "Unpublish" button.
      $element['unpublish'] = $element['submit'];
      $element['unpublish']['#dropbutton'] = 'save';
      if ($node->isNew()) {
        $element['unpublish']['#value'] = t('Save as unpublished');
      }
      else {
        $element['unpublish']['#value'] = !$node->isPublished() ? t('Save and keep unpublished') : t('Save and unpublish');
      }
      $element['unpublish']['#weight'] = 10;
      array_unshift($element['unpublish']['#submit'], array($this, 'unpublish'));

      // If already published, the 'publish' button is primary.
      if ($node->isPublished()) {
        unset($element['unpublish']['#button_type']);
      }
      // Otherwise, the 'unpublish' button is primary and should come first.
      else {
        unset($element['publish']['#button_type']);
        $element['unpublish']['#weight'] = -10;
      }

      // Remove the "Save" button.
      $element['submit']['#access'] = FALSE;
    }

    $element['preview'] = array(
      '#access' => $preview_mode != DRUPAL_DISABLED && ($node->access('create') || $node->access('update')),
      '#value' => t('Preview'),
      '#weight' => 20,
      '#validate' => array(
        array($this, 'validate'),
      ),
      '#submit' => array(
        array($this, 'submit'),
        array($this, 'preview'),
      ),
    );

    $element['delete']['#access'] = $node->access('delete');
    $element['delete']['#weight'] = 100;

    return $element;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::validate().
   */
  public function validate(array $form, array &$form_state) {
    $node = $this->buildEntity($form, $form_state);

    if ($node->id() && (node_last_changed($node->id(), $this->getFormLangcode($form_state)) > $node->getChangedTime())) {
      $this->setFormError('changed', $form_state, $this->t('The content on this page has either been modified by another user, or you have already submitted modifications using this form. As a result, your changes cannot be saved.'));
    }

    // Validate the "authored by" field.
    if (!empty($form_state['values']['uid']) && !($account = user_load_by_name($form_state['values']['uid']))) {
      // The use of empty() is mandatory in the context of usernames
      // as the empty string denotes the anonymous user. In case we
      // are dealing with an anonymous user we set the user ID to 0.
      $this->setFormError('uid', $form_state, $this->t('The username %name does not exist.', array('%name' => $form_state['values']['uid'])));
    }

    // Validate the "authored on" field.
    // The date element contains the date object.
    $date = $node->date instanceof DrupalDateTime ? $node->date : new DrupalDateTime($node->date);
    if ($date->hasErrors()) {
      $this->setFormError('date', $form_state, $this->t('You have to specify a valid date.'));
    }

    // Invoke HOOK_node_validate() for validation needed by modules.
    // Can't use \Drupal::moduleHandler()->invokeAll(), because $form_state must
    // be receivable by reference.
    foreach (\Drupal::moduleHandler()->getImplementations('node_validate') as $module) {
      $function = $module . '_node_validate';
      $function($node, $form, $form_state);
    }

    parent::validate($form, $form_state);
  }

  /**
   * Updates the node object by processing the submitted values.
   *
   * This function can be called by a "Next" button of a wizard to update the
   * form state's entity with the current step's values before proceeding to the
   * next step.
   *
   * Overrides Drupal\Core\Entity\EntityFormController::submit().
   */
  public function submit(array $form, array &$form_state) {
    // Build the node object from the submitted values.
    $node = parent::submit($form, $form_state);

    // Save as a new revision if requested to do so.
    if (!empty($form_state['values']['revision']) && $form_state['values']['revision'] != FALSE) {
      $node->setNewRevision();
      // If a new revision is created, save the current user as revision author.
      $node->setRevisionCreationTime(REQUEST_TIME);
      $node->setRevisionAuthorId(\Drupal::currentUser()->id());
    }
    else {
      $node->setNewRevision(FALSE);
    }

    $node->validated = TRUE;
    foreach (\Drupal::moduleHandler()->getImplementations('node_submit') as $module) {
      $function = $module . '_node_submit';
      $function($node, $form, $form_state);
    }

    return $node;
  }

  /**
   * Form submission handler for the 'preview' action.
   *
   * @param $form
   *   An associative array containing the structure of the form.
   * @param $form_state
   *   A reference to a keyed array containing the current state of the form.
   */
  public function preview(array $form, array &$form_state) {
    // @todo Remove this: we should not have explicit includes in autoloaded
    //   classes.
    module_load_include('inc', 'node', 'node.pages');
    $form_state['node_preview'] = node_preview($this->entity, $form_state);
    $form_state['rebuild'] = TRUE;
  }

  /**
   * Form submission handler for the 'publish' action.
   *
   * @param $form
   *   An associative array containing the structure of the form.
   * @param $form_state
   *   A reference to a keyed array containing the current state of the form.
   */
  public function publish(array $form, array &$form_state) {
    $node = $this->entity;
    $node->setPublished(TRUE);
    return $node;
  }

  /**
   * Form submission handler for the 'unpublish' action.
   *
   * @param $form
   *   An associative array containing the structure of the form.
   * @param $form_state
   *   A reference to a keyed array containing the current state of the form.
   */
  public function unpublish(array $form, array &$form_state) {
    $node = $this->entity;
    $node->setPublished(FALSE);
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, array &$form_state) {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = parent::buildEntity($form, $form_state);
    // A user might assign the node author by entering a user name in the node
    // form, which we then need to translate to a user ID.
    if (!empty($form_state['values']['uid']) && $account = user_load_by_name($form_state['values']['uid'])) {
      $entity->setOwnerId($account->id());
    }
    else {
      $entity->setOwnerId(0);
    }

    if (!empty($form_state['values']['created']) && $form_state['values']['created'] instanceOf DrupalDateTime) {
      $entity->setCreatedTime($form_state['values']['created']->getTimestamp());
    }
    else {
      $entity->setCreatedTime(REQUEST_TIME);
    }
    return $entity;
  }


  /**
   * Overrides Drupal\Core\Entity\EntityFormController::save().
   */
  public function save(array $form, array &$form_state) {
    $node = $this->entity;
    $insert = $node->isNew();
    $node->save();
    $node_link = l(t('View'), 'node/' . $node->id());
    $watchdog_args = array('@type' => $node->getType(), '%title' => $node->label());
    $t_args = array('@type' => node_get_type_label($node), '%title' => $node->label());

    if ($insert) {
      watchdog('content', '@type: added %title.', $watchdog_args, WATCHDOG_NOTICE, $node_link);
      drupal_set_message(t('@type %title has been created.', $t_args));
    }
    else {
      watchdog('content', '@type: updated %title.', $watchdog_args, WATCHDOG_NOTICE, $node_link);
      drupal_set_message(t('@type %title has been updated.', $t_args));
    }

    if ($node->id()) {
      $form_state['values']['nid'] = $node->id();
      $form_state['nid'] = $node->id();
      if ($node->access('view')) {
        $form_state['redirect_route'] = array(
          'route_name' => 'node.view',
          'route_parameters' => array(
            'node' => $node->id(),
          ),
        );
      }
      else {
        $form_state['redirect_route']['route_name'] = '<front>';
      }
    }
    else {
      // In the unlikely case something went wrong on save, the node will be
      // rebuilt and node form redisplayed the same way as in preview.
      drupal_set_message(t('The post could not be saved.'), 'error');
      $form_state['rebuild'] = TRUE;
    }

    // Clear the page and block caches.
    Cache::invalidateTags(array('content' => TRUE));
  }

}
