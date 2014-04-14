<?php

/**
 * @file
 * Contains \Drupal\system\Form\ModulesListForm.
 */

namespace Drupal\system\Form;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Entity\Query\QueryFactoryInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Render\Element;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessManager;

/**
 * Provides module installation interface.
 *
 * The list of modules gets populated by module.info.yml files, which contain
 * each module's name, description, and information about which modules it
 * requires. See \Drupal\Core\Extension\InfoParser for info on module.info.yml
 * descriptors.
 */
class ModulesListForm extends FormBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The expirable key value store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $queryFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('keyvalue.expirable')->get('module_list'),
      $container->get('access_manager'),
      $container->get('entity.manager'),
      $container->get('entity.query')
    );
  }

  /**
   * Constructs a ModulesListForm object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value_expirable
   *   The key value expirable factory.
   * @param \Drupal\Core\Access\AccessManager $access_manager
   *   Access manager.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Entity\Query\QueryFactory $query_factory
   *   The entity query factory.
   */
  public function __construct(ModuleHandlerInterface $module_handler, KeyValueStoreExpirableInterface $key_value_expirable, AccessManager $access_manager, EntityManagerInterface $entity_manager, QueryFactory $query_factory) {
    $this->moduleHandler = $module_handler;
    $this->keyValueExpirable = $key_value_expirable;
    $this->accessManager = $access_manager;
    $this->entityManager = $entity_manager;
    $this->queryFactory = $query_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'system_modules';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    $distribution = check_plain(drupal_install_profile_distribution_name());

    // Include system.admin.inc so we can use the sort callbacks.
    $this->moduleHandler->loadInclude('system', 'inc', 'system.admin');

    $form['filters'] = array(
      '#type' => 'container',
      '#attributes' => array(
        'class' => array('table-filter', 'js-show'),
      ),
    );

    $form['filters']['text'] = array(
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#size' => 30,
      '#placeholder' => $this->t('Enter module name'),
      '#attributes' => array(
        'class' => array('table-filter-text'),
        'data-table' => '#system-modules',
        'autocomplete' => 'off',
        'title' => $this->t('Enter a part of the module name or description to filter by.'),
      ),
    );

    // Sort all modules by their names.
    $modules = system_rebuild_module_data();
    uasort($modules, 'system_sort_modules_by_info_name');

    // Iterate over each of the modules.
    $form['modules']['#tree'] = TRUE;
    foreach ($modules as $filename => $module) {
      if (empty($module->info['hidden'])) {
        $package = $module->info['package'];
        $form['modules'][$package][$filename] = $this->buildRow($modules, $module, $distribution);
      }
    }

    // Add a wrapper around every package.
    foreach (Element::children($form['modules']) as $package) {
      $form['modules'][$package] += array(
        '#type' => 'details',
        '#title' => $this->t($package),
        '#open' => TRUE,
        '#theme' => 'system_modules_details',
        '#header' => array(
          array('data' => '<span class="visually-hidden">' . $this->t('Installed') . '</span>', 'class' => array('checkbox')),
          array('data' => $this->t('Name'), 'class' => array('name')),
          array('data' => $this->t('Description'), 'class' => array('description', RESPONSIVE_PRIORITY_LOW)),
        ),
        '#attributes' => array('class' => array('package-listing')),
        // Ensure that the "Core" package comes first.
        '#weight' => $package == 'Core' ? -10 : NULL,
      );
    }

    // Lastly, sort all packages by title.
    uasort($form['modules'], array('\Drupal\Component\Utility\SortArray', 'sortByTitleProperty'));

    $form['#attached']['library'][] = 'system/drupal.system.modules';
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    );

    return $form;
  }

  /**
   * Builds a table row for the system modules page.
   *
   * @param array $modules
   *   The list existing modules.
   * @param \Drupal\Core\Extension\Extension $module
   *   The module for which to build the form row.
   * @param $distribution
   *
   * @return array
   *   The form row for the given module.
   */
  protected function buildRow(array $modules, Extension $module, $distribution) {
    // Set the basic properties.
    $row['#required'] = array();
    $row['#requires'] = array();
    $row['#required_by'] = array();

    $row['name']['#markup'] = $module->info['name'];
    $row['description']['#markup'] = $this->t($module->info['description']);
    $row['version']['#markup'] = $module->info['version'];

    // Add links for each module.
    // Used when checking if a module implements a help page.
    $help = $this->moduleHandler->moduleExists('help') ? drupal_help_arg() : FALSE;

    // Generate link for module's help page, if there is one.
    $row['links']['help'] = array();
    if ($help && $module->status && in_array($module->getName(), $this->moduleHandler->getImplementations('help'))) {
      if ($this->moduleHandler->invoke($module->getName(), 'help', array('admin/help#' . $module->getName(), $help))) {
        $row['links']['help'] = array(
          '#type' => 'link',
          '#title' => $this->t('Help'),
          '#href' => 'admin/help/' . $module->getName(),
          '#options' => array('attributes' => array('class' =>  array('module-link', 'module-link-help'), 'title' => $this->t('Help'))),
        );
      }
    }

    // Generate link for module's permission, if the user has access to it.
    $row['links']['permissions'] = array();
    if ($module->status && user_access('administer permissions') && in_array($module->getName(), $this->moduleHandler->getImplementations('permission'))) {
      $row['links']['permissions'] = array(
        '#type' => 'link',
        '#title' => $this->t('Permissions'),
        '#href' => 'admin/people/permissions',
        '#options' => array('fragment' => 'module-' . $module->getName(), 'attributes' => array('class' => array('module-link', 'module-link-permissions'), 'title' => $this->t('Configure permissions'))),
      );
    }

    // Generate link for module's configuration page, if it has one.
    $row['links']['configure'] = array();
    if ($module->status && isset($module->info['configure'])) {
      if ($this->accessManager->checkNamedRoute($module->info['configure'], array(), \Drupal::currentUser())) {
        $result = $this->queryFactory->get('menu_link')
          ->condition('route_name', $module->info['configure'])
          ->execute();
        $menu_items = $this->entityManager->getStorage('menu_link')->loadMultiple($result);
        $item = reset($menu_items);
        $row['links']['configure'] = array(
          '#type' => 'link',
          '#title' => $this->t('Configure'),
          '#route_name' => $module->info['configure'],
          '#options' => array(
            'attributes' => array(
              'class' => array('module-link', 'module-link-configure'),
              'title' => $item['description'],
            ),
          ),
        );
      }
    }

    // Present a checkbox for installing and indicating the status of a module.
    $row['enable'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Install'),
      '#default_value' => (bool) $module->status,
      '#disabled' => (bool) $module->status,
    );

    // Disable the checkbox for required modules.
    if (!empty($module->info['required'])) {
      // Used when displaying modules that are required by the installation profile
      $row['enable']['#disabled'] = TRUE;
      $row['#required_by'][] = $distribution . (!empty($module->info['explanation']) ? ' ('. $module->info['explanation'] .')' : '');
    }

    // Check the compatibilities.
    $compatible = TRUE;

    // Initialize an empty array of reasons why the module is incompatible. Add
    // each reason as a separate element of the array.
    $reasons = array();

    // Check the core compatibility.
    if ($module->info['core'] != \Drupal::CORE_COMPATIBILITY) {
      $compatible = FALSE;
      $reasons[] = $this->t('This version is not compatible with Drupal !core_version and should be replaced.', array(
        '!core_version' => \Drupal::CORE_COMPATIBILITY,
      ));
    }

    // Ensure this module is compatible with the currently installed version of PHP.
    if (version_compare(phpversion(), $module->info['php']) < 0) {
      $compatible = FALSE;
      $required = $module->info['php'] . (substr_count($module->info['php'], '.') < 2 ? '.*' : '');
      $reasons[] = $this->t('This module requires PHP version @php_required and is incompatible with PHP version !php_version.', array(
        '@php_required' => $required,
        '!php_version' => phpversion(),
      ));
    }

    // If this module is not compatible, disable the checkbox.
    if (!$compatible) {
      $status = implode(' ', $reasons);
      $row['enable']['#disabled'] = TRUE;
      $row['description']['#markup'] = $status;
      $row['#attributes']['class'][] = 'incompatible';
    }

    // If this module requires other modules, add them to the array.
    foreach ($module->requires as $dependency => $version) {
      if (!isset($modules[$dependency])) {
        $row['#requires'][$dependency] = $this->t('@module (<span class="admin-missing">missing</span>)', array('@module' => Unicode::ucfirst($dependency)));
        $row['enable']['#disabled'] = TRUE;
      }
      // Only display visible modules.
      elseif (empty($modules[$dependency]->hidden)) {
        $name = $modules[$dependency]->info['name'];
        // Disable the module's checkbox if it is incompatible with the
        // dependency's version.
        if ($incompatible_version = drupal_check_incompatibility($version, str_replace(\Drupal::CORE_COMPATIBILITY . '-', '', $modules[$dependency]->info['version']))) {
          $row['#requires'][$dependency] = $this->t('@module (<span class="admin-missing">incompatible with</span> version @version)', array(
            '@module' => $name . $incompatible_version,
            '@version' => $modules[$dependency]->info['version'],
          ));
          $row['enable']['#disabled'] = TRUE;
        }
        // Disable the checkbox if the dependency is incompatible with this
        // version of Drupal core.
        elseif ($modules[$dependency]->info['core'] != \Drupal::CORE_COMPATIBILITY) {
          $row['#requires'][$dependency] = $this->t('@module (<span class="admin-missing">incompatible with</span> this version of Drupal core)', array(
            '@module' => $name,
          ));
          $row['enable']['#disabled'] = TRUE;
        }
        elseif ($modules[$dependency]->status) {
          $row['#requires'][$dependency] = $this->t('@module', array('@module' => $name));
        }
        else {
          $row['#requires'][$dependency] = $this->t('@module (<span class="admin-disabled">disabled</span>)', array('@module' => $name));
        }
      }
    }

    // If this module is required by other modules, list those, and then make it
    // impossible to disable this one.
    foreach ($module->required_by as $dependent => $version) {
      if (isset($modules[$dependent]) && empty($modules[$dependent]->info['hidden'])) {
        if ($modules[$dependent]->status == 1 && $module->status == 1) {
          $row['#required_by'][$dependent] = $this->t('@module', array('@module' => $modules[$dependent]->info['name']));
          $row['enable']['#disabled'] = TRUE;
        }
        else {
          $row['#required_by'][$dependent] = $this->t('@module (<span class="admin-disabled">disabled</span>)', array('@module' => $modules[$dependent]->info['name']));
        }
      }
    }

    return $row;
  }

  /**
   * Helper function for building a list of modules to install.
   *
   * @param array $form_state
   *   The form state array.
   *
   * @return array
   *   An array of modules to install and their dependencies.
   */
  protected function buildModuleList(array $form_state) {
    $packages = $form_state['values']['modules'];

    // Build a list of modules to install.
    $modules = array(
      'install' => array(),
      'dependencies' => array(),
    );

    // Required modules have to be installed.
    // @todo This should really not be handled here.
    $data = system_rebuild_module_data();
    foreach ($data as $name => $module) {
      if (!empty($module->required) && !$this->moduleHandler->moduleExists($name)) {
        $modules['install'][$name] = $module->info['name'];
      }
    }

    // First, build a list of all modules that were selected.
    foreach ($packages as $items) {
      foreach ($items as $name => $checkbox) {
        if ($checkbox['enable'] && !$this->moduleHandler->moduleExists($name)) {
          $modules['install'][$name] = $data[$name]->info['name'];
        }
      }
    }

    // Add all dependencies to a list.
    while (list($module) = each($modules['install'])) {
      foreach (array_keys($data[$module]->requires) as $dependency) {
        if (!isset($modules['install'][$dependency]) && !$this->moduleHandler->moduleExists($dependency)) {
          $modules['dependencies'][$module][$dependency] = $data[$dependency]->info['name'];
          $modules['install'][$dependency] = $data[$dependency]->info['name'];
        }
      }
    }

    // Make sure the install API is available.
    include_once DRUPAL_ROOT . '/core/includes/install.inc';

    // Invoke HOOK_requirements('install'). If failures are detected, make
    // sure the dependent modules aren't installed either.
    foreach (array_keys($modules['install']) as $module) {
      if (!drupal_check_module($module)) {
        unset($modules['install'][$module]);
        foreach (array_keys($data[$module]->required_by) as $dependent) {
          unset($modules['install'][$dependent]);
          unset($modules['dependencies'][$dependent]);
        }
      }
    }

    return $modules;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    // Retrieve a list of modules to install and their dependencies.
    $modules = $this->buildModuleList($form_state);

    // Check if we have to install any dependencies. If there is one or more
    // dependencies that are not installed yet, redirect to the confirmation
    // form.
    if (!empty($modules['dependencies']) || !empty($modules['missing'])) {
      // Write the list of changed module states into a key value store.
      $account = $this->currentUser()->id();
      $this->keyValueExpirable->setWithExpire($account, $modules, 60);

      // Redirect to the confirmation form.
      $form_state['redirect_route']['route_name'] = 'system.modules_list_confirm';

      // We can exit here because at least one modules has dependencies
      // which we have to prompt the user for in a confirmation form.
      return;
    }

    // Gets list of modules prior to install process.
    $before = $this->moduleHandler->getModuleList();

    // There seem to be no dependencies that would need approval.
    if (!empty($modules['install'])) {
      $this->moduleHandler->install(array_keys($modules['install']));
    }

    // Gets module list after install process, flushes caches and displays a
    // message if there are changes.
    if ($before != $this->moduleHandler->getModuleList()) {
      drupal_flush_all_caches();
      drupal_set_message(t('The configuration options have been saved.'));
    }
  }

}
