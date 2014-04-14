<?php

/**
 * @file
 * Hooks provided by the toolbar module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Add items to the toolbar menu.
 *
 * The toolbar is a container for administrative and site-global interactive
 * components.
 *
 * The toolbar provides a common styling for items denoted by the
 * .toolbar-tab class.
 *
 * The toolbar provides a construct called a 'tray'. The tray is a container
 * for content. The tray may be associated with a toggle in the administration
 * bar. The toggle shows or hides the tray and is optimized for small and
 * large screens. To create this association, HOOK_toolbar() returns one or
 * more render elements of type 'toolbar_item', containing the toggle and tray
 * elements in its 'tab' and 'tray' properties.
 *
 * The following properties are available:
 *   - 'tab': A renderable array.
 *   - 'tray': Optional. A renderable array.
 *   - '#weight': Optional. Integer weight used for sorting toolbar items in
 *     administration bar area.
 *
 * This hook is invoked in toolbar_pre_render().
 *
 * @return
 *   An array of toolbar items, keyed by unique identifiers such as 'home' or
 *   'administration', or the short name of the module implementing the hook.
 *   The corresponding value is a render element of type 'toolbar_item'.
 *
 * @see toolbar_pre_render()
 * @ingroup toolbar_tabs
 */
function HOOK_toolbar() {
  $items = array();

  // Add a search field to the toolbar. The search field employs no toolbar
  // module theming functions.
  $items['global_search'] = array(
    '#type' => 'toolbar_item',
    'tab' => array(
      '#type' => 'search',
      '#attributes' => array(
        'placeholder' => t('Search the site'),
        'class' => array('search-global'),
      ),
    ),
    '#weight' => 200,
    // Custom CSS, JS or a library can be associated with the toolbar item.
    '#attached' => array(
      'css' => array(
        drupal_get_path('module', 'search') . '/css/search.base.css',
      ),
    ),
  );

  // The 'Home' tab is a simple link, which is wrapped in markup associated
  // with a visual tab styling.
  $items['home'] = array(
    '#type' => 'toolbar_item',
    'tab' => array(
      '#type' => 'link',
      '#title' => t('Home'),
      '#href' => '<front>',
      '#options' => array(
        'attributes' => array(
          'title' => t('Home page'),
          'class' => array('toolbar-icon', 'toolbar-icon-home'),
        ),
      ),
    ),
    '#weight' => -20,
  );

  // A tray may be associated with a tab.
  //
  // When the tab is activated, the tray will become visible, either in a
  // horizontal or vertical orientation on the screen.
  //
  // The tray should contain a renderable array. An optional #heading property
  // can be passed. This text is written to a heading tag in the tray as a
  // landmark for accessibility.
  $items['commerce'] = array(
    '#type' => 'toolbar_item',
    'tab' => array(
      '#type' => 'link',
      '#title' => t('Shopping cart'),
      '#href' => '/cart',
      '#options' => array(
        'html' => FALSE,
        'attributes' => array(
          'title' => t('Shopping cart'),
        ),
      ),
    ),
    'tray' => array(
      '#heading' => t('Shopping cart actions'),
      'shopping_cart' => array(
        '#theme' => 'item_list',
        '#items' => array( /* An item list renderable array */ ),
      ),
    ),
    '#weight' => 150,
  );

  // The tray can be used to render arbritrary content.
  //
  // A renderable array passed to the 'tray' property will be rendered outside
  // the administration bar but within the containing toolbar element.
  //
  // If the default behavior and styling of a toolbar tray is not desired, one
  // can render content to the toolbar element and apply custom theming and
  // behaviors.
  $items['user_messages'] = array(
    // Include the toolbar_tab_wrapper to style the link like a toolbar tab.
    // Exclude the theme wrapper if custom styling is desired.
    '#type' => 'toolbar_item',
    'tab' => array(
      '#type' => 'link',
      '#theme' => 'user_message_toolbar_tab',
      '#theme_wrappers' => array(),
      '#title' => t('Messages'),
      '#href' => '/user/messages',
      '#options' => array(
        'attributes' => array(
          'title' => t('Messages'),
        ),
      ),
    ),
    'tray' => array(
      '#heading' => t('User messages'),
      'messages' => array(/* renderable content */),
    ),
    '#weight' => 125,
  );

  return $items;
}

/**
 * Alter the toolbar menu after HOOK_toolbar() is invoked.
 *
 * This hook is invoked by toolbar_view() immediately after HOOK_toolbar(). The
 * toolbar definitions are passed in by reference. Each element of the $items
 * array is one item returned by a module from HOOK_toolbar(). Additional items
 * may be added, or existing items altered.
 *
 * @param $items
 *   Associative array of toolbar menu definitions returned from HOOK_toolbar().
 */
function HOOK_toolbar_alter(&$items) {
  // Move the User tab to the right.
  $items['commerce']['#weight'] = 5;
}

/**
 * @} End of "addtogroup hooks".
 */
