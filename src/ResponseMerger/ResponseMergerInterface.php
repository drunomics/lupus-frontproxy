<?php

namespace drunomics\LupusFrontProxy\ResponseMerger;

use Psr\Http\Message\ResponseInterface;

/**
 * Interface for response mergers.
 */
interface ResponseMergerInterface {

  /**
   * Merges the given frontend and backend responses.
   *
   * @param \Psr\Http\Message\ResponseInterface $backendResponse
   * @param \Psr\Http\Message\ResponseInterface $frontendResponse
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown if for some reason the requests should not be processed.
   */
  public function mergeResponses(ResponseInterface $backendResponse, ResponseInterface $frontendResponse);

  /**
   * Provides an error repsonse.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The backend response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function getErrorResponse(ResponseInterface $response);

}
