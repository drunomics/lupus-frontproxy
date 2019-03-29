<?php

namespace drunomics\LupusFrontProxy\ResponseMerger;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A response merger suiting for nuxt-based frontends.
 *
 * It only handles JSON requests and pipes through backend respond headers, so
 * the backend may set cookies etc.
 */
class NuxtResponseMerger implements ResponseMergerInterface {

  /**
   * {@inheritdoc}
   */
  public function mergeResponses(ResponseInterface $backendResponse, ResponseInterface $frontendResponse) {
    // Only deal with JSON responses, no assets.
    $header = $backendResponse->getHeader('Content-Type');

    if (empty($header[0]) || strpos($header[0], 'application/json') !== 0) {
      throw new BadRequestHttpException("Invalid content type requested.");
    }

    $data = json_decode($backendResponse->getBody()->getContents(), TRUE);

    // Finally, merge responses and serve them.
    $page = $frontendResponse->getBody()->getContents();
    $page = str_replace('<main role="main" class="site__content"></main>', '<main role="main" class="site__content">' . $data['content'] . '</main>', $page);

    // Make NUXT hydrate with correct state; i.e. pick up the content and render.
    // Fort that replace the script tag that initialize the __NUXT__ variable.
    $init_nuxt_script = file_get_contents(__DIR__ . '/../../assets/nuxt/initNuxt.js');
    $page .= '<script>' . $init_nuxt_script . '</script>';

    // Pipe through the backend response.
    $response = $backendResponse->withBody(\GuzzleHttp\Psr7\stream_for($page));
    return $response->withHeader('Content-Type', 'text/html');
  }

  /**
   * {@inheritdoc}
   */
  public function getErrorResponse(ResponseInterface $response) {
    $error_html = file_get_contents(__DIR__ . '/../../assets/error.html');
    $error_html = str_replace('{{ error }}', strip_tags($response->getReasonPhrase()), $error_html);
    $response = new Response($error_html);
    $response->setStatusCode(500);
    return $response;
  }

}
