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

namespace Sulu\Bundle\SyliusConsumerBundle\Model\Product;

use Sulu\Bundle\SyliusConsumerBundle\Model\Dimension\DimensionInterface;

interface ProductVariantInformationRepositoryInterface
{
    public function create(ProductVariantInterface $productVariant, DimensionInterface $dimension): ProductVariantInformationInterface;

    public function findByVariantId(string $variantId, DimensionInterface $dimension): ?ProductVariantInformationInterface;

    public function findAllByVariantId(string $variantId): array;

    public function remove(ProductVariantInformationInterface $variantInformation): void;
}
