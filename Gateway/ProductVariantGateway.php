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

namespace Sulu\Bundle\SyliusConsumerBundle\Gateway;

class ProductVariantGateway extends AbstractGateway implements ProductVariantGatewayInterface
{
    const URI = '/api/v1/products/{PRODUCT_ID}/variants/';

    public function findByCodeAndVariantCode(string $code, string $variantCode): array
    {
        $response = $this->gatewayClient->request(
            'GET',
            str_replace('{PRODUCT_ID}', $code, self::URI) . $variantCode
        );

        if (200 !== $response->getStatusCode()) {
            $this->handleErrors($response);
        }

        return json_decode($response->getBody()->getContents(), true);
    }
}
