<?php

namespace App\Domain\Deadlines\Enums;

enum DeadlineKind: string
{
    /** A reglementary period, e.g. filing an appeal within 15 days. */
    case Filing = 'filing';

    /** A scheduled court appearance. */
    case Hearing = 'hearing';

    /** An internal task with a target date. */
    case Task = 'task';
}
