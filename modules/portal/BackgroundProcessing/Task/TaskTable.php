<?php

namespace Portal\BackgroundProcessing\Task;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\Type\DateTime;
use Main\Orm\Fields\SerializedArrayField;

class TaskTable extends DataManager
{
    /**
     * @return string
     */
    public static function getTableName(): string
    {
        return 'portal_background_processing_task';
    }

    /**
     * @return array
     */
    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary()
                ->configureAutocomplete()
                ->configureTitle('ID'),
            (new StringField('CODE'))
                ->configureTitle('Символьный код'),
            (new DatetimeField('INITIALIZING_DATETIME'))
                ->configureRequired()
                ->configureDefaultValue(static fn () => new DateTime())
                ->configureTitle('Время инициализации'),
            (new DatetimeField('LAST_ACTIVITY_DATETIME'))
                ->configureRequired()
                ->configureDefaultValue(static fn () => new DateTime())
                ->configureTitle('Время последней активности'),
            (new EnumField('STATE'))
                ->configureRequired()
                ->configureValues(array_map(static fn (StateEnum $state): string => $state->value, StateEnum::cases()))
                ->configureTitle('Состояние'),
            (new StringField('CLASS'))
                ->configureRequired()
                ->configureTitle('Класс задачи'),
            (new SerializedArrayField('INPUT'))
                ->configureDefaultValue([])
                ->configureTitle('Массив входных данных'),
            (new FloatField('PROGRESS'))
                ->configureRequired()
                ->configureDefaultValue(0.0)
                ->configureTitle('Уровень прогресса'),
            (new SerializedArrayField('OUTPUT'))
                ->configureDefaultValue([])
                ->configureTitle('Массив выходных данных'),
        ];
    }

    /**
     * @param Event $event
     * @return EventResult
     */
    public static function onBeforeUpdate(Event $event): EventResult
    {
        $result = new EventResult();

        if (!isset($event->getParameter('fields')['LAST_ACTIVITY_DATETIME'])) {
            $result->modifyFields(['LAST_ACTIVITY_DATETIME' => new DateTime()]);
        }

        return $result;
    }
}
