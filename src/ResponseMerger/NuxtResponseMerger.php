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

    $data = json_decode($backendResponse->getBody()->__toString(), TRUE);

    // Finally, merge responses and serve them.
    $page = $frontendResponse->getBody()->__toString();
    $page = preg_replace('/<title(.*)><\/title>/', '<title$1>' . $data['title'] . '</title>', $page);
    $page = preg_replace('/<main role="main"(.*)><\/main>/', '<main role="main"$1>' . $data['content'] . '</main>', $page);

    // Append script element before closing body it will add `window.lupus`
    // global object, this object used to set initial state correctly within
    // the frontend application.
    $init_nuxt_script = file_get_contents(__DIR__ . '/../../assets/nuxt/initNuxt.js');

    // Prepare breadcrumbs.
    if (isset($data['breadcrumbs'])) {
      $init_nuxt_script = str_replace('breadcrumbs: { }', 'breadcrumbs: ' . json_encode($data['breadcrumbs']), $init_nuxt_script);
    }

    // Prepare breadcrumbs HTML.
    if (isset($data['breadcrumbs_html'])) {
      $page = preg_replace('/<div class="breadcrumbs"(.*)><\/div>/', '<div class="breadcrumbs"$1>' . $data['breadcrumbs_html'] . '</div>', $page);
    }

    // Prepare metatags.
    if (isset($data['metatags'])) {
      $init_nuxt_script = str_replace('metatags: { }', 'metatags: ' . json_encode($data['metatags']), $init_nuxt_script);

      $metatags_html = '';
      foreach ($data['metatags'] as $tag_type => $metatags_data) {
        foreach ($metatags_data as $properties) {
          $attributes_string = "";
          foreach ($properties as $attribute => $value) {
            $attributes_string .= " " . $attribute . "='" . $value . "'";
          }
          $metatags_html .= "<$tag_type data-source='backend'$attributes_string/>";
        }
      }

      if ($metatags_html != '') {
        $page = preg_replace('/<head(.*)>/', '<head$1>' . $metatags_html, $page);
      }
    }

    $lupus_settings = isset($data['settings']) ? json_encode($data['settings']) : '{}';
    $page = str_replace('</body>', '<script>window.lupus = {settings : ' . $lupus_settings . '};' . $init_nuxt_script . '</script></body>', $page);

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
