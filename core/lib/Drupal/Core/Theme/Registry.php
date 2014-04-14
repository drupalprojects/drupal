<?php

/**
 * @file
 * Contains \Drupal\Core\Theme\Registry.
 */

namespace Drupal\Core\Theme;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Utility\ThemeRegistry;

/**
 * Defines the theme registry service.
 *
 * @todo Replace local $registry variables in methods with $this->registry.
 */
class Registry implements DestructableInterface {

  /**
   * The theme object representing the active theme for this registry.
   *
   * @var object
   */
  protected $theme;

  /**
   * An array of base theme objects.
   *
   * @var array
   */
  protected $baseThemes;

  /**
   * The name of the theme engine of $theme.
   *
   * @var string
   */
  protected $engine;

  /**
   * The lock backend that should be used.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The complete theme registry.
   *
   * @var array
   *   An associative array keyed by theme hook names, whose values are
   *   associative arrays containing the aggregated hook definition:
   *   - type: The type of the extension the original theme hook originates
   *     from; e.g., 'module' for theme hook 'node' of Node module.
   *   - name: The name of the extension the original theme hook originates
   *     from; e.g., 'node' for theme hook 'node' of Node module.
   *   - theme path: The effective path_to_theme() during _theme(), available as
   *     'directory' variable in templates.
   *       functions, it should point to the respective theme. For templates,
   *       it should point to the directory that contains the template.
   *   - includes: (optional) An array of include files to load when the theme
   *     hook is executed by _theme().
   *   - file: (optional) A filename to add to 'includes', either prefixed with
   *     the value of 'path', or the path of the extension implementing
   *     HOOK_theme().
   *   In case of a theme base hook, one of the following:
   *   - variables: An associative array whose keys are variable names and whose
   *     values are default values of the variables to use for this theme hook.
   *   - render element: A string denoting the name of the variable name, in
   *     which the render element for this theme hook is provided.
   *   In case of a theme template file:
   *   - path: The path to the template file to use. Defaults to the
   *     subdirectory 'templates' of the path of the extension implementing
   *     HOOK_theme(); e.g., 'core/modules/node/templates' for Node module.
   *   - template: The basename of the template file to use, without extension
   *     (as the extension is specific to the theme engine). The template file
   *     is in the directory defined by 'path'.
   *   - template_file: A full path and file name to a template file to use.
   *     Allows any extension to override the effective template file.
   *   - engine: The theme engine to use for the template file.
   *   In case of a theme function:
   *   - function: The function name to call to generate the output.
   *   For any registered theme hook, including theme hook suggestions:
   *   - preprocess: An array of theme variable preprocess callbacks to invoke
   *     before invoking final theme variable processors.
   *   - process: An array of theme variable process callbacks to invoke
   *     before invoking the actual theme function or template.
   */
  protected $registry;

  /**
   * The cache backend to use for the complete theme registry data.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The module handler to use to load modules.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The incomplete, runtime theme registry.
   *
   * @var \Drupal\Core\Utility\ThemeRegistry
   */
  protected $runtimeRegistry;

  /**
   * Constructs a \Drupal\Core\\Theme\Registry object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend interface to use for the complete theme registry data.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to use to load modules.
   * @param string $theme_name
   *   (optional) The name of the theme for which to construct the registry.
   */
  public function __construct(CacheBackendInterface $cache, LockBackendInterface $lock, ModuleHandlerInterface $module_handler, $theme_name = NULL) {
    $this->cache = $cache;
    $this->lock = $lock;
    $this->moduleHandler = $module_handler;
    $this->init($theme_name);
  }

  /**
   * Initializes a theme with a certain name.
   *
   * This function does to much magic, so it should be replaced by another
   * services which holds the current active theme information.
   *
   * @param string $theme_name
   *   (optional) The name of the theme for which to construct the registry.+
   */
  protected function init($theme_name = NULL) {
    // Unless instantiated for a specific theme, use globals.
    if (!isset($theme_name)) {
      // #1: The theme registry might get instantiated before the theme was
      // initialized. Cope with that.
      if (!isset($GLOBALS['theme_info']) || !isset($GLOBALS['theme'])) {
        unset($this->runtimeRegistry);
        unset($this->registry);
        drupal_theme_initialize();
      }
      // #2: The testing framework only cares for the global $theme variable at
      // this point. Cope with that.
      if ($GLOBALS['theme'] != $GLOBALS['theme_info']->getName()) {
        unset($this->runtimeRegistry);
        unset($this->registry);
        $this->initializeTheme();
      }
      $this->theme = $GLOBALS['theme_info'];
      $this->baseThemes = $GLOBALS['base_theme_info'];
      $this->engine = $GLOBALS['theme_engine'];
    }
    // Instead of the global theme, a specific theme was requested.
    else {
      // @see drupal_theme_initialize()
      $themes = $this->listThemes();
      $this->theme = $themes[$theme_name];

      // Find all base themes.
      $this->baseThemes = array();
      $ancestor = $theme_name;
      while ($ancestor && isset($themes[$ancestor]->base_theme)) {
        $ancestor = $themes[$ancestor]->base_theme;
        $this->baseThemes[] = $themes[$ancestor];
        if (!empty($themes[$ancestor]->owner)) {
          include_once DRUPAL_ROOT . '/' . $themes[$ancestor]->owner;
        }
      }
      $this->baseThemes = array_reverse($this->baseThemes);

      // @see _drupal_theme_initialize()
      if (isset($this->theme->engine)) {
        $this->engine = $this->theme->engine;
        include_once DRUPAL_ROOT . '/' . $this->theme->owner;
        if (function_exists($this->theme->engine . '_init')) {
          foreach ($this->baseThemes as $base) {
            call_user_func($this->theme->engine . '_init', $base);
          }
          call_user_func($this->theme->engine . '_init', $this->theme);
        }
      }
    }
  }

  /**
   * Returns the complete theme registry from cache or rebuilds it.
   *
   * @return array
   *   The complete theme registry data array.
   *
   * @see Registry::$registry
   */
  public function get() {
    if (isset($this->registry)) {
      return $this->registry;
    }
    if ($cache = $this->cache->get('theme_registry:' . $this->theme->getName())) {
      $this->registry = $cache->data;
    }
    else {
      $this->registry = $this->build();
      // Only persist it if all modules are loaded to ensure it is complete.
      if ($this->moduleHandler->isLoaded()) {
        $this->setCache();
      }
    }
    return $this->registry;
  }

  /**
   * Returns the incomplete, runtime theme registry.
   *
   * @return \Drupal\Core\Utility\ThemeRegistry
   *   A shared instance of the ThemeRegistry class, provides an ArrayObject
   *   that allows it to be accessed with array syntax and isset(), and is more
   *   lightweight than the full registry.
   */
  public function getRuntime() {
    if (!isset($this->runtimeRegistry)) {
      $this->runtimeRegistry = new ThemeRegistry('theme_registry:runtime:' . $this->theme->getName(), $this->cache, $this->lock, array('theme_registry' => TRUE), $this->moduleHandler->isLoaded());
    }
    return $this->runtimeRegistry;
  }

  /**
   * Persists the theme registry in the cache backend.
   */
  protected function setCache() {
    $this->cache->set('theme_registry:' . $this->theme->getName(), $this->registry, Cache::PERMANENT, array('theme_registry' => TRUE));
  }

  /**
   * Returns the base hook for a given hook suggestion.
   *
   * @param string $hook
   *   The name of a theme hook whose base hook to find.
   *
   * @return string|false
   *   The name of the base hook or FALSE.
   */
  public function getBaseHook($hook) {
    $base_hook = $hook;
    // Iteratively strip everything after the last '__' delimiter, until a
    // base hook definition is found. Recursive base hooks of base hooks are
    // not supported, so the base hook must be an original implementation that
    // points to a theme function or template.
    while ($pos = strrpos($base_hook, '__')) {
      $base_hook = substr($base_hook, 0, $pos);
      if (isset($this->registry[$base_hook]['exists'])) {
        break;
      }
    }
    if ($pos !== FALSE && $base_hook !== $hook) {
      return $base_hook;
    }
    return FALSE;
  }

  /**
   * Builds the theme registry cache.
   *
   * Theme hook definitions are collected in the following order:
   * - Modules
   * - Base theme engines
   * - Base themes
   * - Theme engine
   * - Theme
   *
   * All theme hook definitions are essentially just collated and merged in the
   * above order. However, various extension-specific default values and
   * customizations are required; e.g., to record the effective file path for
   * theme template. Therefore, this method first collects all extensions per
   * type, and then dispatches the processing for each extension to
   * processExtension().
   *
   * After completing the collection, modules are allowed to alter it. Lastly,
   * any derived and incomplete theme hook definitions that are hook suggestions
   * for base hooks (e.g., 'block__node' for the base hook 'block') need to be
   * determined based on the full registry and classified as 'base hook'.
   *
   * @see _theme()
   * @see HOOK_theme_registry_alter()
   *
   * @return \Drupal\Core\Utility\ThemeRegistry
   *   The build theme registry.
   */
  protected function build() {
    $cache = array();
    // First, preprocess the theme hooks advertised by modules. This will
    // serve as the basic registry. Since the list of enabled modules is the
    // same regardless of the theme used, this is cached in its own entry to
    // save building it for every theme.
    if ($cached = $this->cache->get('theme_registry:build:modules')) {
      $cache = $cached->data;
    }
    else {
      foreach ($this->moduleHandler->getImplementations('theme') as $module) {
        $this->processExtension($cache, $module, 'module', $module, $this->getPath($module));
      }
      // Only cache this registry if all modules are loaded.
      if ($this->moduleHandler->isLoaded()) {
        $this->cache->set("theme_registry:build:modules", $cache, Cache::PERMANENT, array('theme_registry' => TRUE));
      }
    }

    // Process each base theme.
    foreach ($this->baseThemes as $base) {
      // If the base theme uses a theme engine, process its hooks.
      $base_path = $base->getPath();
      if ($this->engine) {
        $this->processExtension($cache, $this->engine, 'base_theme_engine', $base->getName(), $base_path);
      }
      $this->processExtension($cache, $base->getName(), 'base_theme', $base->getName(), $base_path);
    }

    // And then the same thing, but for the theme.
    if ($this->engine) {
      $this->processExtension($cache, $this->engine, 'theme_engine', $this->theme->getName(), $this->theme->getPath());
    }

    // Finally, hooks provided by the theme itself.
    $this->processExtension($cache, $this->theme->getName(), 'theme', $this->theme->getName(), $this->theme->getPath());

    // Let modules alter the registry.
    $this->moduleHandler->alter('theme_registry', $cache);

    // @todo Implement more reduction of the theme registry entry.
    // Optimize the registry to not have empty arrays for functions.
    foreach ($cache as $hook => $info) {
      if (empty($info['preprocess functions'])) {
        unset($cache[$hook]['preprocess functions']);
      }
    }
    $this->registry = $cache;

    return $this->registry;
  }

  /**
   * Process a single implementation of HOOK_theme().
   *
   * @param $cache
   *   The theme registry that will eventually be cached; It is an associative
   *   array keyed by theme hooks, whose values are associative arrays
   *   describing the hook:
   *   - 'type': The passed-in $type.
   *   - 'theme path': The passed-in $path.
   *   - 'function': The name of the function generating output for this theme
   *     hook. Either defined explicitly in HOOK_theme() or, if neither
   *     'function' nor 'template' is defined, then the default theme function
   *     name is used. The default theme function name is the theme hook
   *     prefixed by either 'theme_' for modules or '$name_' for everything
   *     else. If 'function' is defined, 'template' is not used.
   *   - 'template': The filename of the template generating output for this
   *     theme hook. The template is in the directory defined by the 'path' key
   *     of HOOK_theme() or defaults to "$path/templates".
   *   - 'variables': The variables for this theme hook as defined in
   *     HOOK_theme(). If there is more than one implementation and 'variables'
   *     is not specified in a later one, then the previous definition is kept.
   *   - 'render element': The renderable element for this theme hook as defined
   *     in HOOK_theme(). If there is more than one implementation and
   *     'render element' is not specified in a later one, then the previous
   *     definition is kept.
   *   - 'preprocess functions': See _theme() for detailed documentation.
   * @param string $name
   *   The name of the module, theme engine, base theme engine, theme or base
   *   theme implementing HOOK_theme().
   * @param string $type
   *   One of 'module', 'theme_engine', 'base_theme_engine', 'theme', or
   *   'base_theme'. Unlike regular hooks that can only be implemented by
   *   modules, each of these can implement HOOK_theme(). This function is
   *   called in aforementioned order and new entries override older ones. For
   *   example, if a theme hook is both defined by a module and a theme, then
   *   the definition in the theme will be used.
   * @param \stdClass $theme
   *   The loaded $theme object as returned from list_themes().
   * @param string $path
   *   The directory where $name is. For example, modules/system or
   *   themes/bartik.
   *
   * @see _theme()
   * @see HOOK_theme()
   * @see list_themes()
   */
  protected function processExtension(&$cache, $name, $type, $theme, $path) {
    $result = array();

    $HOOK_defaults = array(
      'variables' => TRUE,
      'render element' => TRUE,
      'pattern' => TRUE,
      'base hook' => TRUE,
    );

    $module_list = array_keys((array) $this->moduleHandler->getModuleList());

    // Invoke the HOOK_theme() implementation, preprocess what is returned, and
    // merge it into $cache.
    $function = $name . '_theme';
    if (function_exists($function)) {
      $result = $function($cache, $type, $theme, $path);
      foreach ($result as $hook => $info) {
        // When a theme or engine overrides a module's theme function
        // $result[$hook] will only contain key/value pairs for information being
        // overridden.  Pull the rest of the information from what was defined by
        // an earlier hook.

        // Fill in the type and path of the module, theme, or engine that
        // implements this theme function.
        $result[$hook]['type'] = $type;
        $result[$hook]['theme path'] = $path;

        // If function and file are omitted, default to standard naming
        // conventions.
        if (!isset($info['template']) && !isset($info['function'])) {
          $result[$hook]['function'] = ($type == 'module' ? 'theme_' : $name . '_') . $hook;
        }

        if (isset($cache[$hook]['includes'])) {
          $result[$hook]['includes'] = $cache[$hook]['includes'];
        }

        // If the theme implementation defines a file, then also use the path
        // that it defined. Otherwise use the default path. This allows
        // system.module to declare theme functions on behalf of core .include
        // files.
        if (isset($info['file'])) {
          $include_file = isset($info['path']) ? $info['path'] : $path;
          $include_file .= '/' . $info['file'];
          include_once DRUPAL_ROOT . '/' . $include_file;
          $result[$hook]['includes'][] = $include_file;
        }

        // If the default keys are not set, use the default values registered
        // by the module.
        if (isset($cache[$hook])) {
          $result[$hook] += array_intersect_key($cache[$hook], $HOOK_defaults);
        }

        // The following apply only to theming hooks implemented as templates.
        if (isset($info['template'])) {
          // Prepend the current theming path when none is set.
          if (!isset($info['path'])) {
            $result[$hook]['template'] = $path . '/templates/' . $info['template'];
          }
        }

        // Preprocess variables for all theming hooks, whether the hook is
        // implemented as a template or as a function. Ensure they are arrays.
        if (!isset($info['preprocess functions']) || !is_array($info['preprocess functions'])) {
          $info['preprocess functions'] = array();
          $prefixes = array();
          if ($type == 'module') {
            // Default variable preprocessor prefix.
            $prefixes[] = 'template';
            // Add all modules so they can intervene with their own variable
            // preprocessors. This allows them to provide variable preprocessors
            // even if they are not the owner of the current hook.
            $prefixes = array_merge($prefixes, $module_list);
          }
          elseif ($type == 'theme_engine' || $type == 'base_theme_engine') {
            // Theme engines get an extra set that come before the normally
            // named variable preprocessors.
            $prefixes[] = $name . '_engine';
            // The theme engine registers on behalf of the theme using the
            // theme's name.
            $prefixes[] = $theme;
          }
          else {
            // This applies when the theme manually registers their own variable
            // preprocessors.
            $prefixes[] = $name;
          }
          foreach ($prefixes as $prefix) {
            // Only use non-hook-specific variable preprocessors for theming
            // hooks implemented as templates. See _theme().
            if (isset($info['template']) && function_exists($prefix . '_preprocess')) {
              $info['preprocess functions'][] = $prefix . '_preprocess';
            }
            if (function_exists($prefix . '_preprocess_' . $hook)) {
              $info['preprocess functions'][] = $prefix . '_preprocess_' . $hook;
            }
          }
        }
        // Check for the override flag and prevent the cached variable
        // preprocessors from being used. This allows themes or theme engines
        // to remove variable preprocessors set earlier in the registry build.
        if (!empty($info['override preprocess functions'])) {
          // Flag not needed inside the registry.
          unset($result[$hook]['override preprocess functions']);
        }
        elseif (isset($cache[$hook]['preprocess functions']) && is_array($cache[$hook]['preprocess functions'])) {
          $info['preprocess functions'] = array_merge($cache[$hook]['preprocess functions'], $info['preprocess functions']);
        }
        $result[$hook]['preprocess functions'] = $info['preprocess functions'];
      }

      // Merge the newly created theme hooks into the existing cache.
      $cache = $result + $cache;
    }

    // Let themes have variable preprocessors even if they didn't register a
    // template.
    if ($type == 'theme' || $type == 'base_theme') {
      foreach ($cache as $hook => $info) {
        // Check only if not registered by the theme or engine.
        if (empty($result[$hook])) {
          if (!isset($info['preprocess functions'])) {
            $cache[$hook]['preprocess functions'] = array();
          }
          // Only use non-hook-specific variable preprocessors for theme hooks
          // implemented as templates. See _theme().
          if (isset($info['template']) && function_exists($name . '_preprocess')) {
            $cache[$hook]['preprocess functions'][] = $name . '_preprocess';
          }
          if (function_exists($name . '_preprocess_' . $hook)) {
            $cache[$hook]['preprocess functions'][] = $name . '_preprocess_' . $hook;
            $cache[$hook]['theme path'] = $path;
          }
          // Ensure uniqueness.
          $cache[$hook]['preprocess functions'] = array_unique($cache[$hook]['preprocess functions']);
        }
      }
    }
  }

  /**
   * Invalidates theme registry caches.
   *
   * To be called when the list of enabled extensions is changed.
   */
  public function reset() {

    // Reset the runtime registry.
    if (isset($this->runtimeRegistry) && $this->runtimeRegistry instanceof ThemeRegistry) {
      $this->runtimeRegistry->clear();
    }
    $this->runtimeRegistry = NULL;

    $this->registry = NULL;
    Cache::invalidateTags(array('theme_registry' => TRUE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if (isset($this->runtimeRegistry)) {
      $this->runtimeRegistry->destruct();
    }
  }

  /**
   * Wraps drupal_get_path().
   *
   * @param string $module
   *   The name of the item for which the path is requested.
   *
   * @return string
   */
  protected function getPath($module) {
    return drupal_get_path('module', $module);
  }

  /**
   * Wraps list_themes().
   *
   * @return array
   */
  protected function listThemes() {
    return list_themes();
  }

  /**
   * Wraps drupal_theme_initialize().
   */
  protected function initializeTheme() {
    drupal_theme_initialize();
  }

}
