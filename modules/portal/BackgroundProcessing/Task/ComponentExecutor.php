<?php

namespace Portal\BackgroundProcessing\Task;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use CMain;
use Exception;

class ComponentExecutor extends Task
{
    /**
     * @param array $input
     * @return $this
     * @throws ArgumentException
     * @throws SystemException
     */
    public function setInput(array $input): static
    {
        if (empty($input['COMPONENT_NAME'])) {
            throw new Exception('Компонент не указан');
        }

        return parent::setInput($input);
    }

    /**
     * @return $this
     * @throws ArgumentException | SystemException
     */
    public function execute(): static
    {
        /** @global CMain $APPLICATION */
        global $APPLICATION;

        $componentParameters = array_merge(($this->getInput()['COMPONENT_PARAMETERS'] ?? []), ['TASK' => $this]);

        ob_start();
        $componentResult = $APPLICATION->IncludeComponent(
            $this->getInput()['COMPONENT_NAME'],
            ($this->getInput()['COMPONENT_TEMPLATE'] ?? ''),
            $componentParameters,
        );
        $componentOutput = ob_get_clean();

        $this->setOutput(['COMPONENT_OUTPUT' => $componentOutput, 'COMPONENT_RESULT' => $componentResult])->save();

        return $this;
    }
}
