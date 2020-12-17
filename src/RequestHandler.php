<?php

namespace drunomics\LupusFrontProxy;

use drunomics\LupusFrontProxy\RequestGenerator\DefaultRequestGenerator;
use drunomics\LupusFrontProxy\RequestGenerator\RequestGeneratorInterface;
use drunomics\LupusFrontProxy\ResponseFetcher\DefaultResponseFetcher;
use drunomics\LupusFrontProxy\ResponseFetcher\ResponseFetcherInterface;
use drunomics\LupusFrontProxy\ResponseMerger\ResponseMergerInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
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
   * The request generator.
   *
   * @var \drunomics\LupusFrontProxy\RequestGenerator\RequestGeneratorInterface
   */
  protected $requestGenerator;

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
   * @param \drunomics\LupusFrontProxy\RequestGenerator\RequestGeneratorInterface|null $requestGenerator
   *   (optional) The request generator to use.
   */
  public function __construct($baseUrl, $backendBaseUrl, $frontendBaseUrl, ResponseMergerInterface $responseMerger, ResponseFetcherInterface $responseFetcher = NULL, RequestGeneratorInterface $requestGenerator = NULL) {
    $this->baseUrl = $baseUrl;
    $this->backendBaseUrl = trim($backendBaseUrl, '/');
    $this->frontendBaseUrl = trim($frontendBaseUrl, '/');
    $this->responseMerger = $responseMerger;
    $this->responseFetcher = $responseFetcher ?: new DefaultResponseFetcher();
    $this->requestGenerator = $requestGenerator ?: new DefaultRequestGenerator($backendBaseUrl, $frontendBaseUrl);
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
    $this->responseFetcher->setHttpAuth($user, $password);
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
      $backend_request = $this->requestGenerator->getBackendRequest($request);
      $frontend_request = $this->requestGenerator->getFrontendRequest($request);
      list($frontend_response, $backend_response) = $this->responseFetcher->fetchResponses($frontend_request, $backend_request);

      if ($this->requestGenerator->isFrontendPage($request)) {
        return $this->convertToSymfonyResponse($frontend_response);
      }

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

}
