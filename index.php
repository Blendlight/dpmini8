<?php

use Symfony\Component\HttpFoundation\Request;

use Drupal\Core\DrupalKernel;

$autoloader = require_once __DIR__.'/vendor/autoload.php';

$kernel = new DrupalKernel('prod', $autoloader);

$request = Request::createFromGlobals();

$response = $kernel->handle($request);

$response->send();

$kernel->terminate($request, $response);
