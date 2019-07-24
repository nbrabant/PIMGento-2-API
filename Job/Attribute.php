<?php

namespace Pimgento\Api\Job;

use Akeneo\Pim\ApiClient\Pagination\PageInterface;
use Akeneo\Pim\ApiClient\Pagination\ResourceCursorInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Eav\Model\Config;
use Pimgento\Api\Helper\Authenticator;
use Pimgento\Api\Helper\Config as ConfigHelper;
use Pimgento\Api\Helper\Import\Attribute as AttributeHelper;
use Pimgento\Api\Helper\Import\Entities as EntitiesHelper;
use Pimgento\Api\Helper\Output as OutputHelper;
use Pimgento\Api\Helper\Store as StoreHelper;
use \Zend_Db_Expr as Expr;
use Magento\Indexer\Model\IndexerFactory;

/**
 * Class Attribute
 *
 * @category  Class
 * @package   Pimgento\Api\Job
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2018 Agence Dn'D
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
class Attribute extends Import
{
    /**
     * This variable contains a string value
     *
     * @var string $code
     */
    protected $code = 'attribute';
    /**
     * This variable contains a string value
     *
     * @var string $name
     */
    protected $name = 'Attribute';
    /**
     * This variable contains an EntitiesHelper
     *
     * @var EntitiesHelper $entitiesHelper
     */
    protected $entitiesHelper;
    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * This variable contains a Config
     *
     * @var Config $eavConfig
     */
    protected $eavConfig;
    /**
     * This variable contains an AttributeHelper
     *
     * @var AttributeHelper $attributeHelper
     */
    protected $attributeHelper;
    /**
     * This variable contains a TypeListInterface
     *
     * @var TypeListInterface $cacheTypeList
     */
    protected $cacheTypeList;
    /**
     * This variable contains a StoreHelper
     *
     * @var StoreHelper $storeHelper
     */
    protected $storeHelper;
    /**
     * This variable contains an EavSetup
     *
     * @var EavSetup $eavSetup
     */
    protected $eavSetup;
    /**
     * This variable contains IndexerFactory
     *
     * @var IndexerFactory $indexerFactory
     */
    protected $indexerFactory;

    /**
     * Attribute constructor
     *
     * @param OutputHelper $outputHelper
     * @param ManagerInterface $eventManager
     * @param Authenticator $authenticator
     * @param EntitiesHelper $entitiesHelper
     * @param ConfigHelper $configHelper
     * @param Config $eavConfig
     * @param AttributeHelper $attributeHelper
     * @param TypeListInterface $cacheTypeList
     * @param StoreHelper $storeHelper
     * @param EavSetup $eavSetup
     * @param array $data
     * @param IndexerFactory $indexerFactory
     */
    public function __construct(
        OutputHelper $outputHelper,
        ManagerInterface $eventManager,
        Authenticator $authenticator,
        EntitiesHelper $entitiesHelper,
        ConfigHelper $configHelper,
        Config $eavConfig,
        AttributeHelper $attributeHelper,
        TypeListInterface $cacheTypeList,
        StoreHelper $storeHelper,
        EavSetup $eavSetup,
        array $data = [],
        IndexerFactory $indexerFactory
    ) {
        parent::__construct($outputHelper, $eventManager, $authenticator, $data, $indexerFactory);

        $this->entitiesHelper  = $entitiesHelper;
        $this->configHelper    = $configHelper;
        $this->eavConfig       = $eavConfig;
        $this->attributeHelper = $attributeHelper;
        $this->cacheTypeList   = $cacheTypeList;
        $this->storeHelper     = $storeHelper;
        $this->eavSetup        = $eavSetup;
        $this->indexerFactory  = $indexerFactory;
    }

    /**
     * Create temporary table
     *
     * @return void
     */
    public function createTable()
    {
        /** @var PageInterface $attributes */
        $attributes = $this->akeneoClient->getAttributeApi()->listPerPage(1);
        /** @var array $attribute */
        $attribute = $attributes->getItems();
        if (empty($attribute)) {
            $this->setMessage(__('No results from Akeneo'));
            $this->stop(1);

            return;
        }
        $attribute = reset($attribute);
        $this->entitiesHelper->createTmpTableFromApi($attribute, $this->getCode());
    }

    /**
     * Insert data into temporary table
     *
     * @return void
     */
    public function insertData()
    {
        /** @var string|int $paginationSize */
        $paginationSize = $this->configHelper->getPanigationSize();
        /** @var ResourceCursorInterface $attributes */
        $attributes = $this->akeneoClient->getAttributeApi()->all($paginationSize);

        /**
         * @var int $index
         * @var array $attribute
         */
        foreach ($attributes as $index => $attribute) {
            $attribute['code'] = strtolower($attribute['code']);

            $this->entitiesHelper->insertDataFromApi($attribute, $this->getCode());
        }
        $index++;

        $this->setMessage(
            __('%1 line(s) found', $index)
        );
    }

    /**
     * Match code with entity
     *
     * @return void
     */
    public function matchEntities()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var Select $select */
        $select = $connection->select()->from(
            $this->entitiesHelper->getTable('eav_attribute'),
            [
                'import'    => new Expr('"attribute"'),
                'code'      => 'attribute_code',
                'entity_id' => 'attribute_id',
            ]
        )->where('entity_type_id = ?', $this->getEntityTypeId());

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->entitiesHelper->getTable('pimgento_entities'),
                ['import', 'code', 'entity_id'],
                2
            )
        );

        $this->entitiesHelper->matchEntity('code', 'eav_attribute', 'attribute_id', $this->getCode());
    }

    /**
     * Match type with Magento logic
     *
     * @return void
     */
    public function matchType()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());
        /** @var array $columns */
        $columns = $this->attributeHelper->getSpecificColumns();
        /**
         * @var string $name
         * @var array $def
         */
        foreach ($columns as $name => $def) {
            $connection->addColumn($tmpTable, $name, $def['type']);
        }

        /** @var Select $select */
        $select = $connection->select()->from(
            $tmpTable,
            array_merge(
                ['_entity_id', 'type'],
                array_keys($columns)
            )
        );
        /** @var array $data */
        $data = $connection->fetchAssoc($select);
        /**
         * @var int $id
         * @var array $attribute
         */
        foreach ($data as $id => $attribute) {
            /** @var array $type */
            $type = $this->attributeHelper->getType($attribute['type']);

            $connection->update($tmpTable, $type, ['_entity_id = ?' => $id]);
        }
    }

    /**
     * Match family code with Magento group id
     *
     * @return void
     */
    public function matchFamily()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());
        /** @var string $familyAttributeRelationsTable */
        $familyAttributeRelationsTable = $this->entitiesHelper->getTable('pimgento_family_attribute_relations');

        $connection->addColumn($tmpTable, '_attribute_set_id', 'text');
        /** @var string $importTmpTable */
        $importTmpTable = $connection->select()->from($tmpTable, ['code', '_entity_id']);
        /** @var string $queryTmpTable */
        $queryTmpTable = $connection->query($importTmpTable);

        while ($row = $queryTmpTable->fetch()) {
            /** @var string $attributeCode */
            $attributeCode = $row['code'];
            /** @var Select $importRelations */
            $importRelations = $connection->select()->from($familyAttributeRelationsTable, 'family_entity_id')->where(
                $connection->prepareSqlCondition('attribute_code', ['like' => $attributeCode])
            );
            /** @var \Zend_Db_Statement_Interface $queryRelations */
            $queryRelations = $connection->query($importRelations);
            /** @var string $attributeIds */
            $attributeIds = '';
            while ($innerRow = $queryRelations->fetch()) {
                $attributeIds .= $innerRow['family_entity_id'] . ',';
            }
            $attributeIds = rtrim($attributeIds, ',');

            $connection->update($tmpTable, ['_attribute_set_id' => $attributeIds], '_entity_id=' . $row['_entity_id']);
        }
    }

    /**
     * Add attributes if not exists
     *
     * @return void
     */
    public function addAttributes()
    {
        /** @var array $columns */
        $columns = $this->attributeHelper->getSpecificColumns();
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());

        /** @var string $adminLang */
        $adminLang = $this->storeHelper->getAdminLang();
        /** @var string $adminLabelColumn */
        $adminLabelColumn = sprintf('labels-%s', $adminLang);

        /** @var Select $import */
        $import = $connection->select()->from($tmpTable);
        /** @var \Zend_Db_Statement_Interface $query */
        $query = $connection->query($import);

        while (($row = $query->fetch())) {
            /* Insert base data (ignore if already exists) */
            /** @var string[] $values */
            $values = [
                'attribute_id'   => $row['_entity_id'],
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
            ];
            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('eav_attribute'),
                $values,
                array_keys($values)
            );

            $values = [
                'attribute_id' => $row['_entity_id'],
            ];
            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('catalog_eav_attribute'),
                $values,
                array_keys($values)
            );

            /* Retrieve default admin label */
            /** @var string $frontendLabel */
            $frontendLabel = __('Unknown');
            if (!empty($row[$adminLabelColumn])) {
                $frontendLabel = $row[$adminLabelColumn];
            }

            /* Retrieve attribute scope */
            /** @var int $global */
            $global = ScopedAttributeInterface::SCOPE_GLOBAL; // Global
            if ($row['scopable'] == 1) {
                $global = ScopedAttributeInterface::SCOPE_WEBSITE; // Website
            }
            if ($row['localizable'] == 1) {
                $global = ScopedAttributeInterface::SCOPE_STORE; // Store View
            }
            /** @var array $data */
            $data = [
                'entity_type_id' => $this->getEntityTypeId(),
                'attribute_code' => $row['code'],
                'frontend_label' => $frontendLabel,
                'is_global'      => $global,
            ];
            foreach ($columns as $column => $def) {
                if (!$def['only_init']) {
                    $data[$column] = $row[$column];
                }
            }
            /** @var array $defaultValues */
            $defaultValues = [];
            if ($row['_is_new'] == 1) {
                $defaultValues = [
                    'backend_table'                 => null,
                    'frontend_class'                => null,
                    'is_required'                   => 0,
                    'is_user_defined'               => 1,
                    'default_value'                 => null,
                    'is_unique'                     => $row['unique'],
                    'note'                          => null,
                    'is_visible'                    => 1,
                    'is_system'                     => 1,
                    'input_filter'                  => null,
                    'multiline_count'               => 0,
                    'validate_rules'                => null,
                    'data_model'                    => null,
                    'sort_order'                    => 0,
                    'is_used_in_grid'               => 0,
                    'is_visible_in_grid'            => 0,
                    'is_filterable_in_grid'         => 0,
                    'is_searchable_in_grid'         => 0,
                    'frontend_input_renderer'       => null,
                    'is_searchable'                 => 0,
                    'is_filterable'                 => 0,
                    'is_comparable'                 => 0,
                    'is_visible_on_front'           => 0,
                    'is_wysiwyg_enabled'            => 0,
                    'is_html_allowed_on_front'      => 0,
                    'is_visible_in_advanced_search' => 0,
                    'is_filterable_in_search'       => 0,
                    'used_in_product_listing'       => 0,
                    'used_for_sort_by'              => 0,
                    'apply_to'                      => null,
                    'position'                      => 0,
                    'is_used_for_promo_rules'       => 0,
                ];

                foreach (array_keys($columns) as $column) {
                    $data[$column] = $row[$column];
                }
            }

            $data = array_merge($defaultValues, $data);
            $this->eavSetup->updateAttribute(
                $this->getEntityTypeId(),
                $row['_entity_id'],
                $data,
                null,
                0
            );

            /* Add Attribute to group and family */
            if ($row['_attribute_set_id'] && $row['group']) {
                $attributeSetIds = explode(',', $row['_attribute_set_id']);

                if (is_numeric($row['group'])) {
                    $row['group'] = 'PIM' . $row['group'];
                }

                foreach ($attributeSetIds as $attributeSetId) {
                    if (is_numeric($attributeSetId)) {
                        $this->eavSetup->addAttributeGroup(
                            $this->getEntityTypeId(),
                            $attributeSetId,
                            ucfirst($row['group'])
                        );
                        $this->eavSetup->addAttributeToSet(
                            $this->getEntityTypeId(),
                            $attributeSetId,
                            ucfirst($row['group']),
                            $row['_entity_id']
                        );
                    }
                }
            }

            /* Add store labels */
            /** @var array $stores */
            $stores = $this->storeHelper->getStores('lang');
            /**
             * @var string $lang
             * @var array $data
             */
            foreach ($stores as $lang => $data) {
                if (isset($row['labels-'.$lang])) {
                    /** @var array $store */
                    foreach ($data as $store) {
                        /** @var string $exists */
                        $exists = $connection->fetchOne(
                            $connection->select()->from($this->entitiesHelper->getTable('eav_attribute_label'))->where(
                                'attribute_id = ?',
                                $row['_entity_id']
                            )->where('store_id = ?', $store['store_id'])
                        );

                        if ($exists) {
                            /** @var array $values */
                            $values = [
                                'value' => $row['labels-'.$lang],
                            ];
                            /** @var array $where */
                            $where  = [
                                'attribute_id = ?' => $row['_entity_id'],
                                'store_id = ?'     => $store['store_id'],
                            ];

                            $connection->update($this->entitiesHelper->getTable('eav_attribute_label'), $values, $where);
                        } else {
                            $values = [
                                'attribute_id' => $row['_entity_id'],
                                'store_id'     => $store['store_id'],
                                'value'        => $row['labels-'.$lang],
                            ];
                            $connection->insert($this->entitiesHelper->getTable('eav_attribute_label'), $values);
                        }
                    }
                }
            }
        }
    }

    /**
     * Drop temporary table
     *
     * @return void
     */
    public function dropTable()
    {
        $this->entitiesHelper->dropTable($this->getCode());
    }

    /**
     * Reindex selected data
     *
     * @return void
     */
    public function reindexData()
    {
        /** @var string $isActiveReindex */
        $isActiveReindex = $this->configHelper->getIsAttributeReindexActive();
        if (!$isActiveReindex) {
            $this->setStatus(false);
            $this->setMessage(
                __('Data reindexing is disabled.')
            );

            return;
        }

        /** @var string $indexerProcesses */
        $indexerProcesses = $this->configHelper->getAttributeIndexSelection();
        if (empty($indexerProcesses)) {
            $this->setStatus(false);
            $this->setMessage(
                __('No index selected.')
            );

            return;
        }

        /** @var string[] $index */
        $index = explode(',', $indexerProcesses);
        $this->indexerProcesses = $index;
        $this->reindex();

        $this->setMessage(
            __('Data reindexed for: %1', join(', ', $this->indexerProcesses))
        );
    }

    /**
     * Clean cache
     *
     * @return void
     */
    public function cleanCache()
    {
        /** @var  $isActiveCacheClear */
        $isActiveCacheClear = $this->configHelper->getIsAttributeClearCacheActive();
        if (!$isActiveCacheClear) {
            $this->setStatus(false);
            $this->setMessage(
                __('Cache cleaning is disable.')
            );

            return;
        }

        /** @var string $config */
        $config = $this->configHelper->getAttributeCacheSelection();
        if (empty($config)) {
            $this->setStatus(false);
            $this->setMessage(
                __('No cache selected.')
            );

            return;
        }
        /** @var string[] $types */
        $types = explode(',', $config);

        /** @var string $type */
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        $this->setMessage(
            __('Cache cleaned for: %1', join(', ', $types))
        );
    }

    /**
     * Get the product entity type id
     *
     * @return string
     */
    protected function getEntityTypeId()
    {
        /** @var string $productEntityTypeId */
        $productEntityTypeId = $this->eavConfig->getEntityType(ProductAttributeInterface::ENTITY_TYPE_CODE)
            ->getEntityTypeId();

        return $productEntityTypeId;
    }
}
