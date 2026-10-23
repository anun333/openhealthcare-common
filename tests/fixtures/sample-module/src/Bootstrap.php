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
}
