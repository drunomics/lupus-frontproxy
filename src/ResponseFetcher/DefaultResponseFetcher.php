<?php

namespace drunomics\LupusFrontProxy\ResponseFetcher;

use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;

/**
 * Default response fetching routine.
 */
class DefaultResponseFetcher implements ResponseFetcherInterface {

  /**
   * Guzzle config for configuring request option defaults.
   *
   * @var array
   */
  protected $guzzleConfig = [];

  /**
   * {@inheritdoc}
   */
  public function fetchResponses(RequestInterface $frontend_request, RequestInterface $backend_request) {
    $client = new Client($this->guzzleConfig);

    $backend_response = $client->sendAsync($backend_request, [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);
    $frontend_response = $client->sendAsync($frontend_request);

    // Resolve the frontend promise first since static files should be always
    // faster than the backend.
    /** @var \Psr\Http\Message\ResponseInterface $frontend_response */
    /** @var \Psr\Http\Message\ResponseInterface $backend_response */
    $frontend_response = $frontend_response->wait();
    $backend_response = $backend_response->wait();
    return [$frontend_response, $backend_response];
  }

}
