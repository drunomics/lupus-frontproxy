<?php

namespace drunomics\LupusFrontProxy\ResponseFetcher;

use Drupal\Core\DrupalKernel;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
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
class DrupalResponseFetcher extends DefaultResponseFetcher {

  /**
   * The class loader.
   *
   * @var object
   */
  protected $autoloader;

  /**
   * The kernel with the handled request and response.
   *
   * @var mixed[]
   */
  private $lastRequestData;

  /**
   * DrupalResponseFetcher constructor.
   *
   * @param mixed $autoloader
   *   The class loader. Normally \Composer\Autoload\ClassLoader, as included by
   *   the front controller, but may also be decorated; e.g.,
   *   \Symfony\Component\ClassLoader\ApcClassLoader.
   */
  public function __construct($autoloader) {
    $this->autoloader = $autoloader;
  }

  /**
   * {@inheritdoc}
   */
  public function fetchResponses(RequestInterface $frontend_request, RequestInterface $backend_request) {
    $curl = new CurlMultiHandler();
    $client = new Client(['handler' => HandlerStack::create($curl)] + $this->guzzleConfig);
    // Do the frontend request, and while this is resolved, handle the backend
    // request.
    $frontend_response = $client->sendAsync($frontend_request);
    // Actually start sending the request, then process the backend meanwhile.
    $curl->tick();

    $backend_response = $this->handleBackendRequest($backend_request);

    // Resolve the frontend promise first since static files should be always
    // faster than the backend.
    /** @var \Psr\Http\Message\ResponseInterface $frontend_response */
    /** @var \Psr\Http\Message\ResponseInterface $backend_response */
    $frontend_response = $frontend_response->wait();
    return [$frontend_response, $backend_response];
  }

  /**
   * Handles the backend request.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The backend request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   */
  protected function handleBackendRequest(RequestInterface $request) {
    // Determine app root and change directory to it, so templates etc. are
    // all found as usual.
    DrupalKernel::bootEnvironment();
    chdir(DRUPAL_ROOT);

    // Directly handle the backend request via Drupal.
    $kernel = new DrupalKernel('prod', $this->autoloader);

    $symfony_factory = new HttpFoundationFactory();
    $symfony_request = $symfony_factory->createRequest($request);

    $response = $kernel->handle($symfony_request);

    // Make sure empty headers are not set.
    foreach ($response->headers as $key => $value) {
      if ($response->headers->get($key) === NULL) {
        $response->headers->remove($key);
      }
    }

    // Keep for terminating later.
    $this->lastRequestData = [$kernel, $symfony_request, $response];

    // Return PSR-7 response.
    $psr7_response = (new DiactorosFactory())->createResponse($response);
    return $psr7_response;
  }

  /**
   * Terminates the request.
   */
  public function terminateRequest() {
    if ($this->lastRequestData) {
      list($kernel, $request, $response) = $this->lastRequestData;
      $kernel->terminate($request, $response);
    }
  }

}
