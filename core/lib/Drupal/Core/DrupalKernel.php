<?php
namespace Drupal\Core;

use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\Core\Database\Database;
use Drupal\Component\FileCache\FileCacheFactory;

class DrupalKernel implements DrupalKernelInterface, TerminableInterface
{


    /**
     *   Environment ('prod', 'dev')
     */

    protected $environment;
    /**
     * Composer Autloader class
     */

    protected $classLoader;

    protected $allowDumping = FALSE;


    /**
     * Root of the application
     */
    protected $root;

    /**
     * Kernel boot is run or not
     */
    protected $booted = false;


    /**
     *
     */
    protected static $isEnvironmentInitialized = false;



    /**
     * Holds the container instance.
     *
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    protected $container;


    /**
     * The site directory.
     *
     * @var string
     */
    protected $sitePath;


    /**
     * Holds the default bootstrap container definition.
     *
     * @var array
     */
    protected $defaultBootstrapContainerDefinition = [
        'parameters' => [],
        'services' => [
            'database' => [
                'class' => 'Drupal\Core\Database\Connection',
                'factory' => 'Drupal\Core\Database\Database::getConnection',
                'arguments' => ['default'],
            ],
            'cache.container' => [
                'class' => 'Drupal\Core\Cache\DatabaseBackend',
                'arguments' => ['@database', '@cache_tags_provider.container', 'container'],
            ],
            'cache_tags_provider.container' => [
                'class' => 'Drupal\Core\Cache\DatabaseCacheTagsChecksum',
                'arguments' => ['@database'],
            ],
        ],
    ];


    /**
     * Holds the class used for instantiating the bootstrap container.
     *
     * @var string
     */
    protected $bootstrapContainerClass = '\Drupal\Component\DependencyInjection\PhpArrayContainer';

    /**
     * Holds the bootstrap container.
     *
     * @var \Symfony\Component\DependencyInjection\ContainerInterface
     */
    protected $bootstrapContainer;


    /**
     * Constructs a DrupalKernel object.
     *
     * @param string $environment
     *   String indicating the environment, e.g. 'prod' or 'dev'.
     * @param $class_loader
     *   The class loader. Normally \Composer\Autoload\ClassLoader, as included by
     *   the front controller, but may also be decorated; e.g.,
     *   \Symfony\Component\ClassLoader\ApcClassLoader.
     * @param bool $allow_dumping
     *   (optional) FALSE to stop the container from being written to or read
     *   from disk. Defaults to TRUE.
     * @param string $app_root
     *   (optional) The path to the application root as a string. If not supplied,
     *   the application root will be computed.
     */
    public function __construct($environment, $class_loader, $allow_dumping = TRUE, $app_root = NULL) {
        $this->environment = $environment;
        $this->classLoader = $class_loader;
        $this->allowDumping = $allow_dumping;
        if ($app_root === NULL) {
            $app_root = static::guessApplicationRoot();
        }
        $this->root = $app_root;
    }

    /**
     * Converts an exception into a response.
     *
     * @param \Exception $e
     *   An exception
     * @param Request $request
     *   A Request instance
     * @param int $type
     *   The type of the request (one of HttpKernelInterface::MASTER_REQUEST or
     *   HttpKernelInterface::SUB_REQUEST)
     *
     * @return Response
     *   A Response instance
     *
     * @throws \Exception
     *   If the passed in exception cannot be turned into a response.
     */
    protected function handleException(\Exception $e, $request, $type) {
        //{IGNORE.NotUsing}

        if(FALSE){
            if ($this->shouldRedirectToInstaller($e, $this->container ? $this->container->get('database') : NULL)) {
                return new RedirectResponse($request->getBasePath() . '/core/install.php', 302, ['Cache-Control' => 'no-cache']);
            }
        }

        if ($e instanceof HttpExceptionInterface) {
            $response = new Response($e->getMessage(), $e->getStatusCode());
            $response->headers->add($e->getHeaders());
            return $response;
        }

        throw $e;
    }

    /**
     * Determine the application root directory based on assumptions.
     *
     * @return string
     *   The application root.
     */
    protected static function guessApplicationRoot() {
        return dirname(dirname(substr(__DIR__, 0, -strlen(__NAMESPACE__))));
    }



    /**
     * {@inheritdoc}
     */
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
    {

        static::bootEnvironment();


        try {

            //Load settings.php
            //set Settings singleton
            //set Database object...
            $this->initializeSettings($request);

            // Redirect the user to the installation script if Drupal has not been
            // installed yet (i.e., if no $databases array has been defined in the
            // settings.php file) and we are not already installing.

            if(false){
                //if the settings.php file doesn't exists or database info is not available
                if (!Database::getConnectionInfo() && !drupal_installation_attempted() && PHP_SAPI !== 'cli') {
                    $response = new RedirectResponse($request->getBasePath() . '/core/install.php', 302, ['Cache-Control' => 'no-cache']);
                }
                else {
                    $this->boot();
                    $response = $this->getHttpKernel()->handle($request, $type, $catch);
                }
            }else{
                $this->boot();
                $response = $this->getHttpKernel()->handle($request, $type, $catch);
            }
        }
        catch (\Exception $e) {

            if ($catch === FALSE) {
                throw $e;
            }

            $response = $this->handleException($e, $request, $type);
        }

        // Adapt response headers to the current request.
        $response->prepare($request);

        return $response;
    }


    /**
     * Locate site path and initialize settings singleton.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The current request.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
     *   In case the host name in the request is not trusted.
     */
    protected function initializeSettings(Request $request) {
        $site_path = static::findSitePath($request);
        $this->setSitePath($site_path);

        //class name of classLoader e.g. Composer\Autoload\ClassLoader
        $class_loader_class = get_class($this->classLoader);

        //load settings.php and set Database and create it's instance
        Settings::initialize($this->root, $site_path, $this->classLoader);

        // Initialize our list of trusted HTTP Host headers to protect against
        // header attacks.
        $host_patterns = Settings::get('trusted_host_patterns', []);



        //If the request is not from cli and there are host patterns
        //{IGNORE.forThisTime}
        if (PHP_SAPI !== 'cli' && !empty($host_patterns)) {
            if (static::setupTrustedHosts($request, $host_patterns) === FALSE) {
                throw new BadRequestHttpException('The provided host name is not valid for this server.');
            }
        }

        //{IGNORE.forThisTime}
        //Without this core will still work
        if(false) {

            // If the class loader is still the same, possibly
            // upgrade to an optimized class loader.
            if ($class_loader_class == get_class($this->classLoader)
                && Settings::get('class_loader_auto_detect', TRUE)) {
                $prefix = Settings::getApcuPrefix('class_loader', $this->root);


                $loader = NULL;

                // We autodetect one of the following three optimized classloaders, if
                // their underlying extension exists.
                if (function_exists('apcu_fetch')) {
                    $loader = new ApcClassLoader($prefix, $this->classLoader);
                } elseif (extension_loaded('wincache')) {
                    $loader = new WinCacheClassLoader($prefix, $this->classLoader);
                } elseif (extension_loaded('xcache')) {
                    $loader = new XcacheClassLoader($prefix, $this->classLoader);
                }
                if (!empty($loader)) {
                    $this->classLoader->unregister();
                    // The optimized classloader might be persistent and store cache misses.
                    // For example, once a cache miss is stored in APCu clearing it on a
                    // specific web-head will not clear any other web-heads. Therefore
                    // fallback to the composer class loader that only statically caches
                    // misses.
                    $old_loader = $this->classLoader;
                    $this->classLoader = $loader;
                    // Our class loaders are preprended to ensure they come first like the
                    // class loader they are replacing.
                    $old_loader->register(TRUE);
                    $loader->register(TRUE);
                }

            }

        }
    }



    /**
     * Return site path
     */
    protected  function findSitePath()
    {
        //we are not using any multisite just return sites/default
        return 'sites/default';
    }


    /**
     * Setup a consistent PHP environment.
     *
     * This method sets PHP environment options we want to be sure are set
     * correctly for security or just saneness.
     *
     * @param string $app_root
     *   (optional) The path to the application root as a string. If not supplied,
     *   the application root will be computed.
     */
    public static function bootEnvironment($app_root = NULL) {

        if (static::$isEnvironmentInitialized) {
            return;
        }

        // Determine the application root if it's not supplied.
        if ($app_root === NULL) {
            $app_root = static::guessApplicationRoot();
        }

        // Include our bootstrap file.
        require_once $app_root . '/core/includes/bootstrap.inc';

        // Enforce E_STRICT, but allow users to set levels not part of E_STRICT.
        error_reporting(E_STRICT | E_ALL);

        // Override PHP settings required for Drupal to work properly.
        // sites/default/default.settings.php contains more runtime settings.
        // The .htaccess file contains settings that cannot be changed at runtime.

        // Use session cookies, not transparent sessions that puts the session id in
        // the query string.
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        // Don't send HTTP headers using PHP's session handler.
        // Send an empty string to disable the cache limiter.
        ini_set('session.cache_limiter', '');
        // Use httponly session cookies.
        ini_set('session.cookie_httponly', '1');

        // Set sane locale settings, to ensure consistent string, dates, times and
        // numbers handling.
        setlocale(LC_ALL, 'C');

        //{IGNORE.ForThisTime}
        // Detect string handling method.
        //        Unicode::check();

        // Indicate that code is operating in a test child site.
        //{IGNORE.ForThisTime}
        if(FALSE) {
            if (!defined('DRUPAL_TEST_IN_CHILD_SITE')) {
                if ($test_prefix = drupal_valid_test_ua()) {
                    $test_db = new TestDatabase($test_prefix);
                    // Only code that interfaces directly with tests should rely on this
                    // constant; e.g., the error/exception handler conditionally adds further
                    // error information into HTTP response headers that are consumed by
                    // Simpletest's internal browser.
                    define('DRUPAL_TEST_IN_CHILD_SITE', TRUE);

                    // Web tests are to be conducted with runtime assertions active.
                    assert_options(ASSERT_ACTIVE, TRUE);
                    // Now synchronize PHP 5 and 7's handling of assertions as much as
                    // possible.
                    Handle::register();

                    // Log fatal errors to the test site directory.
                    ini_set('log_errors', 1);
                    ini_set('error_log', $app_root . '/' . $test_db->getTestSitePath() . '/error.log');

                    // Ensure that a rewritten settings.php is used if opcache is on.
                    ini_set('opcache.validate_timestamps', 'on');
                    ini_set('opcache.revalidate_freq', 0);
                } else {
                    // Ensure that no other code defines this.
                    define('DRUPAL_TEST_IN_CHILD_SITE', FALSE);
                }
            }
        }else{
            define('DRUPAL_TEST_IN_CHILD_SITE', FALSE);
        }

        // Set the Drupal custom error handler.
        //        set_error_handler('_drupal_error_handler');
        //        set_exception_handler('_drupal_exception_handler');

        static::$isEnvironmentInitialized = TRUE;
    }

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {

    }


    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        //if already booted return DrupalKernel
        if($this->booted)
        {
            return $this;
        }

        // Ensure that findSitePath is set.
        if (!$this->sitePath) {
            throw new \Exception('Kernel does not have site path set before calling boot()');
        }

        //{IGNORE.CoreWorks}
        if(FALSE)
        {
            // Initialize the FileCacheFactory component. We have to do it here instead
            // of in \Drupal\Component\FileCache\FileCacheFactory because we can not use
            // the Settings object in a component.
            $configuration = Settings::get('file_cache');



            // Provide a default configuration, if not set.
            if (!isset($configuration['default'])) {
                // @todo Use extension_loaded('apcu') for non-testbot
                //  https://www.drupal.org/node/2447753.
                if (function_exists('apcu_fetch')) {
                    $configuration['default']['cache_backend_class'] = '\Drupal\Component\FileCache\ApcuFileCacheBackend';
                }
            }

        }


        //{IGNORE.CoreWorks}
        if(FALSE)
        {
            FileCacheFactory::setConfiguration($configuration);
            FileCacheFactory::setPrefix(Settings::getApcuPrefix('file_cache', $this->root));
        }

        $this->bootstrapContainer = new $this->bootstrapContainerClass(Settings::get('bootstrap_container_definition', $this->defaultBootstrapContainerDefinition));



        // Initialize the container.
        $this->initializeContainer();

        $this->booted = TRUE;

        return $this;

    }


    /**
     * {@inheritdoc}
     */
    public function shutdown()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function discoverServiceProviders()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function getServiceProviders($origin)
    {

    }


    /**
     * {@inheritdoc}
     */
    public function getContainer()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function getCachedContainerDefinition()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function setSitePath($path)
    {
        if($this->booted)
        {
            throw new \LogicException("Can not change site path after calling boot()");
        }

        $this->sitePath = $path;
    }


    /**
     * {@inheritdoc}
     */
    public function getSitePath()
    {
        return $this->sitePath;
    }


    /**
     * {@inheritdoc}
     */
    public function getAppRoot()
    {
        return $this->root;
    }


    /**
     * {@inheritdoc}
     */
    public function updateModules(array $module_list, array $module_filenames = [])
    {

    }


    /**
     * {@inheritdoc}
     */
    public function rebuildContainer()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function invalidateContainer()
    {

    }


    /**
     * {@inheritdoc}
     */
    public function prepareLegacyRequest(Request $request)
    {

    }


    /**
     * {@inheritdoc}
     */
    public function preHandle(Request $request)
    {

    }


    /**
     * {@inheritdoc}
     */
    public function loadLegacyIncludes()
    {

    }
    /**
     * {@inheritdoc}
     */
    public function terminate(Request $request, Response $response) {

        \Moin::preColor('Returning from Terminate getHTTPKERNEL not defined yet called from '.__CLASS__.'::'.__FUNCTION__, '#009033');

        return;


        if ($this->getHttpKernel() instanceof TerminableInterface) {
            $this->getHttpKernel()->terminate($request, $response);
        }

        /*
        // Only run terminate() when essential services have been set up properly
        // by preHandle() before.
        if (FALSE === $this->prepared) {
            return;
        }

        if ($this->getHttpKernel() instanceof TerminableInterface) {
            $this->getHttpKernel()->terminate($request, $response);
        }
        */


    }
}