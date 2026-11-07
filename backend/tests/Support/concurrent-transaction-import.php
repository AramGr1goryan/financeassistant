<?php

use App\Domain\Transactions\TransactionProcessor;
use App\Models\Account;
use Illuminate\Contracts\Console\Kernel;

$backendPath = dirname(__DIR__, 2);

require $backendPath.'/vendor/autoload.php';

$app = require $backendPath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$account = Account::query()->findOrFail($argv[1]);
$record = json_decode(base64_decode($argv[2], true), true, flags: JSON_THROW_ON_ERROR);
$readyPath = $argv[3];
$releasePath = $argv[4];

if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
    throw new RuntimeException('Unable to signal readiness to the concurrency test.');
}

$deadline = microtime(true) + 30;

while (! file_exists($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Concurrency test did not release the import worker.');
    }

    usleep(10_000);
}

$result = app(TransactionProcessor::class)->process($account, $record);

echo json_encode(['wasCreated' => $result->wasCreated], JSON_THROW_ON_ERROR);
