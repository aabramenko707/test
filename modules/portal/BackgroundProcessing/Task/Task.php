<?php

namespace Portal\BackgroundProcessing\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Objectify\EntityObject;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Exception;

abstract class Task
{
    /**
     * @param EntityObject $object
     */
    public function __construct(protected EntityObject $object)
    {
    }

    /**
     * @return int
     * @throws SystemException
     */
    public function getId(): int
    {
        return $this->object->getId() ?? 0;
    }

    /**
     * @return StateEnum
     * @throws ArgumentException | SystemException
     */
    public function getState(): StateEnum
    {
        return StateEnum::from($this->object->get('STATE') ?? '');
    }

    /**
     * @param StateEnum $state
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function setState(StateEnum $state): static
    {
        $this->object->set('STATE', $state->value);

        return $this;
    }

    /**
     * @return bool
     * @throws ArgumentException | SystemException
     */
    public function isTerminated(): bool
    {
        return $this->getState() == StateEnum::TERMINATED;
    }

    /**
     * @return array
     * @throws ArgumentException | SystemException
     */
    public function getInput(): array
    {
        return $this->object->get('INPUT');
    }

    /**
     * @param array $input
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function setInput(array $input): static
    {
        $this->object->set('INPUT', $input);

        return $this;
    }

    /**
     * @return float
     * @throws ArgumentException | SystemException
     */
    public function getProgress(): float
    {
        return $this->object->get('PROGRESS');
    }

    /**
     * @param float $progress
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function setProgress(float $progress): static
    {
        $this->object->set('PROGRESS', $progress);

        return $this;
    }

    /**
     * @return array
     * @throws ArgumentException | SystemException
     */
    public function getOutput(): array
    {
        return $this->object->get('OUTPUT');
    }

    /**
     * @param array $output
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function setOutput(array $output): static
    {
        $this->object->set('OUTPUT', $output);

        return $this;
    }

    /**
     * @return DateTime
     * @throws ArgumentException | SystemException
     */
    public function getInitializingDatetime(): DateTime
    {
        return $this->object->get('INITIALIZING_DATETIME');
    }

    /**
     * @return DateTime
     * @throws ArgumentException | SystemException
     */
    public function getLastActivityDatetime(): DateTime
    {
        return $this->object->get('LAST_ACTIVITY_DATETIME');
    }

    /**
     * @return $this
     * @throws SystemException | ArgumentException
     */
    public function save(): static
    {
        $objectBeforeSave = clone $this->object;

        $saveResult = $this->object->save();
        if (!$saveResult->isSuccess()) {
            throw new Exception(
                'Не удалось сохранить задачу: ' . implode(', ', $saveResult->getErrorMessages())
            );
        }

        if ($objectBeforeSave->remindActual('STATE') !== $this->object->get('STATE')) {
            $this->onStateChanged(
                (
                    !empty($objectBeforeSave->remindActual('STATE'))
                        ? StateEnum::from($objectBeforeSave->remindActual('STATE'))
                        : null
                ),
                StateEnum::from($this->object->get('STATE'))
            );
        }

        return $this;
    }

    /**
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function refresh(): static
    {
        foreach ($this->object->entity->getFields() as $field) {
            if ($field->getName() == 'ID') {
                continue;
            }

            $this->object->unset($field->getName());
        }

        $this->object->fill();

        return $this;
    }

    /**
     * @return $this
     */
    abstract public function execute(): static;

    /**
     * @param StateEnum|null $previousState
     * @param StateEnum      $currentState
     * @return void
     */
    protected function onStateChanged(?StateEnum $previousState, StateEnum $currentState): void
    {
    }
}
