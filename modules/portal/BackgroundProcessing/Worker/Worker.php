<?php

namespace Portal\BackgroundProcessing\Worker;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Portal\BackgroundProcessing\Task\Manager;
use Throwable;

class Worker
{
    /**
     * @param int $targetTaskId
     */
    public function __construct(protected int $targetTaskId = 0)
    {
    }

    /**
     * @return $this
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function run(): static
    {
        while ($task = Manager::getInstance()->beginProcessing($this->targetTaskId)) {
            try {
                $fail = null;
                $task->execute();
            } catch (Throwable $throwable) {
                $fail = $throwable;
            }

            Manager::getInstance()->endProcessing($task->getId(), $fail);
        }

        return $this;
    }
}
