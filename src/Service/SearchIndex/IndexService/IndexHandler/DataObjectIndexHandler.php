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

namespace Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\IndexService\IndexHandler;

use Pimcore\Bundle\GenericDataIndexBundle\Enum\SearchIndex\FieldCategory;
use Pimcore\Bundle\GenericDataIndexBundle\Event\DataObject\ExtractMappingEvent;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\IndexService\ElementTypeAdapter\DataObjectTypeAdapter;
use Pimcore\Bundle\GenericDataIndexBundle\Service\SearchIndex\SearchIndexConfigService;
use Pimcore\Model\DataObject\ClassDefinition;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal
 */
final class DataObjectIndexHandler extends AbstractIndexHandler
{
    public const DATA_OBJECT_INDEX_ALIAS = 'data-object';

    private DataObjectTypeAdapter $dataObjectTypeAdapter;

    protected function extractMappingProperties(mixed $context = null): array
    {
        if (!$context instanceof ClassDefinition) {
            return [];
        }

        return $this->extractMappingByClassDefinition(
            $context
        );
    }

    protected function createIndex(mixed $context, string $aliasName): void
    {
        parent::createIndex($context, $aliasName);

        $this->searchIndexService->putAlias(
            $this->searchIndexConfigService->getIndexName(self::DATA_OBJECT_INDEX_ALIAS),
            $this->getCurrentFullIndexName($context)
        );
    }

    protected function getAliasIndexName(mixed $context = null): string
    {
        return $this->dataObjectTypeAdapter->getAliasIndexName($context);
    }

    private function extractMappingByClassDefinition(ClassDefinition $classDefinition): array
    {
        $mappingProperties = [
            FieldCategory::SYSTEM_FIELDS->value => [
                'properties' => $this->searchIndexConfigService
                    ->getSystemFieldsSettings(SearchIndexConfigService::SYSTEM_FIELD_DATA_OBJECT),
            ],
            FieldCategory::STANDARD_FIELDS->value => $this->indexMappingService->getMappingForFieldDefinitions(
                $classDefinition->getFieldDefinitions()
            ),
            FieldCategory::CUSTOM_FIELDS->value => [],
        ];

        $mappingProperties[FieldCategory::CUSTOM_FIELDS->value]['properties'] =
            $this->fireEventAndGetCustomFieldsMapping(
                $classDefinition,
                $mappingProperties[FieldCategory::CUSTOM_FIELDS->value]
            );

        return $mappingProperties;
    }

    #[Required]
    public function setDataObjectTypeAdapter(DataObjectTypeAdapter $dataObjectTypeAdapter): void
    {
        $this->dataObjectTypeAdapter = $dataObjectTypeAdapter;
    }

    public function fireEventAndGetCustomFieldsMapping(ClassDefinition $classDefinition, array $customFields): array
    {
        $extractMappingEvent = new ExtractMappingEvent($classDefinition, $customFields);
        $this->eventDispatcher->dispatch($extractMappingEvent);

        return $extractMappingEvent->getCustomFieldsMapping();
    }
}
