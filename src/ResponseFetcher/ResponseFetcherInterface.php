<?php

namespace drunomics\LupusFrontProxy\ResponseFetcher;

use Psr\Http\Message\RequestInterface;

/**
 * Interface for request fetcher.
 */
interface ResponseFetcherInterface {

  /**
   * Fetch responses for the given requests.
   *
   * @param \Psr\Http\Message\RequestInterface $frontend_request
   *   The frontend request.
   * @param \Psr\Http\Message\RequestInterface $backend_request
   *   The backend request.
   *
   * @return \Psr\Http\Message\ResponseInterface[]
   *   A numerical indexed array with two entries, the frontend and backend
   *   response.
   */
  public function fetchResponses(RequestInterface $frontend_request, RequestInterface $backend_request);

}
