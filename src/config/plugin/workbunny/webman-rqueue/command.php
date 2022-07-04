<?php
declare(strict_types=1);

use Workbunny\WebmanRqueue\Commands\WorkbunnyWebmanRqueueBuilder;
use Workbunny\WebmanRqueue\Commands\WorkbunnyWebmanRqueueRemove;
use Workbunny\WebmanRqueue\Commands\WorkbunnyWebmanRqueueList;

return [
    WorkbunnyWebmanRqueueBuilder::class,
    WorkbunnyWebmanRqueueRemove::class,
    WorkbunnyWebmanRqueueList::class
];
