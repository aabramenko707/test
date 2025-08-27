<?php

namespace Portal\BackgroundProcessing\Task\Exception;

use Exception;
use Portal\BackgroundProcessing\Task\Task;

class AlreadyRunningException extends Exception
{
    /**
     * @param Task $task
     */
    public function __construct(protected Task $task)
    {
        parent::__construct('Задача уже выполняется');
    }

    /**
     * @return Task
     */
    public function getTask(): Task
    {
        return $this->task;
    }
}
