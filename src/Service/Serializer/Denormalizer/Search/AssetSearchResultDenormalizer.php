<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\GenericDataIndexBundle\Service\Serializer\Denormalizer\Search;

use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\FieldCategory;
use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\FieldCategory\SystemField;
use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\SerializerContext;
use Pimcore\Bundle\GenericDataIndexBundle\Model\Search\Asset\SearchResult\AssetMetaData;
use Pimcore\Bundle\GenericDataIndexBundle\Model\Search\Asset\SearchResult\AssetSearchResultItem;
use Pimcore\Bundle\GenericDataIndexBundle\Model\SearchIndexAdapter\MappingProperty;
use Pimcore\Bundle\GenericDataIndexBundle\Service\Serializer\AssetTypeSerializationHandlerService;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

readonly class AssetSearchResultDenormalizer implements DenormalizerInterface
{
    public function __construct(
        private AssetTypeSerializationHandlerService $assetTypeSerializationHandlerService
    ) {
    }

    /**
     * @param array $data
     */
    public function denormalize(
        mixed $data,
        string $type,
        string $format = null,
        array $context = []
    ): AssetSearchResultItem {

        $serializationHandler = $this->assetTypeSerializationHandlerService->getSerializationHandler(
            SystemField::TYPE->getData($data)
        );

        if ($serializationHandler) {
            $searchResultItem = $serializationHandler->createSearchResultModel($data);
        } else {
            $searchResultItem = new AssetSearchResultItem();
        }

        $searchResultItem
            ->setId(SystemField::ID->getData($data))
            ->setParentId(SystemField::PARENT_ID->getData($data))
            ->setType(SystemField::TYPE->getData($data))
            ->setKey(SystemField::KEY->getData($data))
            ->setPath(SystemField::PATH->getData($data))
            ->setFullPath(SystemField::FULL_PATH->getData($data))
            ->setMimeType(SystemField::MIME_TYPE->getData($data))
            ->setUserOwner(SystemField::USER_OWNER->getData($data) ?? 0)
            ->setUserModification(SystemField::USER_MODIFICATION->getData($data))
            ->setLocked(SystemField::LOCKED->getData($data))
            ->setIsLocked(SystemField::IS_LOCKED->getData($data))
            ->setMetaData($this->hydrateMetadata($data[FieldCategory::STANDARD_FIELDS->value]))
            ->setCreationDate(strtotime(SystemField::CREATION_DATE->getData($data)))
            ->setModificationDate(strtotime(SystemField::MODIFICATION_DATE->getData($data)));

        if (SerializerContext::SKIP_LAZY_LOADED_FIELDS->containedInContext($context)) {
            return $searchResultItem;
        }

        return $searchResultItem
            ->setFileSize(SystemField::FILE_SIZE->getData($data))
            ->setHasWorkflowWithPermissions(SystemField::HAS_WORKFLOW_WITH_PERMISSIONS->getData($data))
            ->setHasChildren(SystemField::HAS_CHILDREN->getData($data))
            ->setSearchIndexData($data);

    }

    public function supportsDenormalization(mixed $data, string $type, string $format = null): bool
    {
        return is_array($data) && is_subclass_of($type, AssetSearchResultItem::class);
    }

    /**
     * @return AssetMetaData[]
     */
    private function hydrateMetadata(array $standardFields): array
    {
        $result = [];

        foreach ($standardFields as $language => $fields) {
            foreach ($fields as $fieldName => $fieldData) {

                $result[] = new AssetMetaData(
                    name: $fieldName,
                    language: $language !== MappingProperty::NOT_LOCALIZED_KEY ? $language : null,
                    data: $fieldData,
                );
            }
        }

        return $result;
    }
}
