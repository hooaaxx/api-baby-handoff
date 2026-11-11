<?php
// Dispatches one synthetic HTTP request to Laravel without opening a listening socket.
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$app = require __DIR__.'/../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$headers = ['HTTP_ACCEPT'=>'application/json', 'CONTENT_TYPE'=>'application/json'];
if (!empty($input['token'])) {
    $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$input['token'];
}
$body = isset($input['data']) ? json_encode($input['data'], JSON_THROW_ON_ERROR) : null;
$request = Request::create('/api/v1'.$input['path'], $body === null ? 'GET' : 'POST', [], [], [], $headers, $body);
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request);
echo json_encode(['status'=>$response->getStatusCode(), 'body'=>$response->getContent()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
