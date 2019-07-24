<?php

namespace Pimgento\Api\Job;

use Akeneo\Pim\ApiClient\Pagination\PageInterface;
use Akeneo\Pim\ApiClient\Pagination\ResourceCursorInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\Entity\Attribute\Set;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Pimgento\Api\Helper\Authenticator;
use Pimgento\Api\Helper\Import\Entities;
use Pimgento\Api\Helper\Config as ConfigHelper;
use Zend_Db_Expr as Expr;
use Pimgento\Api\Helper\Output as OutputHelper;
use Pimgento\Api\Helper\Store as StoreHelper;
use Magento\Indexer\Model\IndexerFactory;

/**
 * Class Family
 *
 * @category  Class
 * @package   Pimgento\Api\Job
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2018 Agence Dn'D
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
class Family extends Import
{
    /**
     * This variable contains a string value
     *
     * @var string $code
     */
    protected $code = 'family';
    /**
     * This variable contains a string value
     *
     * @var string $name
     */
    protected $name = 'Family';
    /**
     * This variable contains an EntitiesHelper
     *
     * @var Entities $entitiesHelper
     */
    protected $entitiesHelper;
    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * This variable contains a SetFactory
     *
     * @var SetFactory $attributeSetFactory
     */
    protected $attributeSetFactory;
    /**
     * This variable contains a TypeListInterface
     *
     * @var TypeListInterface $cacheTypeList
     */
    protected $cacheTypeList;
    /**
     * This variable contains an EavConfig
     *
     * @var Config $eavConfig
     */
    protected $eavConfig;
    /**
     * This variable contains a StoreHelper
     *
     * @var StoreHelper $storeHelper
     */
    protected $storeHelper;

    /**
     * Family constructor
     *
     * @param StoreHelper $storeHelper
     * @param Entities $entitiesHelper
     * @param ConfigHelper $configHelper
     * @param OutputHelper $outputHelper
     * @param ManagerInterface $eventManager
     * @param Authenticator $authenticator
     * @param SetFactory $attributeSetFactory
     * @param TypeListInterface $cacheTypeList
     * @param Config $eavConfig
     * @param array $data
     * @param IndexerFactory $indexerFactory
     */
    public function __construct(
        StoreHelper $storeHelper,
        Entities $entitiesHelper,
        ConfigHelper $configHelper,
        OutputHelper $outputHelper,
        ManagerInterface $eventManager,
        Authenticator $authenticator,
        SetFactory $attributeSetFactory,
        TypeListInterface $cacheTypeList,
        Config $eavConfig,
        array $data = [],
        IndexerFactory $indexerFactory
    ) {
        parent::__construct($outputHelper, $eventManager, $authenticator, $data, $indexerFactory);

        $this->configHelper        = $configHelper;
        $this->entitiesHelper      = $entitiesHelper;
        $this->attributeSetFactory = $attributeSetFactory;
        $this->cacheTypeList       = $cacheTypeList;
        $this->eavConfig           = $eavConfig;
        $this->storeHelper         = $storeHelper;
    }

    /**
     * Create temporary table for family import
     *
     * @return void
     */
    public function createTable()
    {
        /** @var PageInterface $families */
        $families = $this->akeneoClient->getFamilyApi()->listPerPage(1);
        /** @var array $family */
        $family = $families->getItems();

        if (empty($family)) {
            $this->setMessage(__('No results retrieved from Akeneo'));
            $this->stop(1);

            return;
        }
        $family = reset($family);
        $this->entitiesHelper->createTmpTableFromApi($family, $this->getCode());
    }

    /**
     * Insert families in the temporary table
     *
     * @return void
     */
    public function insertData()
    {
        /** @var string|int $paginationSize */
        $paginationSize = $this->configHelper->getPanigationSize();
        /** @var ResourceCursorInterface $families */
        $families = $this->akeneoClient->getFamilyApi()->all($paginationSize);
        /** @var string $warning */
        $warning = '';
        /**
         * @var int $index
         * @var array $family
         */
        foreach ($families as $index => $family) {
            /** @var string[] $lang */
            $lang = $this->storeHelper->getStores('lang');
            $warning = $this->checkLabelPerLocales($family, $lang, $warning);

            $this->entitiesHelper->insertDataFromApi($family, $this->getCode());
        }
        $index++;

        $this->setMessage(
            __('%1 line(s) found. %2', $index, $warning)
        );
    }

    /**
     * Match code with entity
     *
     * @return void
     */
    public function matchEntities()
    {
        $this->entitiesHelper->matchEntity('code', 'eav_attribute_set', 'attribute_set_id', $this->getCode());
    }

    /**
     * Insert families
     *
     * @return void
     */
    public function insertFamilies()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());
        /** @var string $label */
        $label = 'labels-'.$this->configHelper->getDefaultLocale();
        /** @var string $productEntityTypeId */
        $productEntityTypeId = $this->eavConfig->getEntityType(ProductAttributeInterface::ENTITY_TYPE_CODE)
            ->getEntityTypeId();
        /** @var array $values */
        $values = [
            'attribute_set_id'   => '_entity_id',
            'entity_type_id'     => new Expr($productEntityTypeId),
            'attribute_set_name' => new Expr('CONCAT("Pim", " ", `' . $label . '`)'),
            'sort_order'         => new Expr(1),
        ];
        /** @var Select $families */
        $families = $connection->select()->from($tmpTable, $values);

        $connection->query(
            $connection->insertFromSelect(
                $families,
                $this->entitiesHelper->getTable('eav_attribute_set'),
                array_keys($values),
                1
            )
        );
    }

    /**
     * Insert relations between family and list of attributes
     *
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function insertFamiliesAttributeRelations()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());
        /** @var string $familyAttributeRelationsTable */
        $familyAttributeRelationsTable = $this->entitiesHelper->getTable('pimgento_family_attribute_relations');

        $connection->delete($familyAttributeRelationsTable);
        /** @var array $values */
        $values = [
            'family_entity_id' => '_entity_id',
            'attribute_code'   => 'attributes',
        ];
        /** @var Select $relations */
        $relations = $connection->select()->from($tmpTable, $values);
        /** @var \Zend_Db_Statement_Interface $query */
        $query = $connection->query($relations);
        /** @var array $row */
        while ($row = $query->fetch()) {
            /** @var array $attributes */
            $attributes = explode(',', $row['attribute_code']);
            /** @var string $attribute */
            foreach ($attributes as $attribute) {
                $connection->insert(
                    $familyAttributeRelationsTable,
                    ['family_entity_id' => $row['family_entity_id'], 'attribute_code' => $attribute]
                );
            }
        }
    }

    /**
     * Init group
     *
     * @return void
     * @throws \Exception
     * @throws \Zend_Db_Statement_Exception
     */
    public function initGroup()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->getCode());
        /** @var \Zend_Db_Statement_Interface $query */
        $query = $connection->query(
            $connection->select()->from($tmpTable, ['_entity_id'])->where('_is_new = ?', 1)
        );
        /** @var string $defaultAttributeSetId */
        $defaultAttributeSetId = $this->eavConfig->getEntityType(ProductAttributeInterface::ENTITY_TYPE_CODE)
            ->getDefaultAttributeSetId();
        /** @var int $count */
        $count = 0;
        /** @var array $row */
        while (($row = $query->fetch())) {
            /** @var Set $attributeSet */
            $attributeSet = $this->attributeSetFactory->create();
            $attributeSet->load($row['_entity_id']);

            if ($attributeSet->hasData()) {
                $attributeSet->initFromSkeleton($defaultAttributeSetId)->save();
            }
            $count++;
        }

        $this->setMessage(
            __('%1 family(ies) initialized', $count)
        );
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
        $isActiveReindex = $this->configHelper->getIsFamilyReindexActive();
        if (!$isActiveReindex) {
            $this->setStatus(false);
            $this->setMessage(
                __('Data reindexing is disabled.')
            );

            return;
        }

        /** @var string $indexerProcesses */
        $indexerProcesses = $this->configHelper->getFamilyReindexSelection();
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
        $isActiveCacheClear = $this->configHelper->getIsFamilyClearCacheActive();
        if (!$isActiveCacheClear) {
            $this->setStatus(false);
            $this->setMessage(
                __('Cache cleaning is disable.')
            );

            return;
        }

        /** @var string $config */
        $config = $this->configHelper->getFamilyCacheSelection();
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
}
