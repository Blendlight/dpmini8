<?php
namespace Drupal\Core;

use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DrupalKernel implements DrupalKernelInterface, TerminableInterface
{

    /*
    *   Environment ('prod', 'dev')
    */
    protected $environment;

    /*
    * Composer Autloader class
    */
    protected $classLoader;

    protected $allowDumping = FALSE;

    /*
    * Root of the application
    */
    protected $root;


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

        \Moin::preColor($this->container);

        try {
//{IGNORE.forThisTime}
//            $this->initializeSettings($request);

            // Redirect the user to the installation script if Drupal has not been
            // installed yet (i.e., if no $databases array has been defined in the
            // settings.php file) and we are not already installing.
//{IGNORE.forThisTime}
            if(false){
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

    }


    /**
    * {@inheritdoc}
    */
    public function getSitePath()
    {

    }


    /**
    * {@inheritdoc}
    */
    public function getAppRoot()
    {

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