<?php

use Bitrix\Crm\Component\EntityList\GridId;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\UI\Filter;
use Bitrix\Main\Engine\Response\File as FileResponse;
use Portal\BackgroundProcessing\Task;
use Portal\Crm\Dynamic\Dynamic;
use Portal\Crm\FeedbackForm;
use Portal\Statistics;
use Portal\Intranet\Department\DepartmentTable;
use Portal\Log\Logger;
use Main\Iblock\Iblock;
use Bitrix\Crm\Integration\Main\UISelector\Handler;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:ignore Squiz.Classes.ClassFileName
class FeedbackStatisticsComponent extends CBitrixComponent implements Controllerable
{
    public const HOURS_TO_KEEP_EXPORT_FILES = 24;

    protected const LOG_CATEGORY = 'COMPONENT_FEEDBACK_STATISTICS';

    protected const FILTER_ID_PREFIX = 'PORTAL_FEEDBACK_STATISTICS_';

    protected const EVENT_REPORT_TYPE = 'events';

    protected const CONSULTATIONS_REPORT_TYPE = 'consultations';

    protected const SERVICES_REPORT_TYPE = 'services';

    protected const EDUCATION_PRODUCT_TYPE = 'education_product';

    protected const NO_DATA_STUB = 'нет данных';

    /**
     * @return array
     */
    public function configureActions(): array
    {
        return [
            'downloadExportFile' => [
                '-prefilters' => [ActionFilter\Csrf::class]
            ]
        ];
    }

    /**
     * @return array
     * @throws Throwable
     */
    public function executeComponent(): array
    {
        try {
            $reportType = $this->resolveReportType();

            $this->arResult = [
                'REPORT_TYPE' => $reportType,
                'FILTER_SETTINGS' => $this->getFilterSettings($reportType)
            ];

            $this->includeComponentTemplate('report');

            return ($this->arResult['OUTPUT'] ?? []);
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка компонента');
            throw $throwable;
        }
    }

    /**
     * @param int|null $value
     * @return string
     */
    public function formatCount(?int $value): string
    {
        return (isset($value) ? (string)$value : static::NO_DATA_STUB);
    }

    /**
     * @param float|null $value
     * @return string
     */
    public function formatPercent(?float $value): string
    {
        return isset($value)
            ? (number_format(100.0 * $value, 2, ',') . '%')
            : static::NO_DATA_STUB;
    }

    /**
     * @param array|null $value
     * @return string
     */
    public function formatRelativeCount(?array $value): string
    {
        return isset($value)
            ? ($this->formatCount($value[0]) . ' (' . $this->formatPercent($value[1]) . ')')
            : static::NO_DATA_STUB;
    }

    /**
     * @return array
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function getExportStatusAction(): array
    {
        try {
            $taskCode = $this->getExportTaskCode(CurrentUser::get()->getId());

            /** @var Statistics\ExportTask $currentTask */
            $currentTask = Task\Manager::getInstance()->getByCode($taskCode);

            $oldestVisibleTaskTime = (new DateTime())->add('-' . static::HOURS_TO_KEEP_EXPORT_FILES . ' hours');

            $lastTasks = Task\Manager::getInstance()->getLast($taskCode, $oldestVisibleTaskTime);

            if (
                !empty($currentTask) && (
                    !$currentTask->getState()->isFinal()
                    || $currentTask->getLastActivityDatetime()->getTimestamp() >= $oldestVisibleTaskTime->getTimestamp()
                )
            ) {
                $lastTasks = array_values(array_filter(
                    $lastTasks,
                    static fn(Task\Task $task): bool => ($task->getId() != $currentTask->getId())
                ));
            } else {
                $currentTask = null;
            }

            return [
                'lastTasks' => array_map([$this, 'prepareExportTask'], array_reverse($lastTasks)),
                'currentTask' => empty($currentTask) ? null : $this->prepareExportTask($currentTask),
            ];
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @param string $reportType
     * @param array  $filter
     * @return array
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function startExportAction(string $reportType, array $filter = []): array
    {
        try {
            if (!$this->isReportTypeValid($reportType)) {
                throw new Exception('Некорректный тип отчета');
            }

            $userId = CurrentUser::get()->getId();

            $task = Task\Manager::getInstance()->start(
                Statistics\ExportTask::class,
                [
                    'USER_ID' => $userId,
                    'REPORT_TYPE' => $reportType,
                    'FILTER' => $filter,
                ],
                $this->getExportTaskCode($userId)
            );

            Task\Manager::getInstance()->forceLaunch($task->getId());

            return ['taskId' => $task->getId(), 'wasRunningAlready' => false];
        } catch (Task\Exception\AlreadyRunningException $alreadyRunningException) {
            return ['taskId' => $alreadyRunningException->getTask()->getId(), 'wasRunningAlready' => true];
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @return array
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function abortExportAction(): array
    {
        try {
            $task = Task\Manager::getInstance()->getByCode($this->getExportTaskCode(CurrentUser::get()->getId()));
            $task = Task\Manager::getInstance()->abortProcessing($task->getId());

            return ['taskId' => $task->getId()];
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @param int $exportTaskId
     * @return FileResponse
     * @throws ArgumentException | ObjectPropertyException | SystemException
     */
    public function downloadExportFileAction(int $exportTaskId): FileResponse
    {
        try {
            $userId = CurrentUser::get()->getId();

            $task = Task\Manager::getInstance()->getById($exportTaskId);
            if (!($task instanceof Statistics\ExportTask)) {
                throw new Exception('Некорректный id задачи');
            }

            if ($task->getUserId() != $userId) {
                throw new Exception('Нет доступа');
            }

            $file = $task->getOutputFile();
            if (empty($file) || !$file->doesExist()) {
                throw new Exception('Файл не найден');
            }

            $filePath = (
                $file->isDocumentRootRelative()
                    ? (Application::getDocumentRoot() . $file->getDocumentRootRelativePath())
                    : $file->getAbsolutePath()
            );

            return new FileResponse($filePath, $file->getOriginalName());
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @param string $type
     * @param array  $filter
     * @param array  $settings
     * @return array
     * @throws Throwable
     */
    public function getWidgetAction(string $type, array $filter = [], array $settings = []): array
    {
        try {
            $method = 'get' . ucfirst($type) . 'Data';
            if (!method_exists($this, $method)) {
                throw new Exception('Неизвестный виджет ' . $type);
            }

            $this->arResult = [
                'WIDGET_TYPE' => $type,
                'WIDGET_DATA' => $this->{$method}($filter, $settings)
            ];

            ob_start();
            $this->includeComponentTemplate('widget');
            $layout = ob_get_clean();

            return ['layout' => $layout];
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @param string $reportType
     * @param array  $filter
     * @return array
     * @throws Throwable
     */
    public function getRedirectLinkAction(string $reportType, array $filter = []): array
    {
        try {
            if (!$this->isReportTypeValid($reportType)) {
                throw new Exception('Неизвестный тип отчета ' . $reportType);
            }

            $categoryId = Dynamic::feedbackForm()
                ->getCategoryByCode(match ($reportType) {
                    static::EVENT_REPORT_TYPE => FeedbackForm\Category::EVENT_PARTICIPANT->name,
                    static::CONSULTATIONS_REPORT_TYPE => FeedbackForm\Category::RESULTS_OF_A_TELEPHONE_SURVEY->name,
                    static::SERVICES_REPORT_TYPE => FeedbackForm\Category::ONLINE_SERVICES_EVALUATION->name,
                    static::EDUCATION_PRODUCT_TYPE => FeedbackForm\Category::EDUCATION_PRODUCT_EVALUATION->name,
                })
                ->getId();

            $filterOptions = new Filter\Options(
                (new GridId(Dynamic::feedbackForm()->getEntityTypeId()))->getValueForCategory($categoryId)
            );

            $rows = Filter\Options::fetchPresetFields(array_merge(
                ['fields' => []],
                ($filterOptions->getFilterSettings(Filter\Options::TMP_FILTER) ?? [])
            ));

            $fields = $this->convertFilterToFormListFilter($reportType, $filter);

            $filterOptions->setFilterSettings(
                Filter\Options::TMP_FILTER,
                [
                    'fields' => $fields,
                    'rows' => array_unique(array_merge($rows, Filter\Options::getRowsFromFields($fields)))
                ],
                true,
                false
            );

            $filterOptions->save();

            return [
                'link' => Container::getInstance()->getRouter()->getItemListUrl(
                    Dynamic::feedbackForm()->getEntityTypeId(),
                    $categoryId
                )
            ];
        } catch (Throwable $throwable) {
            (new Logger(static::LOG_CATEGORY))->setThrowable($throwable)->error('Ошибка контроллера');
            throw $throwable;
        }
    }

    /**
     * @param string   $outName
     * @param callable $statisticsProvider
     * @param ...$arguments
     * @return array
     */
    protected function calculateParallelStatistics(string $outName, callable $statisticsProvider, ...$arguments): array
    {
        /** @var Statistics\Filter $filter */
        $filter = ($arguments['filter'] ?? null);
        if (empty($filter)) {
            throw new Exception('Не указан фильтр в параметрах');
        }

        $statistics = [$outName => call_user_func_array($statisticsProvider, $arguments)];

        if (!empty($filter->getRangeBegin()) && !empty($filter->getRangeEnd())) {
            $previousRangeBegin = (clone $filter->getRangeBegin())->toUserTime()->add('-1 year');
            $previousRangeEnd = (clone $filter->getRangeEnd())->toUserTime()->add('-1 year');

            if ((clone $previousRangeBegin)->add('1 year')->getTimestamp() >= $previousRangeEnd->getTimestamp()) {
                $arguments['filter'] = (clone $filter)
                    ->setRangeBegin(DateTime::createFromUserTime((string)$previousRangeBegin->disableUserTime()))
                    ->setRangeEnd(DateTime::createFromUserTime((string)$previousRangeEnd->disableUserTime()));

                $statistics['PREVIOUS_' . $outName] = call_user_func_array($statisticsProvider, $arguments);
            }
        }

        return $statistics;
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array[]
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getLearnedFromSourceStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertFilterToStatisticsFilter($filter)->setCategoryCodes($settings['categories'] ?? []);

        return [
            'ITEM_AGGREGATION' => Statistics\Calculator::getInstance()->calculateLearnedFromSourceValues(
                $settings['fieldName'],
                $filter
            )
        ];
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array
     */
    public function getServicesStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertFilterToStatisticsFilter($filter)
            ->setCategoryCodes([FeedbackForm\Category::ONLINE_SERVICES_EVALUATION->name]);

        $calculator = Statistics\Calculator::getInstance();

        return array_merge(
            ['FILLED_FORM_RELATIVE_COUNT' => $calculator->calculateFormProgressValue($filter)],
            $this->calculateParallelStatistics('NPS',  [$calculator, 'calculateNpsValue'], filter: $filter),
            $this->calculateParallelStatistics(
                'CSI',
                [$calculator, 'calculateCsiValue'],
                filter: $filter,
                fieldNames: ['CONTENTMENT_PRACTICAL_BENEFITS', 'CONVENIENCE_OF_OBTAININ'],
            ),
            array_combine(
                ['SUPPORTER_RELATIVE_COUNT', 'NEUTRAL_RELATIVE_COUNT', 'CRITIC_RELATIVE_COUNT'],
                $calculator->calculateLoyaltyValues($filter)
            ),
        );
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getEventStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertFilterToStatisticsFilter($filter)
            ->setCategoryCodes([FeedbackForm\Category::EVENT_PARTICIPANT->name]);

        $calculator = Statistics\Calculator::getInstance();

        return array_merge(
            ['FILLED_FORM_RELATIVE_COUNT' => $calculator->calculateFormProgressValue($filter)],
            $this->calculateParallelStatistics('NPS', [$calculator, 'calculateNpsValue'], filter: $filter),
            $this->calculateParallelStatistics(
                'CSI',
                [$calculator, 'calculateCsiValue'],
                filter: $filter,
                fieldNames: ['PROFESSIONALISM', 'CONTENTMENT_PRACTICAL_BENEFITS', 'CONVENIENCE_OF_OBTAININ'],
            ),
            array_combine(
                ['SUPPORTER_RELATIVE_COUNT', 'NEUTRAL_RELATIVE_COUNT', 'CRITIC_RELATIVE_COUNT'],
                $calculator->calculateLoyaltyValues($filter)
            ),
            array_combine(['EVENT_COUNT', 'EVENT_PARTICIPANT_COUNT'], $calculator->calculateEventValues($filter)),
        );
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array
     * @throws ArgumentException | ObjectException
     * @throws ObjectPropertyException | SystemException
     */
    public function getEducationProductStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertFilterToStatisticsFilter($filter)
            ->setCategoryCodes([FeedbackForm\Category::EDUCATION_PRODUCT_EVALUATION->name]);
        $calculator = Statistics\Calculator::getInstance();

        return array_merge(
            ['FILLED_FORM_RELATIVE_COUNT' => $calculator->calculateFormProgressValue($filter)],
            $this->calculateParallelStatistics('NPS',  [$calculator, 'calculateNpsValue'], filter: $filter),
            array_combine(
                ['SUPPORTER_RELATIVE_COUNT', 'NEUTRAL_RELATIVE_COUNT', 'CRITIC_RELATIVE_COUNT'],
                $calculator->calculateLoyaltyValues($filter)
            ),
            $this->calculateParallelStatistics(
                'CSI',
                [$calculator, 'calculateCsiValue'],
                filter: $filter,
                fieldNames: ['CONTENTMENT_PRACTICAL_BENEFITS', 'CONVENIENCE_OF_OBTAININ'],
            ),
        );
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function getConsultationStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertFilterToStatisticsFilter($filter)
            ->setCategoryCodes([FeedbackForm\Category::RESULTS_OF_A_TELEPHONE_SURVEY->name]);

        $calculator = Statistics\Calculator::getInstance();

        return array_merge(
            $this->calculateParallelStatistics('NPS', [$calculator, 'calculateNpsValue'], filter: $filter),
            $this->calculateParallelStatistics(
                'CSI',
                [$calculator, 'calculateCsiValue'],
                filter: $filter,
                fieldNames: ['PROFESSIONALISM', 'CONTENTMENT_PRACTICAL_BENEFITS', 'CONVENIENCE_OF_OBTAININ']
            ),
            array_combine(
                ['SUPPORTER_RELATIVE_COUNT', 'NEUTRAL_RELATIVE_COUNT', 'CRITIC_RELATIVE_COUNT'],
                $calculator->calculateLoyaltyValues($filter)
            ),
            array_combine(
                ['CONSULTATION_COUNT', 'FILLED_FORM_RELATIVE_COUNT'],
                $calculator->calculateConsultationValues($filter)
            ),
        );
    }

    /**
     * @param array $filter
     * @param array $settings
     * @return array
     */
    public function getCommonStatisticsData(array $filter = [], array $settings = []): array
    {
        $filter = $this->convertCommonFilterToStatisticsFilter($filter);

        $calculator = Statistics\Calculator::getInstance();

        return array_merge(
            $this->calculateParallelStatistics('NPS', [$calculator, 'calculateNpsValue'], filter: $filter),
            $this->calculateParallelStatistics(
                'CSI',
                [$calculator, 'calculateCsiValue'],
                filter: (clone $filter)->setCategoryCodes([
                    FeedbackForm\Category::EVENT_PARTICIPANT->name,
                    FeedbackForm\Category::RESULTS_OF_A_TELEPHONE_SURVEY->name
                ]),
                fieldNames: ['PROFESSIONALISM', 'CONTENTMENT_PRACTICAL_BENEFITS', 'CONVENIENCE_OF_OBTAININ'],
            )
        );
    }

    /**
     * @param string $reportType
     * @return array
     * @throws ArgumentException | ObjectPropertyException
     * @throws SystemException
     */
    protected function getFilterSettings(string $reportType): array
    {
        return [
            'ID' => static::FILTER_ID_PREFIX . $reportType,
            'PRESETS' => [
                'month_range' => [
                    'name' => 'Текущий месяц',
                    'default' => true,
                    'fields' => [('RANGE' . Filter\DateType::getPostfix()) => Filter\DateType::CURRENT_MONTH]
                ]
            ],
            'FIELDS' => array_merge(
                [
                    'RANGE' => [
                        'id' => 'RANGE',
                        'name' => 'Дата',
                        'type' => 'date',
                        'default' => true,
                    ]
                ],
                match ($reportType) {
                    static::CONSULTATIONS_REPORT_TYPE => [
                        'CONSULTATION_SUPPORT_CODE' => [
                            'id' => 'CONSULTATION_SUPPORT_CODE',
                            'name' => 'Вид поддержки',
                            'default' => true,
                            'type' => 'entity_selector',
                            'params' => [
                                'multiple' => true,
                                'dialogOptions' => [
                                    'height' => 300,
                                    'context' => 'PORTAL_FEEDBACK_STATISTICS',
                                    'recentTabOptions' => ['visible' => true],
                                    'entities' => [
                                        [
                                            'id' => 'crm-feedback-form-support-code',
                                            'options' => [
                                                'iblockId' => Iblock::getDataClass('listsSupport')::getEntity()
                                                    ->getIblock()
                                                    ->getId()
                                            ],
                                            'dynamicLoad' => true,
                                            'dynamicSearch' => true,
                                        ],
                                    ],
                                    'enableSearch' => true,
                                ],
                            ]
                        ],
                        'CONSULTATION_DEPARTMENT' => [
                            'id' => 'CONSULTATION_DEPARTMENT',
                            'name' => 'Подразделение, оказавшее консультацию',
                            'default' => true,
                            'type' => 'entity_selector',
                            'params' => [
                                'multiple' => true,
                                'dialogOptions' => [
                                    'height' => 300,
                                    'context' => 'PORTAL_FEEDBACK_STATISTICS',
                                    'recentTabOptions' => ['visible' => true],
                                    'entities' => [
                                        [
                                            'id' => 'iblock-property-section',
                                            'options' => [
                                                'iblockId' => DepartmentTable::getIblockId()
                                            ],
                                            'dynamicLoad' => true,
                                            'dynamicSearch' => true,
                                        ],
                                    ],
                                    'enableSearch' => true,
                                ],
                            ]
                        ]
                    ],
                    static::EVENT_REPORT_TYPE => [
                        'EVENT_RESPONSIBLE_DEPARTMENT' => [
                            'id' => 'EVENT_RESPONSIBLE_DEPARTMENT',
                            'name' => 'Подразделение, ответственное за проведение мероприятия',
                            'default' => true,
                            'type' => 'entity_selector',
                            'params' => [
                                'multiple' => true,
                                'dialogOptions' => [
                                    'height' => 300,
                                    'context' => 'PORTAL_FEEDBACK_STATISTICS',
                                    'recentTabOptions' => ['visible' => true],
                                    'entities' => [
                                        [
                                            'id' => 'iblock-property-section',
                                            'options' => [
                                                'iblockId' => DepartmentTable::getIblockId()
                                            ],
                                            'dynamicLoad' => true,
                                            'dynamicSearch' => true,
                                        ],
                                    ],
                                    'enableSearch' => true,
                                ],
                            ]
                        ]
                    ],
                    static::SERVICES_REPORT_TYPE => [
                        'ONLINE_SERVICE' => [
                            'id' => 'ONLINE_SERVICE',
                            'name' => 'Сервис',
                            'default' => true,
                            'type' => 'entity_selector',
                            'params' => [
                                'multiple' => true,
                                'dialogOptions' => [
                                    'height' => 300,
                                    'context' => 'PORTAL_FEEDBACK_STATISTICS',
                                    'recentTabOptions' => ['visible' => true],
                                    'entities' => [
                                        [
                                            'id' => 'iblock-property-element',
                                            'options' => [
                                                'iblockId' => Iblock::getDataClass('services')::getEntity()
                                                    ->getIblock()
                                                    ->getId()
                                            ],
                                            'dynamicLoad' => true,
                                            'dynamicSearch' => true,
                                        ],
                                    ],
                                    'enableSearch' => true,
                                ],
                            ]
                        ]
                    ],
                    static::EDUCATION_PRODUCT_TYPE => [
                        'EDUCATION_PRODUCT' => [
                            'id' => 'EDUCATION_PRODUCT',
                            'name' => 'Образовательный продукт',
                            'default' => true,
                            'type' => 'dest_selector',
                            'params' => [
                                'context' => 'PORTAL_FEEDBACK_STATISTICS',
                                'contextCode' => 'CRM',
                                'enableAll' => 'N',
                                'enableDepartments' => 'N',
                                'enableUsers' => 'N',
                                'enableSonetgroups' => 'N',
                                'enableCrm' => 'Y',
                                'multiple' => 'N',
                                'convertJson' => 'Y',
                                'enableCrmDynamics' => [
                                    Dynamic::educationProduct()->getEntityTypeId() => 'Y',
                                ],
                                'addTabCrmDynamics' => [
                                    Dynamic::educationProduct()->getEntityTypeId() => 'N',
                                ],
                                'crmDynamicTitles' => [
                                    sprintf(
                                        '%s_%s',
                                        Handler::ENTITY_TYPE_CRMDYNAMICS,
                                        Dynamic::educationProduct()->getEntityTypeId()
                                    ) => 'Образовательные продукты',
                                ],
                            ],
                        ]
                    ],
                }
            )
        ];
    }

    /**
     * @param array $filter
     * @return Statistics\Filter
     * @throws ObjectException
     */
    protected function convertCommonFilterToStatisticsFilter(array $filter): Statistics\Filter
    {
        $statisticsFilter = Statistics\Filter::createInstance();

        if (!empty($filter['RANGE' . Filter\DateType::getPostfix()])) {
            $convertedField = [];
            Filter\Options::calcDates('RANGE', $filter, $convertedField);

            if (!empty($convertedField['RANGE_from'])) {
                $statisticsFilter->setRangeBegin(DateTime::createFromUserTime($convertedField['RANGE_from']));
            }

            if (!empty($convertedField['RANGE_to'])) {
                $statisticsFilter->setRangeEnd(DateTime::createFromUserTime($convertedField['RANGE_to']));
            }
        }

        return $statisticsFilter;
    }

    /**
     * @param array $filter
     * @return Statistics\Filter
     * @throws ObjectException
     * @throws ArgumentException
     */
    public function convertFilterToStatisticsFilter(array $filter): Statistics\Filter
    {
        return $this->convertCommonFilterToStatisticsFilter($filter)
            ->setConsultationSupportCodes($filter['CONSULTATION_SUPPORT_CODE'] ?? [])
            ->setConsultationDepartmentIds($filter['CONSULTATION_DEPARTMENT'] ?? [])
            ->setEventResponsibleDepartmentIds($filter['EVENT_RESPONSIBLE_DEPARTMENT'] ?? [])
            ->setOnlineServiceIds($filter['ONLINE_SERVICE'] ?? [])
            ->setEducationProductIds(htmlspecialchars_decode($filter['EDUCATION_PRODUCT']) ?? '');
    }

    /**
     * @param string $reportType
     * @param array  $filter
     * @return array
     * @throws ObjectException
     */
    protected function convertFilterToFormListFilter(string $reportType, array $filter): array
    {
        $listFilter = [];

        if (!empty($filter['RANGE' . Filter\DateType::getPostfix()])) {
            if ($reportType == static::EVENT_REPORT_TYPE) {
                $convertedField = [];
                Filter\Options::calcDates('RANGE', $filter, $convertedField);

                if (!empty($convertedField['RANGE_from'])) {
                    $listFilter['EVENT.UF_TIME_TO_datesel'] = Filter\DateType::RANGE;
                    $listFilter['EVENT.UF_TIME_TO_month'] = '';
                    $listFilter['EVENT.UF_TIME_TO_quarter'] = '';
                    $listFilter['EVENT.UF_TIME_TO_year'] = '';
                    $listFilter['EVENT.UF_TIME_TO_from'] = $convertedField['RANGE_from'];
                    $listFilter['EVENT.UF_TIME_TO_to'] = '';
                }

                if (!empty($convertedField['RANGE_to'])) {
                    $listFilter['EVENT.UF_TIME_FROM_datesel'] = Filter\DateType::RANGE;
                    $listFilter['EVENT.UF_TIME_FROM_month'] = '';
                    $listFilter['EVENT.UF_TIME_FROM_quarter'] = '';
                    $listFilter['EVENT.UF_TIME_FROM_year'] = '';
                    $listFilter['EVENT.UF_TIME_FROM_from'] = '';
                    $listFilter['EVENT.UF_TIME_FROM_to'] = $convertedField['RANGE_to'];
                }
            } else {
                $targetFieldName = (
                    $reportType == static::CONSULTATIONS_REPORT_TYPE ? 'CONSULTATION.UF_SUPPORT_DATE' : 'CREATED_TIME'
                );

                foreach (['_datesel', '_month', '_quarter', '_year', '_from', '_to'] as $postfix) {
                    if (array_key_exists('RANGE' . $postfix, $filter)) {
                        $listFilter[$targetFieldName . $postfix] = $filter['RANGE' . $postfix];
                    }
                }
            }
        }

        foreach (['', '_label'] as $postfix) {
            if (!empty($filter['CONSULTATION_SUPPORT_CODE' . $postfix])) {
                $listFilter['CONSULTATION.UF_SUPPORT_CODE' . $postfix] = $filter[
                    'CONSULTATION_SUPPORT_CODE' . $postfix
                ];
            }

            if (!empty($filter['CONSULTATION_DEPARTMENT' . $postfix])) {
                $listFilter['CONSULTATION.UF_DEPARTMENT_ID' . $postfix] = $filter['CONSULTATION_DEPARTMENT' . $postfix];
            }

            if (!empty($filter['EVENT_RESPONSIBLE_DEPARTMENT' . $postfix])) {
                $listFilter['EVENT.UF_RESPONSIBLE_DEPARTMENT' . $postfix] = $filter[
                    'EVENT_RESPONSIBLE_DEPARTMENT' . $postfix
                ];
            }

            if (!empty($filter['ONLINE_SERVICE' . $postfix])) {
                $listFilter['UF_ONLINE_SERVICE' . $postfix] = $filter['ONLINE_SERVICE' . $postfix];
            }

            if (!empty($filter['EDUCATION_PRODUCT' . $postfix])) {
                $listFilter['UF_EDUCATION_PRODUCT' . $postfix] = $filter['EDUCATION_PRODUCT' . $postfix];
            }
        }

        return $listFilter;
    }

    /**
     * @param int $userId
     * @return string
     */
    protected function getExportTaskCode(int $userId): string
    {
        if (empty($userId)) {
            throw new Exception('Задачу на экспорт можно запустить только для авторизованного пользователя');
        }

        return 'feedback_statistics_export_for_user' . $userId;
    }

    /**
     * @param Statistics\ExportTask $task
     * @return array
     * @throws ArgumentException | SystemException
     */
    protected function prepareExportTask(Statistics\ExportTask $task): array
    {
        $file = $task->getOutputFile();

        return [
            'id' => $task->getId(),
            'reportType' => $task->getReportType(),
            'state' => $task->getState()->value,
            'initializingDatetime' => $task->getInitializingDatetime()->toString(),
            'lastActivityDatetime' => $task->getLastActivityDatetime()->toString(),
            'progress' => $task->getProgress(),
            'file' => (
                (!empty($file) && $file->doesExist())
                    ? [
                        'originalName' => $file->getOriginalName(),
                        'url' => UrlManager::getInstance()
                            ->createByBitrixComponent($this, 'downloadExportFile')
                            ->addParams(['exportTaskId' => $task->getId()])
                            ->getLocator(),
                    ]
                    : null
            ),
        ];
    }

    /**
     * @param string $reportType
     * @return bool
     */
    protected function isReportTypeValid(string $reportType): bool
    {
        return in_array(
            $reportType,
            [
                static::EVENT_REPORT_TYPE,
                static::CONSULTATIONS_REPORT_TYPE,
                static::SERVICES_REPORT_TYPE,
                static::EDUCATION_PRODUCT_TYPE
            ]
        );
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function resolveReportType(): string
    {
        if (isset($this->arParams['REPORT_TYPE'])) {
            $reportType = $this->arParams['REPORT_TYPE'];
        } else {
            $routeBase = $this->arParams['SEF_FOLDER'] ?? null;
            if (empty($routeBase)) {
                throw new Exception('Не задан параметр SEF_FOLDER');
            }

            $variables = [];
            $pageCode = CComponentEngine::parseComponentPath(
                $routeBase,
                CComponentEngine::makeComponentUrlTemplates(
                    ['report' => '#REPORT_TYPE#/'],
                    ($this->arParams['SEF_URL_TEMPLATES'] ?? [])
                ),
                $variables
            );

            if (empty($pageCode) || empty($variables['REPORT_TYPE'])) {
                throw new Exception('Ошибка определения типа отчета по URL');
            }

            $reportType = $variables['REPORT_TYPE'];
        }

        if (!$this->isReportTypeValid($reportType)) {
            throw new Exception('Некорректный тип отчета');
        }

        return $reportType;
    }
}
