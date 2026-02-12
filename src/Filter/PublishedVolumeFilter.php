<?php

namespace App\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Global Doctrine filter: only documents belonging to published volumes.
 *
 * Applied automatically to all DQL/QueryBuilder queries on Document entity.
 * For raw DBAL queries, use DocumentRepository::PUBLISHED_FILTER constant.
 */
class PublishedVolumeFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getTableName() === 'documents') {
            return sprintf(
                "EXISTS (SELECT 1 FROM volumes _pv WHERE _pv.id = %s.volume_id AND _pv.status = 'opublikowany')",
                $targetTableAlias
            );
        }

        return '';
    }
}
