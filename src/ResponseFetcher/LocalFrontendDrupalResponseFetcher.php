<?php


namespace drunomics\LupusFrontProxy\ResponseFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Psr7\Response;
use Drupal\Core\DrupalKernel;
use GuzzleHttp\HandlerStack;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Psr\Http\Message\RequestInterface;

/**
 * Drupal response fetching routine.
 *
 * This is an alternative fetcher which makes use of the symfony http foundation
 * to handle the request without the need of another HTTP request. As a
 * consequence we bypass any reverse proxies for those backend requests, but
 * Drupal internal page caching still applies.
 */
class LocalFrontendDrupalResponseFetcher extends DrupalResponseFetcher {

  /**
   * The path to public directory.
   *
   * @var string
   */
  protected $publicDir;

  /**
   * DrupalResponseFetcher constructor.
   *
   * @param mixed $autoloader
   *   The class loader. Normally \Composer\Autoload\ClassLoader, as included by
   *   the front controller, but may also be decorated; e.g.,
   *   \Symfony\Component\ClassLoader\ApcClassLoader.
   * @param string $publicDir
   *   The path to public directory.
   */
  public function __construct($autoloader, $publicDir) {
    parent::__construct($autoloader);
    $this->publicDir = $publicDir;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchResponses(RequestInterface $frontend_request, RequestInterface $backend_request) {
    // Try finding frontend resource via filesystem.
    $site = getenv('SITE');
    $request_body = file_get_contents($this->publicDir . '/' . $site . '/layout--default.html');

    if (!$request_body) {
      list($frontend_response, $backend_response) = parent::fetchResponses($frontend_request, $backend_request);
    }
    else {
      $frontend_response = new Response(200, [], $request_body);
      /** @var \Psr\Http\Message\ResponseInterface $backend_response */
      $backend_response = $this->handleBackendRequest($backend_request);
    }
    return [$frontend_response, $backend_response];
  }

}
