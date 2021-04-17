<?php

declare(strict_types=1);

namespace Danslo\Apq\Model\Cache\Type;

use Magento\Framework\Cache\Frontend\Decorator\TagScope;
use Magento\Framework\App\Cache\Type\FrontendPool;

class Apq extends TagScope
{
    const TYPE_IDENTIFIER = 'apq';

    const CACHE_TAG = 'APQ';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
