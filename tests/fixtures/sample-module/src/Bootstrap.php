<?php

declare(strict_types=1);

namespace Example\Sample;

use OpenEMR\Menu\MenuEvent;

final class Bootstrap
{
    public function subscribeToEvents($eventDispatcher): void
    {
        $eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->label = xlt('Sample');
        $item->acl_req = ['patients', 'med'];
        return $event;
    }

    /** Starts a program, which the sheet lists; a method of its own named exec() is not one. */
    public function convert(string $file): bool
    {
        $process = proc_open(['/usr/bin/true', $file], [], $pipes);
        return is_resource($process) && $this->exec($file);
    }

    private function exec(string $file): bool
    {
        return $file !== '';
    }
}
