<?php

namespace drunomics\LupusFrontProxy\RequestGenerator;

use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Uri;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * The default request generator.
 */
class DefaultRequestGenerator implements RequestGeneratorInterface {

  /**
   * The default page to load from the frontend.
   *
   * @var string
   */
  protected $defaultPage;

  /**
   * The list of frontend pages next to the default page.
   *
   * The pages are the keys of the array, the values do not matter.
   *
   * @var array
   */
  protected $frontendPages = [];

  /**
   * Base URL of the backend.
   *
   * @var string
   */
  protected $backendBaseUrl;

  /**
   * Base URL of the frontend.
   *
   * @var string
   */
  protected $frontendBaseUrl;

  /**
   * DefaultRequestGenerator constructor.
   *
   * @param string $backendBaseUrl
   *   The base URL of the backend.
   * @param string $frontendBaseUrl
   *   The base URL of the frontend.
   * @param string $defaultPage
   *   The default page to load from the frontend.
   */
  public function __construct($backendBaseUrl, $frontendBaseUrl, $defaultPage = 'layout--default') {
    $this->backendBaseUrl = $backendBaseUrl;
    $this->frontendBaseUrl = $frontendBaseUrl;
    $this->defaultPage = $defaultPage;
  }

  /**
   * {@inheritdoc}
   *
   * By default forward the request headers to the backend.
   */
  public function getBackendRequest(Request $request) {
    // Forward the request to the backend.
    $psr7_request = (new DiactorosFactory())->createRequest($request);
    $new_uri = str_replace($request->getSchemeAndHttpHost(), $this->backendBaseUrl, $psr7_request->getUri());
    return $psr7_request->withUri(new Uri($new_uri));
  }

  /**
   * {@inheritdoc}
   */
  public function getFrontendRequest(Request $request) {
    $path = trim($request->getPathInfo(), '/');
    $frontend_path = isset($this->frontendPages[$path]) ? $path : $this->defaultPage;
    // @todo: Possibly add some caching here.
    return new GuzzleRequest('GET', $this->frontendBaseUrl . '/' . $frontend_path . '.html');
  }

  /**
   * Sets the list of frontend pages next to the default page.
   *
   * Allows setting additional frontend pages that should be served instead
   * of the default page, when the path matches the page name.
   *
   * @param string[] $pages
   *   The list of frontend pages.
   *
   * @return $this
   */
  public function setFrontendPages(array $pages) {
    $this->frontendPages = array_flip($pages);
    return $this;
  }

}
