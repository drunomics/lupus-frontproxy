<?php

namespace drunomics\LupusFrontProxy;

use drunomics\LupusFrontProxy\ResponseFetcher\DefaultResponseFetcher;
use drunomics\LupusFrontProxy\ResponseFetcher\ResponseFetcherInterface;
use GuzzleHttp\Psr7\Uri;
use drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Symfony\Bridge\PsrHttpMessage\Factory\DiactorosFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Handles requests.
 */
class RequestHandler {

  /**
   * The base URL of the main app; i.e. the front-proxy.
   *
   * @var string
   */
  protected $baseUrl;

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
   * The response merger.
   *
   * @var \drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface
   */
  protected $responseMerger;

  /**
   * The response fetcher.
   *
   * @var \drunomics\LupusFrontProxy\ResponseFetcher\ResponseFetcherInterface
   */
  protected $responseFetcher;

  /**
   * RequestHandler constructor.
   *
   * @param string $baseUrl
   *   The base URL of the main app; i.e. the front-proxy.
   * @param string $backendBaseUrl
   *   The base URL of the backend.
   * @param string $frontendBaseUrl
   *   The base URL of the frontend.
   * @param \drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface $responseMerger
   *   The response merger to use.
   * @param \drunomics\LupusFrontProxy\ResponseFetcher\ResponseFetcherInterface|null $responseFetcher
   *   (optional) The response fetcher to use.
   */
  public function __construct($baseUrl, $backendBaseUrl, $frontendBaseUrl, ResponseMergerInterface $responseMerger, ResponseFetcherInterface $responseFetcher = NULL) {
    /** @var TYPE_NAME $this */
    $this->baseUrl = $baseUrl;
    $this->backendBaseUrl = trim($backendBaseUrl, '/');
    $this->frontendBaseUrl = trim($frontendBaseUrl, '/');
    $this->responseMerger = $responseMerger;
    $this->responseFetcher = $responseFetcher ?: new DefaultResponseFetcher();
  }

  /**
   * Allows setting basic HTTP authentication credentials.
   *
   * The authentication is applied for both, requests to the static and api
   * endpoints.
   *
   * @param string $user
   *   The user name.
   * @param string $password
   *   The password.
   *
   * @return $this
   */
  public function setHttpAuth($user, $password) {
    if ($user && $password) {
      $this->guzzleConfig['auth'] = [$user, $password];
    }
    else {
      unset($this->guzzleConfig['auth']);
    }
    return $this;
  }

  /**
   * Handles the given request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The http request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The generated response.
   */
  public function handle(Request $request) {
    try {
      $backend_request = $this->getBackendRequest($request);
      // Fetch the page shell from the frontend.
      // @todo: Add caching here.
      $frontend_request = new GuzzleRequest('GET', $this->frontendBaseUrl . '/layout--default.html');
      list($frontend_response, $backend_response) = $this->responseFetcher->fetchResponses($frontend_request, $backend_request);

      $response_status = intval($backend_response->getStatusCode() / 100);
      if ($response_status == 2) {
        return $this->getCombinedResponse($backend_response, $frontend_response);
      }
      // Pass through redirects.
      elseif ($response_status == 3) {
        $backend_response = $this->mapHeaderUrl($backend_response, 'Location');
        return $this->convertToSymfonyResponse($backend_response);
      }
      elseif ($response_status == 4) {
        return $this->getCombinedResponse($backend_response, $frontend_response);
      }
      // Handle server errors.
      elseif ($response_status == 5) {
        return $this->getErrorResponse($backend_response);
      }
      else {
        throw new BadRequestHttpException('Received unsupported response status from backend.');
      }
    }
    catch (BadRequestHttpException $exception) {
      $response = new Response(strip_tags($exception->getMessage()));
      $response->setStatusCode(500);
      return $response;
    }
  }

  /**
   * Maps URLs from backend to the base URLs in headers.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The backend response.
   * @param string $header
   *   The header to map.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The mapped response.
   */
  private function mapHeaderUrl(ResponseInterface $response, $header) {
    $value = $response->getHeader($header);
    if ($value) {
      return $response->withHeader($header, str_replace($this->backendBaseUrl, $this->baseUrl, $value));
    }
    return $response;
  }

  /**
   * Gets an error response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return \Psr\Http\Message\ResponseInterface|\Symfony\Component\HttpFoundation\Response
   *   The error response.
   */
  private function getErrorResponse(ResponseInterface $response) {
    return $this->responseMerger->getErrorResponse($response);
  }

  /**
   * Combines the frontend and backend responses.
   *
   * @param \Psr\Http\Message\ResponseInterface $backendResponse
   *   The backend response.
   * @param \Psr\Http\Message\ResponseInterface $frontendResponse
   *   The frontend response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The combined response.
   */
  private function getCombinedResponse(ResponseInterface $backendResponse, ResponseInterface $frontendResponse) {
    $response = $this->responseMerger->mergeResponses($backendResponse, $frontendResponse);
    return $this->convertToSymfonyResponse($response);
  }

  /**
   * Converts the guzzle response to a symfony response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The generated response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The converted response.
   */
  private function convertToSymfonyResponse(ResponseInterface $response) {
    $httpFoundationFactory = new HttpFoundationFactory();
    return $httpFoundationFactory->createResponse($response);
  }

  /**
   * Generates a request by forwarding the request to the backend.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The http request.
   *
   * @return \Psr\Http\Message\ServerRequestInterface
   *   The backend request.
   */
  private function getBackendRequest(Request $request) {
    // Forward the request to the backend.
    $psr7_request = (new DiactorosFactory())->createRequest($request);
    $new_uri = str_replace($request->getSchemeAndHttpHost(), $this->backendBaseUrl, $psr7_request->getUri());
    return $psr7_request->withUri(new Uri($new_uri));
  }

}
