<?php

namespace App\Domain\Deadlines\Enums;

/** Board columns for an open task. "Done" is the task's completed status. */
enum TaskProgress: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'To do',
            self::InProgress => 'In progress',
            self::Review => 'For review',
        };
    }
}
