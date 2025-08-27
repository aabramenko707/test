<?php

namespace Portal\BackgroundProcessing\Task;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Exception;
use Portal\BackgroundProcessing\Task\Exception\AlreadyRunningException;
use Portal\Log\Logger;
use Throwable;


class Manager
{
    protected const LOCK_TIMEOUT_SECONDS = 15;
    protected const WORKER_SCRIPT_PATH = '/local/modules/cli/background_task_worker.php';

    protected const LOG_CATEGORY = 'PORTAL_BACKGROUND_TASK';

    protected static Manager $instance;

    /**
     * __construct
     */
    protected function __construct()
    {
    }

    /**
     * @return void
     */
    protected function __clone()
    {
    }

    /**
     * @return static
     */
    public static function getInstance(): static
    {
        return static::$instance ?? (static::$instance = new static());
    }

    /**
     * @param int $id
     * @return Task|null
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function getById(int $id): ?Task
    {
        return $this->fetch(['=ID' => $id]);
    }

    /**
     * @param string $code
     * @return Task|null
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function getByCode(string $code): ?Task
    {
        return $this->fetch(['=CODE' => $code], ['ID' => 'DESC']);
    }

    /**
     * @param string        $code
     * @param DateTime|null $rangeBegin
     * @param DateTime|null $rangeEnd
     * @param int           $offset
     * @param int           $limit
     * @return Task[]
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function getLast(
        string $code,
        ?DateTime $rangeBegin = null,
        ?DateTime $rangeEnd = null,
        int $offset = 0,
        int $limit = 0
    ): array {
        return $this->fetchList(
            (
                ['=CODE' => $code]
                + (!empty($rangeBegin) ? ['>=LAST_ACTIVITY_DATETIME' => $rangeBegin] : [])
                + (!empty($rangeEnd) ? ['<=LAST_ACTIVITY_DATETIME' => $rangeEnd] : [])
            ),
            ['ID' => 'DESC'],
            $offset,
            $limit
        );
    }

    /**
     * @param class-string<Task> $class
     * @param array              $input
     * @param string             $code
     * @return Task
     * @throws AlreadyRunningException | ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    public function start(string $class, array $input = [], string $code = ''): Task
    {
        $this->lock();
        try {
            if (!empty($code)) {
                $task = $this->fetchRunning($code);
                if (!empty($task)) {
                    throw new AlreadyRunningException($task);
                }
            }

            return $this->create($class, $input, $code);
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param string             $code
     * @param class-string<Task> $class
     * @param array              $input
     * @return Task
     * @throws ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    public function restart(string $code, string $class, array $input = []): Task
    {
        if (empty($code)) {
            throw new Exception('Код задачи не указан');
        }

        $this->lock();
        try {
            $task = $this->fetchRunning($code);
            if (!empty($task)) {
                $task->setState(StateEnum::TERMINATED)->save();
            }

            return $this->create($class, $input, $code);
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param int $taskId
     * @return Task|null
     * @throws ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    public function beginProcessing(int $taskId = 0): ?Task
    {
        $this->lock();
        try {
            $task = $this->fetch(
                ['=STATE' => StateEnum::PENDING->value] + (!empty($taskId) ? ['=ID' => $taskId] : [])
            );
            if (empty($task)) {
                return null;
            }

            return $task->setState(StateEnum::PROCESSING)->save();
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param int $taskId
     * @return Task
     * @throws ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    public function abortProcessing(int $taskId): Task
    {
        $this->lock();
        try {
            $task = $this->getById($taskId);
            if (empty($task)) {
                throw new Exception('Задача ' . $taskId . ' не найдена');
            }

            if ($task->getState()->isFinal()) {
                return $task;
            }

            return $task->setState(StateEnum::TERMINATED)->save();
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param int            $taskId
     * @param Throwable|null $fail
     * @return Task
     * @throws ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    public function endProcessing(int $taskId, ?Throwable $fail = null): Task
    {
        $this->lock();
        try {
            $task = $this->getById($taskId);
            if (empty($task)) {
                throw new Exception('Задача ' . $taskId . ' не найдена');
            }

            if ($task->getState() === StateEnum::TERMINATED) {
                return $task;
            }

            if ($task->getState()->isFinal()) {
                throw new Exception('Задача ' . $taskId . ' уже завершена');
            }

            if ($fail !== null) {
                (new Logger(static::LOG_CATEGORY, $fail))->error('Выполнение задачи ' . $taskId . ' прервано из-за ошибки');
            }

            return $task
                ->setState(empty($fail) ? StateEnum::SUCCEEDED : StateEnum::FAILED)
                ->setProgress(empty($fail) ? 1.0 : $task->getProgress())
                ->save();
        } finally {
            $this->unlock();
        }
    }

    /**
     * @param int $taskId
     * @return $this
     */
    public function forceLaunch(int $taskId): static
    {
        $scriptPath = realpath(Application::getDocumentRoot()) . static::WORKER_SCRIPT_PATH;
        $command = 'php "' . $scriptPath . '" --target-task-id=' . $taskId . ' > /dev/null 2> /dev/null &';

        Application::getInstance()->addBackgroundJob(static fn () => shell_exec($command));

        return $this;
    }

    /**
     * @param class-string<Task> $class
     * @param array              $input
     * @param string             $code
     * @return Task
     * @throws ArgumentException | SystemException | Exception
     */
    protected function create(string $class, array $input, string $code): Task
    {
        if (!class_exists($class)) {
            throw new Exception(sprintf('Класс %s не найден', $class));
        }

        if (!is_subclass_of($class, Task::class)) {
            throw new Exception(sprintf('Класс %s не является подклассом %s', $class, Task::class));
        }

        return (new $class(TaskTable::createObject()->set('CLASS', $class)->set('CODE', $code)))
            ->setState(StateEnum::PENDING)
            ->setInput($input)
            ->save();
    }

    /**
     * @param array $filter
     * @param array $order
     * @param int   $offset
     * @param int   $limit
     * @return Task[]
     * @throws ArgumentException | ObjectPropertyException | SystemException | Exception
     */
    protected function fetchList(array $filter = [], array $order = [], int $offset = 0, int $limit = 0): array
    {
        $collection = TaskTable::query()
            ->setSelect(['*'])
            ->setFilter($filter)
            ->setOrder($order)
            ->setOffset($offset)
            ->setLimit($limit)
            ->fetchCollection();

        $tasks = [];
        foreach ($collection as $object) {
            $class = $object->get('CLASS') ?? '';
            if (!class_exists($class)) {
                throw new Exception('Класс задачи ' . $class . ' не найден');
            }

            $tasks[] = new $class($object);
        }

        return $tasks;
    }

    /**
     * @param array $filter
     * @param array $order
     * @return Task|null
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    protected function fetch(array $filter = [], array $order = []): ?Task
    {
        return $this->fetchList($filter, $order, 0, 1)[0] ?? null;
    }

    /**
     * @param string $code
     * @return Task|null
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    protected function fetchRunning(string $code): ?Task
    {
        return $this->fetch([
            '=CODE' => $code,
            '!=STATE' => array_map(static fn (StateEnum $state): string => $state->value, StateEnum::getFinalCases())
        ]);
    }

    /**
     * @return void
     * @throws Exception
     */
    protected function lock(): void
    {
        if (!Application::getConnection()->lock(static::class, static::LOCK_TIMEOUT_SECONDS)) {
            throw new Exception('Не удалось заблокировать таблицу задач');
        }
    }

    /**
     * @return void
     */
    protected function unlock(): void
    {
        Application::getConnection()->unlock(static::class);
    }
}
