<?php

namespace drunomics\LupusFrontProxy\RequestGenerator;

use Symfony\Component\HttpFoundation\Request;

/**
 * The default request generator.
 */
interface RequestGeneratorInterface {

  /**
   * Generates the backend request.
   *
   * By default forward the request headers to the backend.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming http request.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The backend request.
   */
  public function getBackendRequest(Request $request);

  /**
   * Generates the frontend request for fetching the page shell.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming http request.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The frontend request.
   */
  public function getFrontendRequest(Request $request);

}
