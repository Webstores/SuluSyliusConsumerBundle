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

namespace Sulu\Bundle\SyliusConsumerBundle\Model\Product\Handler\Message;

use Sulu\Bundle\RouteBundle\Generator\RouteGenerator;
use Sulu\Bundle\SyliusConsumerBundle\Model\Content\Message\ModifyContentMessage;
use Sulu\Bundle\SyliusConsumerBundle\Model\Content\Message\PublishContentMessage;
use Sulu\Bundle\SyliusConsumerBundle\Model\Dimension\DimensionInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Dimension\DimensionRepositoryInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\Exception\ProductInformationNotFoundException;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\Exception\ProductNotFoundException;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\Message\PublishProductMessage;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\Message\PublishProductVariantMessage;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\ProductInformationAttributeValueRepositoryInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\ProductInformationInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\ProductInformationRepositoryInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\ProductInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\Product\ProductRepositoryInterface;
use Sulu\Bundle\SyliusConsumerBundle\Model\RoutableResource\Message\PublishRoutableResourceMessage;
use Sulu\Bundle\SyliusConsumerBundle\Model\RoutableResource\RoutableResource;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\Content\Metadata\PropertyMetadata;
use Symfony\Component\Messenger\MessageBusInterface;

class PublishProductMessageHandler
{
    const PRODUCT_PATH_FIELD_TAG = 'sulu_sylius_consumer.product_path';

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductInformationRepositoryInterface
     */
    private $productInformationRepository;

    /**
     * @var ProductInformationAttributeValueRepositoryInterface
     */
    private $productInformationAttributeValueRepository;

    /**
     * @var DimensionRepositoryInterface
     */
    private $dimensionRepository;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    /**
     * @var StructureMetadataFactoryInterface
     */
    private $factory;

    /**
     * @var RouteGenerator
     */
    private $routeGenerator;

    /**
     * @var array
     */
    private $routeMappings;

    /**
     * @var string
     */
    private $defaultType;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductInformationRepositoryInterface $productInformationRepository,
        ProductInformationAttributeValueRepositoryInterface $productInformationAttributeValueRepository,
        DimensionRepositoryInterface $dimensionRepository,
        MessageBusInterface $messageBus,
        StructureMetadataFactoryInterface $factory,
        RouteGenerator $routeGenerator,
        array $routeMappings,
        string $defaultType
    ) {
        $this->productRepository = $productRepository;
        $this->productInformationRepository = $productInformationRepository;
        $this->productInformationAttributeValueRepository = $productInformationAttributeValueRepository;
        $this->dimensionRepository = $dimensionRepository;
        $this->messageBus = $messageBus;
        $this->factory = $factory;
        $this->routeGenerator = $routeGenerator;
        $this->routeMappings = $routeMappings;
        $this->defaultType = $defaultType;
    }

    public function __invoke(PublishProductMessage $message): void
    {
        $product = $this->productRepository->findById($message->getId());
        if (!$product) {
            throw new ProductNotFoundException($message->getId());
        }

        try {
            $this->publishProductInformation($product, $message->getLocale());
        } catch (ProductInformationNotFoundException $exception) {
            throw new ProductNotFoundException($message->getId(), 0, $exception);
        }

        $publishContentMessage = new PublishContentMessage(
            ProductInterface::CONTENT_RESOURCE_KEY,
            $message->getId(),
            $message->getLocale(),
            false
        );
        $this->messageBus->dispatch($publishContentMessage);

        $routePath = $this->getRoutePathFromContent($publishContentMessage);
        if (empty($routePath)) {
            $mappings = $this->routeMappings[RoutableResource::class];
            $routePath = $this->routeGenerator->generate($product, $mappings['options']);
        }

        $publishRoutableResourceMessage = new PublishRoutableResourceMessage(
            ProductInterface::RESOURCE_KEY,
            $message->getId(),
            $message->getLocale(),
            $routePath
        );
        $this->messageBus->dispatch($publishRoutableResourceMessage);

        $routePathFieldName = $this->getRoutePathFieldNameFromContent($publishContentMessage);
        $payloadData = array_merge(
            $publishContentMessage->hasContentView() ? $publishContentMessage->getContentView()->getData() : [],
            [$routePathFieldName => $publishRoutableResourceMessage->getRoute()->getRoute()->getPath()]
        );

        $type = $this->defaultType;
        if ($publishContentMessage->hasContentView()) {
            $type = $publishContentMessage->getContentView()->getType();
        }
        $modifyContentMessage = new ModifyContentMessage(
            ProductInterface::CONTENT_RESOURCE_KEY,
            $message->getId(),
            $message->getLocale(),
            ['type' => $type, 'data' => $payloadData]
        );
        $this->messageBus->dispatch($modifyContentMessage);

        $contentMessage = new PublishContentMessage(
            ProductInterface::CONTENT_RESOURCE_KEY,
            $message->getId(),
            $message->getLocale(),
            false
        );
        $this->messageBus->dispatch($contentMessage);

        foreach ($product->getVariants() as $variant) {
            $this->messageBus->dispatch(
                new PublishProductVariantMessage(
                    $variant->getId(),
                    $message->getLocale(),
                    false
                )
            );
        }

        $message->setProduct($product);
    }

    private function publishProductInformation(ProductInterface $product, string $locale): void
    {
        $draftDimension = $this->dimensionRepository->findOrCreateByAttributes(
            [
                DimensionInterface::ATTRIBUTE_KEY_STAGE => DimensionInterface::ATTRIBUTE_VALUE_DRAFT,
                DimensionInterface::ATTRIBUTE_KEY_LOCALE => $locale,
            ]
        );
        $liveDimension = $this->dimensionRepository->findOrCreateByAttributes(
            [
                DimensionInterface::ATTRIBUTE_KEY_STAGE => DimensionInterface::ATTRIBUTE_VALUE_LIVE,
                DimensionInterface::ATTRIBUTE_KEY_LOCALE => $locale,
            ]
        );

        $draftProductInformation = $product->findProductInformationByDimension($draftDimension);
        if (!$draftProductInformation) {
            throw new ProductInformationNotFoundException($product->getId());
        }

        $liveProductInformation = $product->findProductInformationByDimension($liveDimension);
        if (!$liveProductInformation) {
            $liveProductInformation = $this->productInformationRepository->create($product, $liveDimension);
        }

        $liveProductInformation->mapPublishProperties($draftProductInformation);
        $this->publishProductInformationAttributeValues($draftProductInformation, $liveProductInformation);
    }

    private function publishProductInformationAttributeValues(
        ProductInformationInterface $draftProductInformation,
        ProductInformationInterface $liveProductInformation
    ) {
        // process existing and new
        $processedAttributeValueCodes = [];
        foreach ($draftProductInformation->getAttributeValues() as $draftAttributeValue) {
            $attributeValue = $liveProductInformation->findAttributeValueByCode($draftAttributeValue->getCode());
            if (!$attributeValue) {
                // create new one
                $attributeValue = $this->productInformationAttributeValueRepository->create(
                    $liveProductInformation,
                    $draftAttributeValue->getCode(),
                    $draftAttributeValue->getType()
                );
            }
            $attributeValue->setValue($draftAttributeValue->getValue());

            $processedAttributeValueCodes[] = $draftAttributeValue->getCode();
        }

        // check for removed
        foreach (array_diff($liveProductInformation->getAttributeValueCodes(), $processedAttributeValueCodes) as $attributeValueCode) {
            $liveProductInformation->removeAttributeValueByCode($attributeValueCode);
        }
    }

    private function getRoutePathFromContent(PublishContentMessage $message): string
    {
        $routePathProperty = $this->getRoutePathPropertyFromContent($message);
        if ($routePathProperty) {
            return (string) $message->getContentView()->getData()[$routePathProperty->getName()];
        }

        return '';
    }

    private function getRoutePathFieldNameFromContent(PublishContentMessage $message)
    {
        $routePathProperty = $this->getRoutePathPropertyFromContent($message);
        if ($routePathProperty) {
            return $routePathProperty->getName();
        }

        return '';
    }

    private function getRoutePathPropertyFromContent(PublishContentMessage $message): ?PropertyMetadata
    {
        if (!$message->hasContentView()) {
            return null;
        }

        $metadata = $this->factory->getStructureMetadata($message->getResourceKey(), $message->getContentView()->getType());
        if (!$metadata || !$metadata->hasPropertyWithTagName(self::PRODUCT_PATH_FIELD_TAG)) {
            return null;
        }

        return $metadata->getPropertyByTagName(self::PRODUCT_PATH_FIELD_TAG);
    }
}
