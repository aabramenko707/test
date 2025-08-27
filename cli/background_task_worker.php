<?php

use Bitrix\Main\Application;
use Portal\BackgroundProcessing\Worker\Worker;
use Portal\Log\Logger;

$status = 0;

try {
    /**
     * @global CMain $APPLICATION
     */

    require 'bootstrap.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

    set_time_limit(0);

    $APPLICATION->RestartBuffer();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $targetTaskId = (getopt('', ['target-task-id:'])['target-task-id'] ?? 0);

    try {
        (new Worker($targetTaskId))->run();
    } catch (Throwable $throwable) {
        (new Logger('PORTAL_BACKGROUND_TASK_WORKER', $throwable))->error('Ошибка при обработке фоновых задач');
        $status = 1;
    }

    Application::getInstance()->end($status);
} catch (Throwable $throwable) {
    fwrite(STDERR, ($throwable->getMessage() . PHP_EOL . $throwable->getTraceAsString() . PHP_EOL));
    exit(1);
}
