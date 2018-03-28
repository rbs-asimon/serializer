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

namespace JMS\Serializer\Tests\Serializer;

use Doctrine\Common\Annotations\AnnotationReader;
use JMS\Serializer\Construction\UnserializeObjectConstructor;
use JMS\Serializer\DeserializationGraphNavigator;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\Exclusion\DisjunctExclusionStrategy;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\GraphNavigatorInterface;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\Metadata\Driver\AnnotationDriver;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializationGraphNavigator;
use JMS\Serializer\SerializationVisitorInterface;
use Metadata\MetadataFactory;

class GraphNavigatorTest extends \PHPUnit_Framework_TestCase
{
    private $metadataFactory;
    private $handlerRegistry;
    private $objectConstructor;
    private $dispatcher;
    private $serializationNavigator;
    private $deserializationNavigator;
    private $context;

    /**
     * @expectedException JMS\Serializer\Exception\RuntimeException
     * @expectedExceptionMessage Resources are not supported in serialized data.
     */
    public function testResourceThrowsException()
    {
        $this->context->expects($this->any())
            ->method('getDirection')
            ->will($this->returnValue(GraphNavigatorInterface::DIRECTION_SERIALIZATION));

        $this->serializationNavigator->accept(STDIN, null, $this->context);
    }

    public function testNavigatorPassesInstanceOnSerialization()
    {
        $object = new SerializableClass;
        $metadata = $this->metadataFactory->getMetadataForClass(get_class($object));

        $self = $this;
        $this->context = $this->getMockBuilder(SerializationContext::class)->getMock();
        $context = $this->context;
        $exclusionStrategy = $this->getMockBuilder('JMS\Serializer\Exclusion\ExclusionStrategyInterface')->getMock();
        $exclusionStrategy->expects($this->once())
            ->method('shouldSkipClass')
            ->will($this->returnCallback(function ($passedMetadata, $passedContext) use ($metadata, $context, $self) {
                $self->assertSame($metadata, $passedMetadata);
                $self->assertSame($context, $passedContext);
            }));
        $exclusionStrategy->expects($this->once())
            ->method('shouldSkipProperty')
            ->will($this->returnCallback(function ($propertyMetadata, $passedContext) use ($context, $metadata, $self) {
                $self->assertSame($metadata->propertyMetadata['foo'], $propertyMetadata);
                $self->assertSame($context, $passedContext);
            }));

        $this->context->expects($this->once())
            ->method('getExclusionStrategy')
            ->will($this->returnValue($exclusionStrategy));

        $this->context->expects($this->any())
            ->method('getDirection')
            ->will($this->returnValue(GraphNavigatorInterface::DIRECTION_SERIALIZATION));

        $this->context->expects($this->any())
            ->method('getVisitor')
            ->will($this->returnValue($this->getMockBuilder(SerializationVisitorInterface::class)->getMock()));

        $navigator = new SerializationGraphNavigator($this->metadataFactory, $this->handlerRegistry, $this->dispatcher);
        $navigator->accept($object, null, $this->context);
    }

    public function testNavigatorPassesNullOnDeserialization()
    {
        $class = __NAMESPACE__ . '\SerializableClass';
        $metadata = $this->metadataFactory->getMetadataForClass($class);

        $this->context = $this->getMockBuilder(SerializationContext::class)->getMock();

        $context = $this->context;
        $exclusionStrategy = $this->getMockBuilder('JMS\Serializer\Exclusion\ExclusionStrategyInterface')->getMock();
        $exclusionStrategy->expects($this->once())
            ->method('shouldSkipClass')
            ->with($metadata, $this->callback(function ($navigatorContext) use ($context) {
                return $navigatorContext === $context;
            }));

        $exclusionStrategy->expects($this->once())
            ->method('shouldSkipProperty')
            ->with($metadata->propertyMetadata['foo'], $this->callback(function ($navigatorContext) use ($context) {
                return $navigatorContext === $context;
            }));

        $this->context->expects($this->once())
            ->method('getExclusionStrategy')
            ->will($this->returnValue($exclusionStrategy));

        $this->context->expects($this->any())
            ->method('getDirection')
            ->will($this->returnValue(GraphNavigatorInterface::DIRECTION_DESERIALIZATION));

        $this->context->expects($this->any())
            ->method('getVisitor')
            ->will($this->returnValue($this->getMockBuilder(SerializationVisitorInterface::class)->getMock()));

        $navigator = new SerializationGraphNavigator($this->metadataFactory, $this->handlerRegistry, $this->dispatcher);
        $navigator->accept('random', array('name' => $class, 'params' => array()), $this->context);
    }

    public function testNavigatorChangeTypeOnSerialization()
    {
        $object = new SerializableClass;
        $typeName = 'JsonSerializable';

        $this->dispatcher->addListener('serializer.pre_serialize', function ($event) use ($typeName) {
            $type = $event->getType();
            $type['name'] = $typeName;
            $event->setType($type['name'], $type['params']);
        });

        $this->handlerRegistry->registerSubscribingHandler(new TestSubscribingHandler());

        $this->context->expects($this->any())
            ->method('getDirection')
            ->will($this->returnValue(GraphNavigatorInterface::DIRECTION_SERIALIZATION));

        $this->context->expects($this->any())
            ->method('getVisitor')
            ->will($this->returnValue($this->getMockBuilder(SerializationVisitorInterface::class)->getMock()));

        $navigator = new SerializationGraphNavigator($this->metadataFactory, $this->handlerRegistry, $this->dispatcher);
        $navigator->accept($object, null, $this->context);
    }

    protected function setUp()
    {
        $this->context = $this->getMockBuilder(SerializationContext::class)
            ->enableOriginalConstructor()
            ->setMethodsExcept(['getExclusionStrategy'])
            ->getMock();

        $this->dispatcher = new EventDispatcher();
        $this->handlerRegistry = new HandlerRegistry();
        $this->objectConstructor = new UnserializeObjectConstructor();

        $this->metadataFactory = new MetadataFactory(new AnnotationDriver(new AnnotationReader()));
        $this->serializationNavigator = new SerializationGraphNavigator($this->metadataFactory, $this->handlerRegistry, $this->dispatcher);
        $this->deserializationNavigator = new DeserializationGraphNavigator($this->metadataFactory, $this->handlerRegistry, $this->objectConstructor, $this->dispatcher);
    }
}

class SerializableClass
{
    public $foo = 'bar';
}

class TestSubscribingHandler implements SubscribingHandlerInterface
{
    public static function getSubscribingMethods()
    {
        return array(array(
            'type' => 'JsonSerializable',
            'format' => 'foo',
            'direction' => GraphNavigatorInterface::DIRECTION_SERIALIZATION,
            'method' => 'serialize'
        ));
    }
}
