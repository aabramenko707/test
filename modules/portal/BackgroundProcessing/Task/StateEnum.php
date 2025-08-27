<?php

namespace Portal\BackgroundProcessing\Task;

enum StateEnum: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case TERMINATED = 'terminated';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    /**
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this, self::getFinalCases());
    }

    /**
     * @return StateEnum[]
     */
    public static function getFinalCases(): array
    {
        return [self::TERMINATED, self::FAILED, self::SUCCEEDED];
    }
}
