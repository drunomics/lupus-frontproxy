<?php


namespace drunomics\LupusFrontProxy\ResponseFetcher;

use Drupal\Core\File\Exception\FileNotExistsException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Drupal response fetching routine.
 *
 * This is an alternative fetcher which makes use of the filesystem
 * to fetch static frontend resource. That way we eliminate the need for
 * sending additional request to server. Backend request is passed over to
 * the DrupalResponseFetcher for handling.
 */
class LocalFrontendDrupalResponseFetcher extends DrupalResponseFetcher {

  /**
   * The path to the site frontend public directory.
   *
   * @var string
   */
  protected $publicDir;

  /**
   * LocalFrontendDrupalResponseFetcher constructor.
   *
   * @param mixed $autoloader
   *   The class loader. Normally \Composer\Autoload\ClassLoader, as included by
   *   the front controller, but may also be decorated; e.g.,
   *   \Symfony\Component\ClassLoader\ApcClassLoader.
   * @param string $publicDir
   *   The path to the site frontend public directory.
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
    $request_body = file_get_contents($this->publicDir . '/layout--default.html');

    if (!$request_body) {
      throw new FileNotExistsException('Resource "layout--default.html" not found in directory ' . $this->publicDir);
    }

    $frontend_response = new Response(200, [], $request_body);
    /** @var \Psr\Http\Message\ResponseInterface $backend_response */
    $backend_response = $this->handleBackendRequest($backend_request);
    return [$frontend_response, $backend_response];
  }

}
