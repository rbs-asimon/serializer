<?php

/*
 * Copyright 2016 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\Serializer;

use JMS\Serializer\Construction\ObjectConstructorInterface;
use JMS\Serializer\ContextFactory\DefaultDeserializationContextFactory;
use JMS\Serializer\ContextFactory\DefaultSerializationContextFactory;
use JMS\Serializer\ContextFactory\DeserializationContextFactoryInterface;
use JMS\Serializer\ContextFactory\SerializationContextFactoryInterface;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\EventDispatcherInterface;
use JMS\Serializer\Exception\InvalidArgumentException;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\Exception\UnsupportedFormatException;
use JMS\Serializer\Expression\ExpressionEvaluatorInterface;
use JMS\Serializer\Handler\HandlerRegistryInterface;
use Metadata\MetadataFactoryInterface;

/**
 * Serializer Implementation.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
final class Serializer implements SerializerInterface, ArrayTransformerInterface
{
    private $factory;
    private $handlerRegistry;
    private $objectConstructor;
    private $dispatcher;
    private $typeParser;

    private $serializationVisitors = array();

    private $deserializationVisitors = array();

    private $serializationNavigator;
    private $deserializationNavigator;

    /**
     * @var SerializationContextFactoryInterface
     */
    private $serializationContextFactory;

    /**
     * @var DeserializationContextFactoryInterface
     */
    private $deserializationContextFactory;

    /**
     * @param \Metadata\MetadataFactoryInterface $factory
     * @param Handler\HandlerRegistryInterface $handlerRegistry
     * @param Construction\ObjectConstructorInterface $objectConstructor
     * @param SerializationVisitorInterface[] $serializationVisitors
     * @param DeserializationVisitorInterface[] $deserializationVisitors
     * @param EventDispatcherInterface|null $dispatcher
     * @param TypeParser|null $typeParser
     * @param ExpressionEvaluatorInterface|null $expressionEvaluator
     */
    public function __construct(
        MetadataFactoryInterface $factory,
        HandlerRegistryInterface $handlerRegistry,
        ObjectConstructorInterface $objectConstructor,
        array $serializationVisitors,
        array $deserializationVisitors,
        EventDispatcherInterface $dispatcher = null,
        TypeParser $typeParser = null,
        ExpressionEvaluatorInterface $expressionEvaluator = null,
        SerializationContextFactoryInterface $serializationContextFactory = null,
        DeserializationContextFactoryInterface $deserializationContextFactory = null

    )
    {
        $this->factory = $factory;
        $this->handlerRegistry = $handlerRegistry;
        $this->objectConstructor = $objectConstructor;
        $this->dispatcher = $dispatcher ?: new EventDispatcher();
        $this->typeParser = $typeParser ?: new TypeParser();
        $this->serializationVisitors = $serializationVisitors;
        $this->deserializationVisitors = $deserializationVisitors;

        $this->serializationNavigator = new SerializationGraphNavigator($this->factory, $this->handlerRegistry, $this->dispatcher, $expressionEvaluator);
        $this->deserializationNavigator = new DeserializationGraphNavigator($this->factory, $this->handlerRegistry, $this->objectConstructor, $this->dispatcher, $expressionEvaluator);

        $this->serializationContextFactory = $serializationContextFactory ?: new DefaultSerializationContextFactory();
        $this->deserializationContextFactory = $deserializationContextFactory ?: new DefaultDeserializationContextFactory();
    }

    /**
     * Parses a direction string to one of the direction constants.
     *
     * @param string $dirStr
     *
     * @return integer
     */
    public static function parseDirection($dirStr)
    {
        switch (strtolower($dirStr)) {
            case 'serialization':
                return GraphNavigatorInterface::DIRECTION_SERIALIZATION;

            case 'deserialization':
                return GraphNavigatorInterface::DIRECTION_DESERIALIZATION;

            default:
                throw new InvalidArgumentException(sprintf('The direction "%s" does not exist.', $dirStr));
        }
    }

    private function findInitialType($type, SerializationContext $context)
    {
        if ($type !== null) {
            return $this->typeParser->parse($type);
        } elseif ($context->hasAttribute('initial_type')) {
            return $this->typeParser->parse($context->getAttribute('initial_type'));
        }
        return null;
    }

    public function serialize($data, $format, SerializationContext $context = null, $type = null)
    {
        if (null === $context) {
            $context = $this->serializationContextFactory->createSerializationContext();
        }

        if (!isset($this->serializationVisitors[$format])) {
            throw new UnsupportedFormatException(sprintf('The format "%s" is not supported for serialization.', $format));
        }

        $type = $this->findInitialType($type, $context);

        $visitor = $this->serializationVisitors[$format];

        $preparedData = $visitor->prepare($data);
        $result = $this->visit($this->serializationNavigator, $visitor, $context, $preparedData, $format, $type);
        return $visitor->getResult($result);
    }

    public function deserialize($data, $type, $format, DeserializationContext $context = null)
    {
        if (null === $context) {
            $context = $this->deserializationContextFactory->createDeserializationContext();
        }

        if (!isset($this->deserializationVisitors[$format])) {
            throw new UnsupportedFormatException(sprintf('The format "%s" is not supported for deserialization.', $format));
        }

        $visitor = $this->deserializationVisitors[$format];
        $preparedData = $visitor->prepare($data);
        $result = $this->visit($this->deserializationNavigator, $visitor, $context, $preparedData, $format, $this->typeParser->parse($type));

        return $visitor->getResult($result);
    }

    /**
     * {@InheritDoc}
     */
    public function toArray($data, SerializationContext $context = null, $type = null)
    {
        if (null === $context) {
            $context = $this->serializationContextFactory->createSerializationContext();
        }

        if (!isset($this->serializationVisitors['json'])) {
            throw new UnsupportedFormatException(sprintf('The format "%s" is not supported for fromArray.', 'json'));
        }

        $visitor = $this->serializationVisitors['json'];

        $type = $this->findInitialType($type, $context);

        $preparedData = $visitor->prepare($data);
        $result = $this->visit($this->serializationNavigator, $visitor, $context, $preparedData, 'json', $type);
        $result = $this->convertArrayObjects($result);

        if (!\is_array($result)) {
            throw new RuntimeException(sprintf(
                'The input data of type "%s" did not convert to an array, but got a result of type "%s".',
                \is_object($data) ? \get_class($data) : \gettype($data),
                \is_object($result) ? \get_class($result) : \gettype($result)
            ));
        }

        return $result;
    }

    /**
     * {@InheritDoc}
     */
    public function fromArray(array $data, $type, DeserializationContext $context = null)
    {
        if (null === $context) {
            $context = $this->deserializationContextFactory->createDeserializationContext();
        }

        if (!isset($this->deserializationVisitors['json'])) {
            throw new UnsupportedFormatException(sprintf('The format "%s" is not supported for fromArray.', 'json'));
        }
        $visitor = $this->deserializationVisitors['json'];

        return $this->visit($this->deserializationNavigator, $visitor, $context, $data, 'json', $this->typeParser->parse($type));
    }

    private function visit(GraphNavigatorInterface $navigator, $visitor, Context $context, $data, $format, array $type = null)
    {
        $context->initialize(
            $format,
            $visitor,
            $navigator,
            $this->factory
        );

        $visitor->setNavigator($navigator);

        return $navigator->accept($data, $type, $context);
    }

    private function convertArrayObjects($data)
    {
        if ($data instanceof \ArrayObject || $data instanceof \stdClass) {
            $data = (array)$data;
        }
        if (\is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->convertArrayObjects($v);
            }
        }

        return $data;
    }

    /**
     * @return MetadataFactoryInterface
     */
    public function getMetadataFactory()
    {
        return $this->factory;
    }
}
