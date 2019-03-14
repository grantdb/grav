<?php

/**
 * @package    Grav\Framework\Flex
 *
 * @copyright  Copyright (C) 2015 - 2019 Trilby Media, LLC. All rights reserved.
 * @license    MIT License; see LICENSE file for details.
 */

namespace Grav\Framework\Flex;

use Doctrine\Common\Collections\Criteria;
use Grav\Common\Debugger;
use Grav\Common\Grav;
use Grav\Common\Twig\Twig;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Cache\CacheInterface;
use Grav\Framework\ContentBlock\ContentBlockInterface;
use Grav\Framework\ContentBlock\HtmlBlock;
use Grav\Framework\Flex\Interfaces\FlexIndexInterface;
use Grav\Framework\Flex\Interfaces\FlexObjectInterface;
use Grav\Framework\Object\ObjectCollection;
use Grav\Framework\Flex\Interfaces\FlexCollectionInterface;
use Psr\SimpleCache\InvalidArgumentException;
use RocketTheme\Toolbox\Event\Event;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\TemplateWrapper;

/**
 * Class FlexCollection
 * @package Grav\Framework\Flex
 */
class FlexCollection extends ObjectCollection implements FlexCollectionInterface
{
    /** @var FlexDirectory */
    private $_flexDirectory;

    /** @var string */
    private $_keyField;

    /**
     * @return array
     */
    public static function getCachedMethods()
    {
        return [
            'getTypePrefix' => true,
            'getType' => true,
            'getFlexDirectory' => true,
            'getCacheKey' => true,
            'getCacheChecksum' => true,
            'getTimestamp' => true,
            'hasProperty' => true,
            'getProperty' => true,
            'hasNestedProperty' => true,
            'getNestedProperty' => true,
            'orderBy' => true,

            'render' => false,
            'isAuthorized' => 'session',
            'search' => true,
            'sort' => true,
        ];
    }

    /**
     * @param array $entries
     * @param FlexDirectory $directory
     * @param string $keyField
     * @return static
     */
    public static function createFromArray(array $entries, FlexDirectory $directory, string $keyField = null): FlexCollectionInterface
    {
        $instance = new static($entries, $directory);
        $instance->setKeyField($keyField);

        return $instance;
    }

    /**
     * @param array $elements
     * @param FlexDirectory|null $flexDirectory
     * @throws \InvalidArgumentException
     */
    public function __construct(array $elements = [], FlexDirectory $flexDirectory = null)
    {
        parent::__construct($elements);

        if ($flexDirectory) {
            $this->setFlexDirectory($flexDirectory)->setKey($flexDirectory->getType());
        }
    }

    /**
     * Creates a new instance from the specified elements.
     *
     * This method is provided for derived classes to specify how a new
     * instance should be created when constructor semantics have changed.
     *
     * @param array $elements Elements.
     * @param string|null $keyField
     *
     * @return static
     * @throws \InvalidArgumentException
     */
    protected function createFrom(array $elements, $keyField = null)
    {
        $collection = new static($elements, $this->_flexDirectory);
        $collection->setKeyField($keyField ?: $this->_keyField);

        return $collection;
    }

    /**
     * @return string
     */
    protected function getTypePrefix()
    {
        return 'c.';
    }

    /**
     * @param bool $prefix
     * @return string
     */
    public function getType($prefix = false)
    {
        $type = $prefix ? $this->getTypePrefix() : '';

        return $type . $this->_flexDirectory->getType();
    }


    /**
     * @param string $search
     * @param string|string[]|null $properties
     * @param array|null $options
     * @return FlexCollectionInterface
     */
    public function search(string $search, $properties = null, array $options = null) // : FlexCollectionInterface
    {
        $matching = $this->call('search', [$search, $properties, $options]);
        $matching = array_filter($matching);

        if ($matching) {
            uksort($matching, function ($a, $b) {
                return -($a <=> $b);
            });
        }

        return $this->select(array_keys($matching));
    }

    public function sort(array $order) // : FlexCollectionInterface
    {
        $criteria = Criteria::create()->orderBy($order);

        return $this->matching($criteria);
    }

    /**
     * Twig example: {% render collection layout 'edit' with {my_check: true} %}
     *
     * @param string $layout
     * @param array $context
     * @return ContentBlockInterface|HtmlBlock
     * @throws \Exception
     * @throws \Throwable
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function render($layout = null, array $context = [])
    {
        if (null === $layout) {
            $layout = 'default';
        }

        $grav = Grav::instance();

        /** @var Debugger $debugger */
        $debugger = $grav['debugger'];
        $debugger->startTimer('flex-collection-' . ($debugKey =  uniqid($this->getType(false), false)), 'Render Collection ' . $this->getType(false) . ' (' . $layout . ')');

        $cache = $key = null;
        foreach ($context as $value) {
            if (!\is_scalar($value)) {
                $key = false;
            }
        }

        if ($key !== false) {
            $key = md5($this->getCacheKey() . '.' . $layout . json_encode($context));
            $cache = $this->getCache('render');
        }

        try {
            $data = $cache ? $cache->get($key) : null;

            $block = $data ? HtmlBlock::fromArray($data) : null;
        } catch (InvalidArgumentException $e) {
            $debugger->addException($e);
            $block = null;
        } catch (\InvalidArgumentException $e) {
            $debugger->addException($e);
            $block = null;
        }

        $checksum = $this->getCacheChecksum();
        if ($block && $checksum !== $block->getChecksum()) {
            $block = null;
        }

        if (!$block) {
            $block = HtmlBlock::create($key);
            $block->setChecksum($checksum);
            if ($key === false) {
                $block->disableCache();
            }

            $grav->fireEvent('onFlexCollectionRender', new Event([
                'collection' => $this,
                'layout' => &$layout,
                'context' => &$context
            ]));

            $output = $this->getTemplate($layout)->render(
                ['grav' => $grav, 'block' => $block, 'collection' => $this, 'layout' => $layout] + $context
            );

            if ($debugger->enabled()) {
                $name = $this->getType(false);
                $output = "\n<!–– START {$name} collection ––>\n{$output}\n<!–– END {$name} collection ––>\n";
            }

            $block->setContent($output);

            try {
                $cache && $block->isCached() && $cache->set($key, $block->toArray());
            } catch (InvalidArgumentException $e) {
                $debugger->addException($e);
            }
        }

        $debugger->stopTimer('flex-collection-' . $debugKey);

        return $block;
    }

    /**
     * @param FlexDirectory $type
     * @return $this
     */
    public function setFlexDirectory(FlexDirectory $type)
    {
        $this->_flexDirectory = $type;

        return $this;
    }

    /**
     * @return FlexDirectory
     */
    public function getFlexDirectory() //: FlexDirectory
    {
        return $this->_flexDirectory;
    }

    /**
     * @return array
     */
    public function getMetaData(string $key) : array
    {
        $object = $this->get($key);

        return $object instanceof FlexObjectInterface ? $object->getMetaData() : [];
    }

    /**
     * @param string|null $namespace
     * @return CacheInterface
     */
    public function getCache(string $namespace = null): CacheInterface
    {
        return $this->_flexDirectory->getCache($namespace);
    }

    /**
     * @return string
     */
    public function getCacheKey()
    {
        return $this->getType(true) . '.' . sha1(json_encode($this->call('getKey')));
    }

    /**
     * @return string
     */
    public function getCacheChecksum()
    {
        return sha1(json_encode($this->getTimestamps()));
    }

    /**
     * @return int[]
     */
    public function getTimestamps()
    {
        /** @var int[] $timestamps */
        $timestamps = $this->call('getTimestamp');

        return $timestamps;
    }

    /**
     * @return string[]
     */
    public function getStorageKeys()
    {
        /** @var string[] $keys */
        $keys = $this->call('getStorageKey');

        return $keys;
    }

    /**
     * @return string[]
     */
    public function getFlexKeys()
    {
        /** @var string[] $keys */
        $keys = $this->call('getFlexKey');

        return $keys;
    }

    /**
     * @return FlexIndexInterface
     */
    public function getIndex(): FlexIndexInterface
    {
        return $this->getFlexDirectory()->getIndex($this->getKeys(), $this->getKeyField());
    }

    /**
     * @param string|null $keyField
     * @return FlexCollectionInterface
     */
    public function withKeyField(string $keyField = null): FlexCollectionInterface
    {
        $keyField = $keyField ?: 'key';
        if ($keyField === $this->getKeyField()) {
            return $this;
        }

        $entries = [];
        foreach ($this as $key => $object) {
            // TODO: remove hardcoded logic
            if ($keyField === 'storage_key') {
                $entries[$object->getStorageKey()] = $object;
            } elseif ($keyField === 'flex_key') {
                $entries[$object->getFlexKey()] = $object;
            } elseif ($keyField === 'key') {
                $entries[$object->getKey()] = $object;
            }
        }

        return $this->createFrom($entries, $keyField);
    }

    /**
     * @return string
     */
    public function getKeyField(): string
    {
        return $this->_keyField ?? 'storage_key';
    }

    /**
     * @param string $action
     * @param string|null $scope
     * @param UserInterface|null $user
     * @return static
     */
    public function isAuthorized(string $action, string $scope = null, UserInterface $user = null)
    {
        $list = $this->call('isAuthorized', [$action, $scope, $user]);
        $list = \array_filter($list);

        return $this->select(array_keys($list));
    }

    /**
     * @param string $value
     * @param string $field
     * @return FlexObject|null
     */
    public function find($value, $field = 'id')
    {
        if ($value) foreach ($this as $element) {
            if (mb_strtolower($element->getProperty($field)) === mb_strtolower($value)) {
                return $element;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        $elements = [];

        /**
         * @var string $key
         * @var array|FlexObject $object
         */
        foreach ($this->getElements() as $key => $object) {
            $elements[$key] = \is_array($object) ? $object : $object->jsonSerialize();
        }

        return $elements;
    }

    public function __debugInfo()
    {
        return [
            'type:private' => $this->getType(false),
            'key:private' => $this->getKey(),
            'objects_key:private' => $this->getKeyField(),
            'objects:private' => $this->getElements()
        ];
    }

    /**
     * @param string $layout
     * @return TemplateWrapper
     * @throws LoaderError
     * @throws SyntaxError
     */
    protected function getTemplate($layout) //: \Twig_Template
    {
        $grav = Grav::instance();

        /** @var Twig $twig */
        $twig = $grav['twig'];

        try {
            return $twig->twig()->resolveTemplate(["flex-objects/layouts/{$this->getType(false)}/collection/{$layout}.html.twig"]);
        } catch (LoaderError $e) {
            /** @var Debugger $debugger */
            $debugger = Grav::instance()['debugger'];
            $debugger->addException($e);

            return $twig->twig()->resolveTemplate(['flex-objects/layouts/404.html.twig']);
        }
    }

    /**
     * @param string $type
     * @return FlexDirectory
     */
    protected function getRelatedDirectory($type): ?FlexDirectory
    {
        /** @var Flex $flex */
        $flex = Grav::instance()['flex_objects'];

        return $flex->getDirectory($type);
    }

    protected function setKeyField($keyField = null)
    {
        $this->_keyField = $keyField ?? 'storage_key';
    }
}
