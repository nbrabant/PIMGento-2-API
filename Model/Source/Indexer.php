<?php

namespace Pimgento\Api\Model\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Class Indexer
 *
 * @category  Class
 * @package   Pimgento\Api\Model\Source
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2019 Agence Dn'D
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.dnd.fr/
 */
class Indexer implements ArrayInterface
{
    /**
     * Indexer options
     *
     * @var array $options
     */
    protected $options;
    /**
     * This variable contain Logger
     *
     * @var LoggerInterface $logger
     */
    private $logger;
    /**
     * This variable contains CollectionFactory
     *
     * @var CollectionFactory $indexerCollectionFactory
     */
    protected $indexerCollectionFactory;

    /**
     * Indexer constructor
     *
     * @param CollectionFactory $indexerCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        CollectionFactory $indexerCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->indexerCollectionFactory = $indexerCollectionFactory;
        $this->logger                   = $logger;
    }

    /**
     * Return indexer options array
     *
     * @return string[]
     */
    public function toOptionArray()
    {
        /** @var \Magento\Indexer\Model\Indexer\Collection $indexerCollection */
        $indexerCollection = $this->indexerCollectionFactory->create();
        /** @var string[] $indexerIds */
        $indexerIds = $indexerCollection->getAllIds();

        $this->options = [];
        foreach ($indexerIds as $indexId) {
            $this->options[] = ['value' => $indexId, 'label' => $indexId];
        }

        return $this->options;
    }
}
