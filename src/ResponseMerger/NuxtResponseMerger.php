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

    // Allow passing through responses for XML news feeds or sitemaps.
    if (!empty($header[0]) && (strpos($header[0], 'application/rss+xml') === 0 || strpos($header[0], 'application/xml') === 0)) {
      return $backendResponse;
    }
    elseif (empty($header[0]) || strpos($header[0], 'application/json') !== 0) {
      throw new BadRequestHttpException("Invalid content type requested.");
    }

    $data = json_decode($backendResponse->getBody()->__toString(), TRUE);
    $data += [
      'messages' => [],
      'breadcrumbs' => [],
      'metatags' => [],
      'settings' => [],
    ];

    // Finally, merge responses and serve them.
    $page = $frontendResponse->getBody()->__toString();
    $page = preg_replace("/<title(.*?)>.*?<\/title>/", '<title$1>' . strip_tags($data['title']) . '</title>', $page);
    if ($data['content']) {
      $page = preg_replace("/<main role=\"main\"(.*?)>.*?<\/main>/", '<main role="main"$1>' . $data['content'] . '</main>', $page);
    }
    if (isset($data['breadcrumbs_html'])) {
      $page = preg_replace("/<div class=\"breadcrumbs\"(.*?)>.*?<\/div>/", '<div class="breadcrumbs"$1>' . $data['breadcrumbs_html'] . '</div>', $page);
    }

    // Add metatags to the HTML.
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
    if ($metatags_html) {
      // Limited to 1 to match only <head> and not <header> as well as not having duplicates,
      // <head> will always be the first match as it precedes the document.
      $page = preg_replace("/<head(.*?)>/", '<head$1>' . $metatags_html, $page, 1);
    }

    // Append script element before closing body it will add `window.lupus`
    // global object, this object used to set initial state correctly within
    // the frontend application.
    $init_nuxt_script = $this->getInitNuxtScript($data);
    $page = str_replace('</body>', '<script>' . $init_nuxt_script . '</script></body>', $page);

    // Pipe through the backend response.
    $response = $backendResponse->withBody(\GuzzleHttp\Psr7\stream_for($page));
    return $response->withHeader('Content-Type', 'text/html');
  }

  /**
   * Gets the nuxt init script.
   *
   * @param array $page_data
   *   The page data.
   *
   * @return string
   *   The script code.
   */
  protected function getInitNuxtScript(array $page_data) {
    // Both, breadcrumbs and content is handled differently.
    unset($page_data['breadcrumbs_html'], $page_data['content']);
    $json_data = $page_data + [
      'synced' => FALSE,
    ];
    $json_string = json_encode($json_data);
    return "window.lupus = window.lupus ? window.lupus : {}; window.lupus.initialState = $json_string;" .
      file_get_contents(__DIR__ . '/../../assets/nuxt/initNuxt.js');
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
