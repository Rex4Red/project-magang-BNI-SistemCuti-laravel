<?php

use App\Domain\CutiError;
use App\Domain\CutiStore;
use App\Domain\LeaveService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new LogicException('Worker requires a testing database.');
}
$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($payload['ready'], 'ready');
try {
    $leave = app(LeaveService::class)->submit(app(CutiStore::class)->employee($payload['actor']), $payload['body'], $payload['key']);
    echo json_encode(['result' => 'ok', 'id' => $leave['id']], JSON_THROW_ON_ERROR);
} catch (CutiError $error) {
    echo json_encode(['result' => $error->errorCode, 'status' => $error->status], JSON_THROW_ON_ERROR);
}
