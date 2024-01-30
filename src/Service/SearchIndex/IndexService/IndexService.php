<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under following license:
 * - Pimcore Commercial License (PCL)
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     PCL
 */

namespace Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\IndexService;

use Exception;
use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\FieldCategory;
use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\FieldCategory\SystemField;
use Pimcore\Bundle\GenericDataIndexBundle\Exception\IndexDataException;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\IndexService\ElementTypeAdapter\ElementTypeAdapterService;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\IndexServiceInterface;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\OpenSearch\BulkOperationService;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\OpenSearch\OpenSearchService;
use Pimcore\Bundle\GenericDataIndexBundle\Traits\LoggerAwareTrait;
use Pimcore\Model\Element\ElementInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
final class IndexService implements IndexServiceInterface
{
    use LoggerAwareTrait;

    private bool $performIndexRefresh = false;

    public function __construct(
        private readonly ElementTypeAdapterService $typeAdapterService,
        private readonly OpenSearchService $openSearchService,
        private readonly BulkOperationService $bulkOperationService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function isPerformIndexRefresh(): bool
    {
        return $this->performIndexRefresh;
    }

    public function setPerformIndexRefresh(bool $performIndexRefresh): IndexService
    {
        $this->performIndexRefresh = $performIndexRefresh;

        return $this;
    }

    /**
     * @throws IndexDataException
     */
    public function updateIndexData(ElementInterface $element): IndexService
    {
        $indexName = $this->typeAdapterService
            ->getTypeAdapter($element)
            ->getAliasIndexNameByElement($element);

        try {
            $indexDocument = $this->openSearchService->getDocument($indexName, $element->getId());
            $originalChecksum = $indexDocument['_source'][FieldCategory::SYSTEM_FIELDS->value][SystemField::CHECKSUM->value] ?? -1;
        } catch (Exception $e) {
            $this->logger->debug($e->getMessage());
            $originalChecksum = -1;
        }

        $indexData = $this->getIndexData($element);

        if ($indexData[FieldCategory::SYSTEM_FIELDS->value][SystemField::CHECKSUM->value] !== $originalChecksum) {

            $this->bulkOperationService->add(['update' => ['_index' => $indexName, '_id' => $element->getId()]]);
            $this->bulkOperationService->add(['doc' => $indexData, 'doc_as_upsert' => true]);

            $this->logger->info('Add update of element ID ' . $element->getId() . ' from ' . $indexName . ' index to bulk.');
        } else {
            $this->logger->info('Not updating index ' . $indexName . ' for element ID ' . $element->getId() . ' - nothing has changed.');
        }

        return $this;
    }

    public function deleteFromIndex(ElementInterface $element): IndexService
    {
        $indexName = $this->typeAdapterService
            ->getTypeAdapter($element)
            ->getAliasIndexNameByElement($element);

        $elementId = $element->getId();

        $this->bulkOperationService->add([
            'delete' => [
                '_index' => $indexName,
                '_id' => $elementId,
            ],
        ]);

        $this->logger->info('Add deletion of item ID ' . $elementId . ' from ' . $indexName . ' index to bulk.');

        return $this;
    }

    /**
     * @throws IndexDataException
     */
    private function getIndexData(ElementInterface $element): array
    {
        try {
            $typeAdapter = $this->typeAdapterService->getTypeAdapter($element);
            $indexData = $typeAdapter
                ->getNormalizer()
                ->normalize($element);

            $systemFields = $indexData[FieldCategory::SYSTEM_FIELDS->value];
            $standardFields = $indexData[FieldCategory::STANDARD_FIELDS->value];
            $customFields = [];

            //dispatch event before building checksum
            $updateIndexDataEvent = $typeAdapter->getUpdateIndexDataEvent($element, $customFields);
            $this->eventDispatcher->dispatch($updateIndexDataEvent);
            $customFields = $updateIndexDataEvent->getCustomFields();

            $checksum = crc32(json_encode([$systemFields, $standardFields, $customFields], JSON_THROW_ON_ERROR));
            $systemFields[SystemField::CHECKSUM->value] = $checksum;

            return [
                FieldCategory::SYSTEM_FIELDS->value => $systemFields,
                FieldCategory::STANDARD_FIELDS->value => $standardFields,
                FieldCategory::CUSTOM_FIELDS->value => $customFields,
            ];
        } catch (Exception|ExceptionInterface $e) {
            throw new IndexDataException($e->getMessage());
        }

    }
}
