<?php

namespace drunomics\LupusFrontProxy\ResponseFetcher;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
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
  public function setHttpAuth($user, $password) {
    if ($user && $password) {
      $this->guzzleConfig['auth'] = [$user, $password];
    }
    else {
      unset($this->guzzleConfig['auth']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function fetchResponses(RequestInterface $frontend_request, RequestInterface $backend_request) {
    $client = new Client($this->guzzleConfig);

    $backend_response = $client->sendAsync($backend_request, [
      'allow_redirects' => FALSE,
      'http_errors' => FALSE,
    ]);
    // Try fetching static html via filesystem
    $root = getenv('PWD');
    $site = getenv('SITE');
    $request_body = file_get_contents($root . '/frontend/public/' . $site . '/layout--default.html');
    if ($request_body) {
      $frontend_response = new Response(200,[],$request_body);
    }
    else {
      // Fallback to letting server handle the request
      $frontend_response = $client->sendAsync($frontend_request);
      // Resolve the frontend promise first since static files should be always
      // faster than the backend.
      /** @var \Psr\Http\Message\ResponseInterface $frontend_response */
      $frontend_response = $frontend_response->wait();
    }

    /** @var \Psr\Http\Message\ResponseInterface $backend_response */
    $backend_response = $backend_response->wait();
    return [$frontend_response, $backend_response];
  }

}
