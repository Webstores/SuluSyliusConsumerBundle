<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\SyliusConsumerBundle\Tests\Unit\Model\Product\Query;

use PHPUnit\Framework\TestCase;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\Query\ListProductsQuery;

class ListProductsQueryTest extends TestCase
{
    public function testGetLocale(): void
    {
        $query = new ListProductsQuery('en', 'app.products', ['page' => 1]);

        $this->assertEquals('en', $query->getLocale());
    }

    public function testGetRoute(): void
    {
        $query = new ListProductsQuery('en', 'app.products', ['page' => 1]);

        $this->assertEquals('app.products', $query->getRoute());
    }

    public function testGetQuery(): void
    {
        $query = new ListProductsQuery('en', 'app.products', ['page' => 1]);

        $this->assertEquals(['page' => 1], $query->getQuery());
    }
}
