<?php

namespace Pimgento\Api\Model\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Cache
 *
 * @category  Class
 * @package   Pimgento\Api\Model\Source
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2019 Agence Dn'D
 * @license   https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.dnd.fr/
 */
class Cache implements ArrayInterface
{
    /**
     * Cache options
     *
     * @var array $options
     */
    protected $options = [];
    /**
     * Magento cache list interface
     *
     * @var TypeListInterface $cacheTypeList
     */
    private $cacheTypeList;
    /**
     * This variable contain Logger
     *
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * Cache constructor
     *
     * @param TypeListInterface $cacheTypeList
     * @param LoggerInterface $logger
     */
    public function __construct(
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger
    ) {
        $this->cacheTypeList = $cacheTypeList;
        $this->logger = $logger;
    }

    /**
     * Return cache options array
     *
     * @return array
     */
    public function toOptionArray()
    {
        /** @var Magento\Framework\DataObject $types */
        $types = $this->cacheTypeList->getTypes();

        $this->options = [];
        foreach ($types as $type) {
            $this->options[] = ['value' => $type->getId(), 'label' => $type->getCacheType()];
        }

        return $this->options;
    }
}
