<?php

/**
 * @file
 * Example index implementation.
 */

require __DIR__ . '/../vendor/autoload.php';

use drunomics\LupusFrontProxy\RequestHandler;
use drunomics\LupusFrontProxy\ResponseMerger\NuxtResponseMerger;
use Symfony\Component\HttpFoundation\Request;

// Figure out the name of the current multi-site first.
// Then use it to initialize dotenv.
$symfony_request = Request::createFromGlobals();

// Read dotenv and pass it on.
$base_url = getenv('PHAPP_BASE_URL');
$backend_url = getenv('SITE_BACKEND_BASE_URL');
$frontend_url = getenv('SITE_FRONTEND_BASE_URL');

(new RequestHandler($base_url, $backend_url, $frontend_url, new NuxtResponseMerger()))
  ->setHttpAuth(getenv('HTTP_AUTH_USER'), getenv('HTTP_AUTH_PASSWORD'))
  ->handle($symfony_request)
  ->send();
