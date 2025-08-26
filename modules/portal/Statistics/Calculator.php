<?php

namespace Portal\Statistics;

use Bitrix\Crm\Category\Entity\DealCategoryTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Crm\DealTable;
use Exception;
use Portal\Crm\FeedbackForm;
use Portal\Crm\Dynamic\Dynamic;
use Main\Iblock\Iblock;
use Main\UserField\EnumTable;
use Portal\Crm\Deal;

class Calculator
{
    protected const NO_ANSWER_CODE = 'no_answer';

    protected static Calculator $instance;

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
     * @param Filter $filter
     * @return float|null
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateNpsValue(Filter $filter): ?float
    {
        $statistics = $this->getLoyaltyStatistics($filter);

        if (empty($statistics['TOTAL_COUNT'])) {
            return null;
        }

        return ($statistics['SUPPORTER_COUNT'] - $statistics['CRITIC_COUNT']) / $statistics['TOTAL_COUNT'];
    }

    /**
     * @param array  $fieldNames
     * @param Filter $filter
     * @return float|null
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateCsiValue(array $fieldNames, Filter $filter): ?float
    {
        if (empty($fieldNames)) {
            throw new Exception('Не указаны поля для расчета CSI');
        }

        $query = new Query($this->createStatisticsQuery(
            array_merge(...array_map(
                static fn (string $fieldName): array => [
                    ($fieldName . '_IMPORTANCE') => ('IMPORTANCE_' . $fieldName . '.XML_ID'),
                    ($fieldName . '_SATISFACTION') => ('SATISFACTION_' . $fieldName . '.XML_ID')
                ],
                $fieldNames
            )),
            $filter
        ));

        foreach ($fieldNames as $fieldName) {
            $query
                ->whereNotNull($fieldName . '_IMPORTANCE')
                ->whereNotNull($fieldName . '_SATISFACTION')
                ->addSelect(Query::expr()->avg($fieldName . '_IMPORTANCE'), $fieldName . '_WEIGHT')
                ->addSelect(Query::expr()->avg($fieldName . '_SATISFACTION'), $fieldName . '_VALUE');
        }

        $statistics = $query->fetch();

        if (empty($statistics)) {
            return null;
        }

        $weightSum = array_sum(array_intersect_key($statistics, array_flip(array_map(
            static fn (string $fieldName): string => ($fieldName . '_WEIGHT'),
            $fieldNames
        ))));

        if (empty($weightSum)) {
            return null;
        }

        return array_sum(array_map(
            static fn (string $fieldName): float => (
                0.1 * $statistics[$fieldName . '_VALUE'] * $statistics[$fieldName . '_WEIGHT'] / $weightSum
            ),
            $fieldNames
        ));
    }

    /**
     * @param Filter $filter
     * @return array[]|null[]
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateLoyaltyValues(Filter $filter): array
    {
        $statistics = $this->getLoyaltyStatistics($filter);

        if (empty($statistics['TOTAL_COUNT'])) {
            return [null, null, null];
        }

        return [
            [(int)$statistics['SUPPORTER_COUNT'], $statistics['SUPPORTER_COUNT'] / $statistics['TOTAL_COUNT']],
            [(int)$statistics['NEUTRAL_COUNT'], $statistics['NEUTRAL_COUNT'] / $statistics['TOTAL_COUNT']],
            [(int)$statistics['CRITIC_COUNT'], $statistics['CRITIC_COUNT'] / $statistics['TOTAL_COUNT']],
        ];
    }

    /**
     * @param Filter $filter
     * @return array|null
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateFormProgressValue(Filter $filter): ?array
    {
        $joinCondition = Join::on('this.FEEDBACK_FORM_ID', 'ref.ID');

        if (!empty($filter->getRangeBegin())) {
            $joinCondition->where('ref.CREATED_TIME', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $joinCondition->where('ref.CREATED_TIME', '<=', $filter->getRangeEnd());
        }

        $query = FeedbackForm\WebFormRelationToken\WebFormRelationTokenTable::query()
            ->registerRuntimeField(new Reference(
                'SCOPE_FEEDBACK_FORM',
                Dynamic::feedbackFormTable(),
                $joinCondition
            ))
            ->addSelect('ID')
            ->addSelect(new ExpressionField('IS_FILLED', '%s IS NOT NULL', 'SCOPE_FEEDBACK_FORM.ID'));

        if (!empty($filter->getRangeBegin())) {
            $query->where('CREATED_TIME', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $query->where('CREATED_TIME', '<=', $filter->getRangeEnd());
        }

        if (!empty($filter->getFormCodes())) {
            $query->whereIn('CRM_WEBFORM.XML_ID', $filter->getFormCodes());
        }

        if (!empty($filter->getContactIds())) {
            $query->whereIn('CONTACT_ID', $filter->getContactIds());
        }

        if (!empty($filter->getEducationProductIds())) {
            $query->whereIn('EDUCATION_PRODUCT_ID', $filter->getEducationProductIds());
        }

        $consultationFilter = Query::filter();

        if (!empty($filter->getConsultationSupportCodes())) {
            $consultationFilter->whereIn('ref.UF_SUPPORT_CODE', $filter->getConsultationSupportCodes());
        }

        if (!empty($filter->getConsultationDepartmentIds())) {
            $consultationFilter->whereIn('ref.UF_DEPARTMENT_ID', $filter->getConsultationDepartmentIds());
        }

        if ($consultationFilter->hasConditions()) {
            $query
                ->registerRuntimeField(new Reference(
                    'SCOPE_CONSULTATION',
                    Entity::getInstanceByQuery(
                        DealTable::query()->setSelect(['ID', 'UF_SUPPORT_CODE', 'UF_DEPARTMENT_ID'])
                    ),
                    Join::on('this.CONSULT_ID', 'ref.ID')->where($consultationFilter)
                ))
                ->where(
                    Query::filter()->logic('or')
                        ->whereNotIn('CRM_WEBFORM.XML_ID', Filter::convertCategoryCodeToTypeCodes(
                            FeedbackForm\Category::RESULTS_OF_A_TELEPHONE_SURVEY->name
                        ))
                        ->whereNotNull('SCOPE_CONSULTATION.ID')
                );
        }

        $eventFilter = Query::filter();

        if (!empty($filter->getEventResponsibleDepartmentIds())) {
            $eventFilter->whereIn('ref.UF_RESPONSIBLE_DEPARTMENT', $filter->getEventResponsibleDepartmentIds());
        }

        if ($eventFilter->hasConditions()) {
            $query
                ->registerRuntimeField(new Reference(
                    'SCOPE_EVENT',
                    Dynamic::eventTable(),
                    Join::on('this.EVENT_ID', 'ref.ID')->where($eventFilter)
                ))
                ->where(
                    Query::filter()->logic('or')
                        ->whereNotIn('CRM_WEBFORM.XML_ID', Filter::convertCategoryCodeToTypeCodes(
                            FeedbackForm\Category::EVENT_PARTICIPANT->name
                        ))
                        ->whereNotNull('SCOPE_EVENT.ID')
                );
        }

        $serviceFilter = Query::filter();

        if (!empty($filter->getOnlineServiceIds())) {
            $serviceFilter->whereIn('ref.ID', $filter->getOnlineServiceIds());
        }

        if ($serviceFilter->hasConditions()) {
            $query
                ->registerRuntimeField(new Reference(
                    'SCOPE_SERVICE',
                    Iblock::getDataClass('servicesMbmServices'),
                    Join::on('this.ONLINE_SERVICE_CODE', 'ref.CODE')->where($serviceFilter)
                ))
                ->where(
                    Query::filter()->logic('or')
                        ->whereNotIn('CRM_WEBFORM.XML_ID', Filter::convertCategoryCodeToTypeCodes(
                            FeedbackForm\Category::ONLINE_SERVICES_EVALUATION->name
                        ))
                        ->whereNotNull('SCOPE_SERVICE.ID')
                );
        }

        $statistics = (new Query($query))
            ->addSelect(Query::expr()->count('ID'), 'TOTAL_COUNT')
            ->addSelect(Query::expr()->sum('IS_FILLED'), 'FILLED_COUNT')
            ->fetch();

        if (empty($statistics['TOTAL_COUNT'])) {
            return null;
        }

        return [(int)$statistics['FILLED_COUNT'], $statistics['FILLED_COUNT'] / $statistics['TOTAL_COUNT']];
    }

    /**
     * @param string $fieldName
     * @param Filter $filter
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateLearnedFromSourceValues(string $fieldName, Filter $filter): array
    {
        $values = EnumTable::query()->setSelect(['XML_ID', 'VALUE'])
            ->where('FIELD.ENTITY_ID', '=', Dynamic::feedbackForm()->getUserFieldEntityId())
            ->where('FIELD.FIELD_NAME', '=', ('UF_' . $fieldName))
            ->setOrder(['SORT' => 'ASC'])
            ->fetchAll();

        $values[] = ['XML_ID' => static::NO_ANSWER_CODE, 'VALUE' => 'Нет ответа'];

        $query = new Query(
            (new Query($this->createStatisticsQuery(['FIELD_VALUE' => $fieldName . '.XML_ID'], $filter)))->addSelect(
                new ExpressionField('ANSWER_CODE', 'COALESCE(%s, \'' . static::NO_ANSWER_CODE . '\')', 'FIELD_VALUE')
            )
        );

        $aggregation = $query
            ->setGroup(['ANSWER_CODE'])
            ->addSelect('ANSWER_CODE')
            ->addSelect(Query::expr()->count('ANSWER_CODE'), 'COUNT')
            ->fetchAll();

        $aggregation = array_column($aggregation, 'COUNT', 'ANSWER_CODE');

        $formCount = array_sum($aggregation);

        $answerCount = array_sum(array_filter(
            $aggregation,
            static fn (string $key): bool => ($key != static::NO_ANSWER_CODE),
            ARRAY_FILTER_USE_KEY
        ));

        foreach ($values as $key => $value) {
            $code = $value['XML_ID'];

            $count = (int)($aggregation[$code] ?? 0);
            $totalCount = ($code == static::NO_ANSWER_CODE ? $formCount : $answerCount);

            $values[$key]['PERCENT'] = (!empty($totalCount) ? ($count / $totalCount) : null);
        }

        return $values;
    }

    /**
     * @param Filter $filter
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateConsultationValues(Filter $filter): array
    {
        $joinCondition = Join::on('this.ID', 'ref.UF_CONSULTATION');

        if (!empty($filter->getRangeBegin())) {
            $joinCondition->where('ref.CREATED_TIME', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $joinCondition->where('ref.CREATED_TIME', '<=', $filter->getRangeEnd());
        }

        $query = DealTable::query()
            ->where('CATEGORY_ID', '=', Deal\DealCategoryEnum::CONSULT->id())
            ->registerRuntimeField(new Reference(
                'SCOPE_FEEDBACK_FORM',
                Dynamic::feedbackFormTable(),
                $joinCondition
            ))
            ->addSelect('ID')
            ->addSelect(new ExpressionField('IS_FILLED', '%s IS NOT NULL', 'SCOPE_FEEDBACK_FORM.ID'));

        if (!empty($filter->getRangeBegin())) {
            $query->where('UF_SUPPORT_DATE', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $query->where('UF_SUPPORT_DATE', '<=', $filter->getRangeEnd());
        }

        if (!empty($filter->getConsultationSupportCodes())) {
            $query->whereIn('UF_SUPPORT_CODE', $filter->getConsultationSupportCodes());
        }

        if (!empty($filter->getConsultationDepartmentIds())) {
            $query->whereIn('UF_DEPARTMENT_ID', $filter->getConsultationDepartmentIds());
        }

        if (!empty($filter->getContactIds())) {
            $query->whereIn('CONTACT_ID', $filter->getContactIds());
        }

        $statistics = (new Query($query))
            ->addSelect(Query::expr()->count('ID'), 'TOTAL_COUNT')
            ->addSelect(Query::expr()->sum('IS_FILLED'), 'FILLED_COUNT')
            ->fetch();

        if (empty($statistics['TOTAL_COUNT'])) {
            return [0, null];
        }

        return [
            (int)$statistics['TOTAL_COUNT'],
            [(int)$statistics['FILLED_COUNT'], $statistics['FILLED_COUNT'] / $statistics['TOTAL_COUNT']]
        ];
    }

    /**
     * @param Filter $filter
     * @return int[]
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function calculateEventValues(Filter $filter): array
    {
        $query = Dynamic::eventTable()::query()
            ->addSelect(Query::expr()->count('ID'), 'EVENT_COUNT')
            ->addSelect(Query::expr()->sum('UF_REGISTERED_PARTICIPANT_COUNT'), 'PARTICIPANT_COUNT');

        if (!empty($filter->getRangeBegin())) {
            $query->where('UF_TIME_TO', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $query->where('UF_TIME_FROM', '<=', $filter->getRangeEnd());
        }

        if (!empty($filter->getEventResponsibleDepartmentIds())) {
            $query->whereIn('UF_RESPONSIBLE_DEPARTMENT', $filter->getEventResponsibleDepartmentIds());
        }

        $statistics = $query->fetch();

        return [(int)($statistics['EVENT_COUNT'] ?? 0), (int)($statistics['PARTICIPANT_COUNT'] ?? 0)];
    }

    /**
     * @param array  $select
     * @param Filter $filter
     * @return Query
     * @throws ArgumentException
     * @throws SystemException
     */
    protected function createStatisticsQuery(array $select, Filter $filter): Query
    {
        $sliceQuery = Dynamic::feedbackFormTable()::query()
            ->setSelect(['ID'])
            ->whereNotNull('WEBFORM_RELATION_TOKEN.ID');

        if (!empty($filter->getRangeBegin())) {
            $sliceQuery->where('CREATED_TIME', '>=', $filter->getRangeBegin());
        }

        if (!empty($filter->getRangeEnd())) {
            $sliceQuery->where('CREATED_TIME', '<=', $filter->getRangeEnd());
        }

        if (!empty($filter->getCategoryCodes())) {
            $sliceQuery->whereIn('CATEGORY_ID', array_map(
                static fn (string $code): int => Dynamic::feedbackForm()->getCategoryByCode($code)->getId(),
                $filter->getCategoryCodes()
            ));
        }

        if (!empty($filter->getContactIds())) {
            $sliceQuery->whereIn('CONTACT_ID', $filter->getContactIds());
        }

        if (!empty($filter->getEducationProductIds())) {
            $sliceQuery->whereIn('UF_EDUCATION_PRODUCT', $filter->getEducationProductIds());
        }

        $consultationFilter = Query::filter();

        if (!empty($filter->getConsultationSupportCodes())) {
            $consultationFilter->whereIn('ref.UF_SUPPORT_CODE', $filter->getConsultationSupportCodes());
        }

        if (!empty($filter->getConsultationDepartmentIds())) {
            $consultationFilter->whereIn('ref.UF_DEPARTMENT_ID', $filter->getConsultationDepartmentIds());
        }

        if ($consultationFilter->hasConditions()) {
            $sliceQuery
                ->registerRuntimeField(new Reference(
                    'SCOPE_CONSULTATION',
                    Entity::getInstanceByQuery(
                        DealTable::query()->setSelect(['ID', 'UF_SUPPORT_CODE', 'UF_DEPARTMENT_ID'])
                    ),
                    Join::on('this.UF_CONSULTATION', 'ref.ID')->where($consultationFilter)
                ))
                ->where(
                    Query::filter()->logic('or')
                        ->whereNot(
                            'CATEGORY_ID',
                            '=',
                            Dynamic::feedbackForm()
                                ->getCategoryByCode(FeedbackForm\Category::RESULTS_OF_A_TELEPHONE_SURVEY->name)
                                ->getId()
                        )
                        ->whereNotNull('SCOPE_CONSULTATION.ID')
                );
        }

        $eventFilter = Query::filter();

        if (!empty($filter->getEventResponsibleDepartmentIds())) {
            $eventFilter->whereIn('ref.UF_RESPONSIBLE_DEPARTMENT', $filter->getEventResponsibleDepartmentIds());
        }

        if ($eventFilter->hasConditions()) {
            $sliceQuery
                ->registerRuntimeField(new Reference(
                    'SCOPE_EVENT',
                    Dynamic::eventTable(),
                    Join::on('this.UF_EVENT', 'ref.ID')->where($eventFilter)
                ))
                ->where(
                    Query::filter()->logic('or')
                        ->whereNot(
                            'CATEGORY_ID',
                            '=',
                            Dynamic::feedbackForm()
                                ->getCategoryByCode(FeedbackForm\Category::EVENT_PARTICIPANT->name)
                                ->getId() // TODO: прикрутить метод получения id из FeedbackForm\Category
                        )
                        ->whereNotNull('SCOPE_EVENT.ID')
                );
        }

        if (!empty($filter->getOnlineServiceIds())) {
            $sliceQuery->where(
                Query::filter()->logic('or')
                    ->whereNot(
                        'CATEGORY_ID',
                        '=',
                        Dynamic::feedbackForm()
                            ->getCategoryByCode(FeedbackForm\Category::ONLINE_SERVICES_EVALUATION->name)
                            ->getId()
                    )
                    ->whereIn('UF_ONLINE_SERVICE', $filter->getOnlineServiceIds())
            );
        }

        $feedbackQuery = (new Query($sliceQuery))->registerRuntimeField(new Reference(
            'THIS',
            Dynamic::feedbackFormTable(),
            Join::on('this.ID', 'ref.ID')
        ));

        foreach ($select as $alias => $chain) {
            $feedbackQuery->addSelect('THIS.' . $chain, $alias);
        }

        return $feedbackQuery;
    }

    /**
     * @param Filter $filter
     * @return array
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    protected function getLoyaltyStatistics(Filter $filter): array
    {
        $query = (new Query($this->createStatisticsQuery(['SCORE' => 'RECOMMEND_TO_PARTNERS.XML_ID'], $filter)))
            ->whereNotNull('SCORE')
            ->addSelect('SCORE')
            ->addSelect(new ExpressionField(
                'IS_SUPPORTER_FEEDBACK',
                '%s IN (\'9\', \'10\')',
                'SCORE'
            ))
            ->addSelect(new ExpressionField(
                'IS_NEUTRAL_FEEDBACK',
                '%s IN (\'7\', \'8\')',
                'SCORE'
            ))
            ->addSelect(new ExpressionField(
                'IS_CRITIC_FEEDBACK',
                '%s IN (\'0\', \'1\', \'2\', \'3\', \'4\', \'5\', \'6\')',
                'SCORE'
            ));

        return (new Query($query))
            ->addSelect(Query::expr()->count('SCORE'), 'TOTAL_COUNT')
            ->addSelect(Query::expr()->sum('IS_SUPPORTER_FEEDBACK'), 'SUPPORTER_COUNT')
            ->addSelect(Query::expr()->sum('IS_NEUTRAL_FEEDBACK'), 'NEUTRAL_COUNT')
            ->addSelect(Query::expr()->sum('IS_CRITIC_FEEDBACK'), 'CRITIC_COUNT')
            ->fetch();
    }
}
