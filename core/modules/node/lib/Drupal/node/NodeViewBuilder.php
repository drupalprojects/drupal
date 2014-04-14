<?php

/**
 * @file
 * Definition of Drupal\node\NodeViewBuilder.
 */

namespace Drupal\node;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;

/**
 * Render controller for nodes.
 */
class NodeViewBuilder extends EntityViewBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildContent(array $entities, array $displays, $view_mode, $langcode = NULL) {
    $return = array();
    if (empty($entities)) {
      return $return;
    }

    // Attach user account.
    user_attach_accounts($entities);

    parent::buildContent($entities, $displays, $view_mode, $langcode);

    foreach ($entities as $entity) {
      $bundle = $entity->bundle();
      $display = $displays[$bundle];

      $entity->content['links'] = array(
        '#type' => 'render_cache_placeholder',
        '#callback' => '\Drupal\node\NodeViewBuilder::renderLinks',
        '#context' => array(
          'node_entity_id' => $entity->id(),
          'view_mode' => $view_mode,
          'langcode' => $langcode,
          'in_preview' => !empty($entity->in_preview),
        ),
      );

      // Add Language field text element to node render array.
      if ($display->getComponent('langcode')) {
        $entity->content['langcode'] = array(
          '#type' => 'item',
          '#title' => t('Language'),
          '#markup' => $entity->language()->name,
          '#prefix' => '<div id="field-language-display">',
          '#suffix' => '</div>'
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode, $langcode) {
    $defaults = parent::getBuildDefaults($entity, $view_mode, $langcode);

    // Don't cache nodes that are in 'preview' mode.
    if (isset($defaults['#cache']) && isset($entity->in_preview)) {
      unset($defaults['#cache']);
    }

    return $defaults;
  }

  /**
   * #post_render_cache callback; replaces the placeholder with node links.
   *
   * Renders the links on a node.
   *
   * @param array $context
   *   An array with the following keys:
   *   - node_entity_id: a node entity ID
   *   - view_mode: the view mode in which the node entity is being viewed
   *   - langcode: in which language the node entity is being viewed
   *   - in_preview: whether the node is currently being previewed
   *
   * @return array
   *   A renderable array representing the node links.
   */
  public static function renderLinks(array $context) {
    $links = array(
      '#theme' => 'links__node',
      '#pre_render' => array('drupal_pre_render_links'),
      '#attributes' => array('class' => array('links', 'inline')),
    );

    if (!$context['in_preview']) {
      $entity = entity_load('node', $context['node_entity_id'])->getTranslation($context['langcode']);
      $links['node'] = self::buildLinks($entity, $context['view_mode']);

      // Allow other modules to alter the node links.
      $HOOK_context = array(
        'view_mode' => $context['view_mode'],
        'langcode' => $context['langcode'],
      );
      \Drupal::moduleHandler()->alter('node_links', $links, $entity, $HOOK_context);
    }

    return $links;
  }

  /**
   * Build the default links (Read more) for a node.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The node object.
   * @param string $view_mode
   *   A view mode identifier.
   *
   * @return array
   *   An array that can be processed by drupal_pre_render_links().
   */
  protected static function buildLinks(NodeInterface $entity, $view_mode) {
    $links = array();

    // Always display a read more link on teasers because we have no way
    // to know when a teaser view is different than a full view.
    if ($view_mode == 'teaser') {
      $node_title_stripped = strip_tags($entity->label());
      $links['node-readmore'] = array(
        'title' => t('Read more<span class="visually-hidden"> about @title</span>', array(
          '@title' => $node_title_stripped,
        )),
        'href' => 'node/' . $entity->id(),
        'language' => $entity->language(),
        'html' => TRUE,
        'attributes' => array(
          'rel' => 'tag',
          'title' => $node_title_stripped,
        ),
      );
    }

    return array(
      '#theme' => 'links__node__node',
      '#links' => $links,
      '#attributes' => array('class' => array('links', 'inline')),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function alterBuild(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode, $langcode = NULL) {
    /** @var \Drupal\node\NodeInterface $entity */
    parent::alterBuild($build, $entity, $display, $view_mode, $langcode);
    if ($entity->id()) {
      $build['#contextual_links']['node'] = array(
        'route_parameters' =>array('node' => $entity->id()),
        'metadata' => array('changed' => $entity->getChangedTime()),
      );
    }

    // The node 'submitted' info is not rendered in a standard way (renderable
    // array) so we have to add a cache tag manually.
    $build['#cache']['tags']['user'][] = $entity->getOwnerId();
  }

}
