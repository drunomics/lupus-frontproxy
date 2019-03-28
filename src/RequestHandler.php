<?php

namespace drunomics\LupusFrontProxy;

use drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Client;
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
   * Guzzle config for configuring request option defaults.
   *
   * @var array
   */
  protected $guzzleConfig = [];

  /**
   * The response merger.
   *
   * @var \drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface
   */
  protected $responseMerger;

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
   */
  public function __construct($baseUrl, $backendBaseUrl, $frontendBaseUrl, ResponseMergerInterface $responseMerger) {
    $this->baseUrl = $baseUrl;
    $this->backendBaseUrl = trim($backendBaseUrl, '/');
    $this->frontendBaseUrl = trim($frontendBaseUrl, '/');
    $this->responseMerger = $responseMerger;
  }

  /**
   * Allows setting basic HTTP authentication credentials.
   *
   * The authentication is applied for both, requests to the static and api
   * endpoints.
   *
   * @param string $user
   *   The user name.
   * @param $password
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
   * 
   * @return \Symfony\Component\HttpFoundation\Response
   *   The generated response.
   */
  public function handle(Request $request) {
    try {
      $client = new Client($this->guzzleConfig);
      $backend_response = $client->sendAsync($this->getBackendRequest($request), [
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
      ]);
      // Fetch the page shell from the frontend.
      // @todo: Add caching here.
      $frontend_request = new GuzzleRequest('GET', $this->frontendBaseUrl . '/layout--default.html');
      $frontend_response = $client->sendAsync($frontend_request);

      // Resolve the frontend promise first since static files should be always
      // faster than the backend.
      /** @var \Psr\Http\Message\ResponseInterface $frontend_response */
      /** @var \Psr\Http\Message\ResponseInterface $backend_response */
      $frontend_response = $frontend_response->wait();
      $backend_response = $backend_response->wait();

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
   *
   * @return \Psr\Http\Message\ResponseInterface|\Symfony\Component\HttpFoundation\Response
   */
  private function getErrorResponse(ResponseInterface $response) {
    return $this->responseMerger->getErrorResponse($response);
  }

  /**
   * Combines the frontend and backend responses.
   *
   * @param \Psr\Http\Message\ResponseInterface $backendResponse
   * @param \Psr\Http\Message\ResponseInterface $frontendResponse
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function getCombinedResponse(ResponseInterface $backendResponse, ResponseInterface $frontendResponse) {
    $response = $this->responseMerger->mergeResponses($backendResponse, $frontendResponse);
    return $this->convertToSymfonyResponse($response);
  }

  /**
   * Converts the guzzle response to a symfony response.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function convertToSymfonyResponse(ResponseInterface $response) {
    $httpFoundationFactory = new HttpFoundationFactory();
    return $httpFoundationFactory->createResponse($response);
  }

  /**
   * Generates a request by forwarding the request to the backend.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Psr\Http\Message\ServerRequestInterface
   */
  private function getBackendRequest(Request $request) {
    // Forward the request to the backend.
    $psr7_request = (new DiactorosFactory())->createRequest($request);
    $new_uri = str_replace($request->getSchemeAndHttpHost(), $this->backendBaseUrl, $psr7_request->getUri());
    return $psr7_request->withUri(new \GuzzleHttp\Psr7\Uri($new_uri));
  }

}