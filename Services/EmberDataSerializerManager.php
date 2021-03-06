<?php

namespace UniqueLibs\EmberDataSerializerBundle\Services;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use UniqueLibs\EmberDataSerializerBundle\Exception\InvalidEmberDataSerializerAdapterServiceNameException;
use UniqueLibs\EmberDataSerializerBundle\Exception\InvalidEmberDataSerializerInputException;
use UniqueLibs\EmberDataSerializerBundle\Interfaces\EmberDataSerializableInterface;
use UniqueLibs\EmberDataSerializerBundle\Interfaces\EmberDataSerializerAdapterInterface;

/**
 * Class EmberDataSerializerManager
 *
 * @package Main\MainBundle\Services
 * @author  Marvin Rind <marvin.rind@uniquelibs.com>
 */
class EmberDataSerializerManager implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface|null
     */
    private $container;

    /**
     * Contains parsed data in ember format.
     *
     * @var array
     */
    protected $data;

    /**
     * Contains cached ember data serializer adapters.
     *
     * @var EmberDataSerializerAdapterInterface[]
     */
    protected $adapters;

    public function __construct()
    {
        $this->data = array();
        $this->adapters = array();
    }

    /**
     * Formats array full of same entities or one entity while ignoring side-loading.
     *
     * @param EmberDataSerializableInterface[]|EmberDataSerializableInterface $objects
     *
     * @return array
     */
    public function formatWithoutRecursion($objects)
    {
        if (is_array($objects)) {
            $this->format($objects, null, 0);
        } else {
            $this->formatOne($objects, null, 0);
        }
        return $this->data;
    }

    /**
     * @param EmberDataSerializableInterface[] $objects
     * @param null|string                      $forcedKey
     * @param int                              $maxDepth
     *
     * @return array
     *
     * @throws InvalidEmberDataSerializerInputException
     */
    public function format($objects, $forcedKey = null, $maxDepth = INF)
    {
        $this->createDataKeyIfNotExists($forcedKey);

        if (!isset($objects[0])) {
            return $this->data;
        }

        $className = $this->getClass($objects[0]);

        if (!$objects[0] instanceof EmberDataSerializableInterface) {
            throw new InvalidEmberDataSerializerInputException("Objects needs to implement the EmberDataSerializableInterface.");
        }

        foreach ($objects as $object) {
            if ($this->getClass($object) != $className) {
                throw new InvalidEmberDataSerializerInputException("Objects needs to be an array containing the same classes.");
            }
        }

        foreach ($objects as $object) {
            $this->checkAccessAndParseSerializableObject($object, $maxDepth);
        }

        return $this->data;
    }

    /**
     * @param EmberDataSerializableInterface $object
     * @param null|string                    $forcedKey
     *
     * @return array
     * @throws \Exception
     */
    public function formatOne(EmberDataSerializableInterface $object, $forcedKey = null, $maxDepth = INF)
    {
        $this->createDataKeyIfNotExists($forcedKey);

        $this->parseSerializableObject($object, false, $maxDepth);

        return $this->data;
    }

    /**
     * @param string|null $key
     */
    private function createDataKeyIfNotExists($key = null)
    {
        if (!is_null($key)) {
            if (!isset($this->data[$key])) {
                $this->data[$key] = array();
            }
        }
    }

    /**
     * @param EmberDataSerializableInterface $object
     *
     * @throws \Exception
     */
    private function checkAccessAndParseSerializableObject(EmberDataSerializableInterface $object, $maxDepth)
    {
        $adapter = $this->getSerializerAdapterOrNullBySerializableObject($object);

        if (is_null($adapter)) {
            return;
        }

        $objectId = $adapter->getId($object);

        if (isset($this->data[$adapter->getModelNameSingular()])) {
            if ($this->data[$adapter->getModelNameSingular()]['id'] == $objectId) {
                return;
            }
        }

        if (isset($this->data[$adapter->getModelNamePlural()])) {
            foreach ($this->data[$adapter->getModelNamePlural()] as $check) {
                if ($check['id'] == $objectId) {
                    return;
                }
            }
        } else {
            $this->data[$adapter->getModelNamePlural()] = array();
        }

        $this->parseSerializableObject($object, true, $maxDepth);
    }

    /**
     * @param EmberDataSerializableInterface $object
     * @param bool                           $plural
     *
     * @throws InvalidEmberDataSerializerInputException
     */
    private function parseSerializableObject(EmberDataSerializableInterface $object, $plural, $maxDepth)
    {
        $adapter = $this->getSerializerAdapterOrNullBySerializableObject($object);

        if (is_null($adapter)) {
            return;
        }

        $index = 0;

        if ($plural) {
            $this->data[$adapter->getModelNamePlural()][] = array();
            $index = count($this->data[$adapter->getModelNamePlural()])-1;
        } else {
            $this->data[$adapter->getModelNameSingular()] = array();
        }

        $data = $adapter->getData($object);

        assert(eval('
            foreach($data as $key => $val) {
                if (!is_array($val)) {
                    echo "$key must contain an array.\n\n";
                    return false;
                }
            }
            return true;
        '), 'Every row must be an array. But ' . $object->getEmberDataSerializerAdapterServiceName() . ' failed to do that.');

        foreach ($data as $key => $array) {

            $value = $array[0];
            $recurse = $array[1];

            if ($this->isArrayCollection($value)) {

                if (count($value) && $this->getLast($value) instanceof EmberDataSerializableInterface) {

                    foreach ($value as $var) {
                        if (!$var instanceof EmberDataSerializableInterface) {
                            throw new InvalidEmberDataSerializerInputException("Each array element needs to be an instance of EmberDataSerializableInterface.");
                        }
                    }

                    $allocatedData = array();

                    $valueAdapter = $this->getSerializerAdapterOrNullBySerializableObject($this->getLast($value));

                    if (!is_null($valueAdapter)) {
                        /** @var EmberDataSerializableInterface $v */
                        foreach ($value as $v) {

                            if ($valueAdapter->hasAccess($v)) {
                                $allocatedData[] = $valueAdapter->getId($v);
                            }

                        }
                    }

                    if ($plural) {
                        $this->data[$adapter->getModelNamePlural()][$index][$key] = $allocatedData;
                    } else {
                        $this->data[$adapter->getModelNameSingular()][$key] = $allocatedData;
                    }

                    if ($recurse && $maxDepth > 0) {
                        $this->format($value, null, $maxDepth - 1);
                    }

                } else {
                    if ($plural) {
                        $this->data[$adapter->getModelNamePlural()][$index][$key] = count($value) === 0 ? [] : $value;
                    } else {
                        $this->data[$adapter->getModelNameSingular()][$key] = count($value) === 0 ? [] : $value;
                    }
                }
            } else if ($value instanceof EmberDataSerializableInterface) {

                $valueAdapter = $this->getSerializerAdapterOrNullBySerializableObject($value);

                if (!is_null($valueAdapter)) {
                    if ($valueAdapter->hasAccess($value)) {
                        $valueId = $valueAdapter->getId($value);

                        if ($plural) {
                            $this->data[$adapter->getModelNamePlural()][$index][$key] = $valueId;
                        } else {
                            $this->data[$adapter->getModelNameSingular()][$key] = $valueId;
                        }

                        if ($recurse && $maxDepth > 0) {
                            $this->checkAccessAndParseSerializableObject($value, $maxDepth - 1);
                        }
                    }
                }

            } else {

                if ($plural) {
                    $this->data[$adapter->getModelNamePlural()][$index][$key] = $value instanceof \DateTime ? $value->format('c') : $value;
                } else {
                    $this->data[$adapter->getModelNameSingular()][$key] = $value instanceof \DateTime ? $value->format('c') : $value;
                }

            }
        }
    }

    private function getLast($value)
    {
        if (is_array($value)) {
            return reset($value);
        }
        
        if (method_exists($value, 'first')) {
            return $value->first();
        }

        throw new InvalidEmberDataSerializerInputException("Was not able to get item from array.");
    }

    /**
     * @param $value
     *
     * @return bool
     */
    private function isArrayCollection($value)
    {
        if (is_array($value) || $value instanceof \ArrayAccess) {
            return true;
        }

        return false;
    }

    /**
     * @param EmberDataSerializableInterface $object
     *
     * @return null|EmberDataSerializerAdapterInterface
     * @throws InvalidEmberDataSerializerAdapterServiceNameException
     */
    private function getSerializerAdapterOrNullBySerializableObject(EmberDataSerializableInterface $object)
    {
        $class = $this->getClass($object);

        if (!isset($this->adapters[$class])) {

            $adapter = $this->container->get($object->getEmberDataSerializerAdapterServiceName());

            if (!$adapter instanceof EmberDataSerializerAdapterInterface) {
                throw new InvalidEmberDataSerializerAdapterServiceNameException('Adapter is not an instance of EmberDataSerializerAdapterInterface.');
            }

            $this->adapters[$class] = $adapter;
        }

        if ($this->adapters[$class]->hasAccess($object)) {
            return $this->adapters[$class];
        }

        return null;
    }

    /**
     * @param EmberDataSerializableInterface $object
     *
     * @return string
     */
    private function getClass(EmberDataSerializableInterface $object)
    {
        $class = get_class($object);

        if (substr($class, 0, 15) == 'Proxies\__CG__\\') {
            $class = substr($class, 15);
        }

        return $class;
    }

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function reset()
    {
        $this->data = array();
    }
}
