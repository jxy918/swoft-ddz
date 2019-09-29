<?php declare(strict_types=1);


namespace App\Listener;

use Swoft\Event\Annotation\Mapping\Listener;
use Swoft\Event\EventHandlerInterface;
use Swoft\Event\EventInterface;
use Swoft\Log\Helper\CLog;
use Swoft\Server\ServerEvent;

/**
 * Class BeforeStartListener
 *
 * @since 2.0
 *
 * @Listener(ServerEvent::BEFORE_START)
 */
class BeforeStartListener implements EventHandlerInterface
{
    /**
     * @param EventInterface $event
     */
    public function handle(EventInterface $event): void
    {
        CLog::info('Before Start: 清理缓存数据');
    }
}