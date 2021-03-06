<?php
namespace TYPO3\CMS\Extbase\Tests\Unit\Property\TypeConverter;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Extbase\Property\Exception\DuplicateObjectException;
use TYPO3\CMS\Extbase\Property\Exception\InvalidPropertyMappingConfigurationException;
use TYPO3\CMS\Extbase\Property\Exception\InvalidTargetException;
use TYPO3\CMS\Extbase\Property\Exception\TargetNotFoundException;
use TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter;
use TYPO3\CMS\Extbase\Reflection\ClassSchema;
use TYPO3\CMS\Extbase\Tests\Unit\Property\TypeConverter\Fixtures\PersistentObjectEntityFixture;
use TYPO3\CMS\Extbase\Tests\Unit\Property\TypeConverter\Fixtures\PersistentObjectFixture;
use TYPO3\CMS\Extbase\Tests\Unit\Property\TypeConverter\Fixtures\PersistentObjectValueObjectFixture;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case
 */
class PersistentObjectConverterTest extends UnitTestCase
{
    /**
     * @var \TYPO3\CMS\Extbase\Property\TypeConverterInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $converter;

    /**
     * @var \TYPO3\CMS\Extbase\Reflection\ReflectionService|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $mockReflectionService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $mockPersistenceManager;

    /**
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $mockObjectManager;

    /**
     * @var \TYPO3\CMS\Extbase\Object\Container\Container|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $mockContainer;

    /**
     * @throws \InvalidArgumentException
     * @throws \PHPUnit\Framework\Exception
     * @throws \RuntimeException
     */
    protected function setUp()
    {
        $this->converter = new PersistentObjectConverter();
        $this->mockReflectionService = $this->createMock(\TYPO3\CMS\Extbase\Reflection\ReflectionService::class);
        $this->inject($this->converter, 'reflectionService', $this->mockReflectionService);

        $this->mockPersistenceManager = $this->createMock(\TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface::class);
        $this->inject($this->converter, 'persistenceManager', $this->mockPersistenceManager);

        $this->mockObjectManager = $this->createMock(\TYPO3\CMS\Extbase\Object\ObjectManagerInterface::class);
        $this->mockObjectManager->expects($this->any())
            ->method('get')
            ->will($this->returnCallback(function ($className, ...$arguments) {
                $reflectionClass = new \ReflectionClass($className);
                if (empty($arguments)) {
                    return $reflectionClass->newInstance();
                }
                return $reflectionClass->newInstanceArgs($arguments);
            }));
        $this->inject($this->converter, 'objectManager', $this->mockObjectManager);

        $this->mockContainer = $this->createMock(\TYPO3\CMS\Extbase\Object\Container\Container::class);
        $this->inject($this->converter, 'objectContainer', $this->mockContainer);
    }

    /**
     * @test
     */
    public function checkMetadata()
    {
        $this->assertEquals(['integer', 'string', 'array'], $this->converter->getSupportedSourceTypes(), 'Source types do not match');
        $this->assertEquals('object', $this->converter->getSupportedTargetType(), 'Target type does not match');
        $this->assertEquals(20, $this->converter->getPriority(), 'Priority does not match');
    }

    /**
     * @return array
     */
    public function dataProviderForCanConvert()
    {
        return [
            [true, false, true],
            // is entity => can convert
            [false, true, true],
            // is valueobject => can convert
            [false, false, false],
            // is no entity and no value object => can not convert
        ];
    }

    /**
     * @test
     * @dataProvider dataProviderForCanConvert
     */
    public function canConvertFromReturnsTrueIfClassIsTaggedWithEntityOrValueObject($isEntity, $isValueObject, $expected)
    {
        $className = PersistentObjectFixture::class;

        if ($isEntity) {
            $className = PersistentObjectEntityFixture::class;
        } elseif ($isValueObject) {
            $className = PersistentObjectValueObjectFixture::class;
        }
        $this->assertEquals($expected, $this->converter->canConvertFrom('myInputData', $className));
    }

    /**
     * @test
     */
    public function getSourceChildPropertiesToBeConvertedReturnsAllPropertiesExceptTheIdentityProperty()
    {
        $source = [
            'k1' => 'v1',
            '__identity' => 'someIdentity',
            'k2' => 'v2'
        ];
        $expected = [
            'k1' => 'v1',
            'k2' => 'v2'
        ];
        $this->assertEquals($expected, $this->converter->getSourceChildPropertiesToBeConverted($source));
    }

    /**
     * @test
     */
    public function getTypeOfChildPropertyShouldUseReflectionServiceToDetermineType()
    {
        $mockSchema = $this->getMockBuilder(\TYPO3\CMS\Extbase\Reflection\ClassSchema::class)->disableOriginalConstructor()->getMock();
        $this->mockReflectionService->expects($this->any())->method('getClassSchema')->with('TheTargetType')->will($this->returnValue($mockSchema));

        $this->mockContainer->expects($this->any())->method('getImplementationClassName')->will($this->returnValue('TheTargetType'));
        $mockSchema->expects($this->any())->method('hasProperty')->with('thePropertyName')->will($this->returnValue(true));
        $mockSchema->expects($this->any())->method('getProperty')->with('thePropertyName')->will($this->returnValue([
            'type' => 'TheTypeOfSubObject',
            'elementType' => null
        ]));
        $configuration = $this->buildConfiguration([]);
        $this->assertEquals('TheTypeOfSubObject', $this->converter->getTypeOfChildProperty('TheTargetType', 'thePropertyName', $configuration));
    }

    /**
     * @test
     */
    public function getTypeOfChildPropertyShouldUseConfiguredTypeIfItWasSet()
    {
        $this->mockReflectionService->expects($this->never())->method('getClassSchema');
        $this->mockContainer->expects($this->any())->method('getImplementationClassName')->will($this->returnValue('foo'));

        $configuration = $this->buildConfiguration([]);
        $configuration->forProperty('thePropertyName')->setTypeConverterOption(\TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_TARGET_TYPE, 'Foo\Bar');
        $this->assertEquals('Foo\Bar', $this->converter->getTypeOfChildProperty('foo', 'thePropertyName', $configuration));
    }

    /**
     * @test
     */
    public function convertFromShouldFetchObjectFromPersistenceIfUuidStringIsGiven()
    {
        $identifier = '17';
        $object = new \stdClass();

        $this->mockPersistenceManager->expects($this->any())->method('getObjectByIdentifier')->with($identifier)->will($this->returnValue($object));
        $this->assertSame($object, $this->converter->convertFrom($identifier, 'MySpecialType'));
    }

    /**
     * @test
     */
    public function convertFromShouldFetchObjectFromPersistenceIfuidStringIsGiven()
    {
        $identifier = '17';
        $object = new \stdClass();

        $this->mockPersistenceManager->expects($this->any())->method('getObjectByIdentifier')->with($identifier)->will($this->returnValue($object));
        $this->assertSame($object, $this->converter->convertFrom($identifier, 'MySpecialType'));
    }

    /**
     * @test
     */
    public function convertFromShouldFetchObjectFromPersistenceIfOnlyIdentityArrayGiven()
    {
        $identifier = '12345';
        $object = new \stdClass();

        $source = [
            '__identity' => $identifier
        ];
        $this->mockPersistenceManager->expects($this->any())->method('getObjectByIdentifier')->with($identifier)->will($this->returnValue($object));
        $this->assertSame($object, $this->converter->convertFrom($source, 'MySpecialType'));
    }

    /**
     * @test
     */
    public function convertFromShouldThrowExceptionIfObjectNeedsToBeModifiedButConfigurationIsNotSet()
    {
        $this->expectException(InvalidPropertyMappingConfigurationException::class);
        $this->expectExceptionCode(1297932028);
        $identifier = '12345';
        $object = new \stdClass();
        $object->someProperty = 'asdf';

        $source = [
            '__identity' => $identifier,
            'foo' => 'bar'
        ];
        $this->mockPersistenceManager->expects($this->any())->method('getObjectByIdentifier')->with($identifier)->will($this->returnValue($object));
        $this->converter->convertFrom($source, 'MySpecialType');
    }

    /**
     * @param array $typeConverterOptions
     * @return \TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration
     */
    protected function buildConfiguration($typeConverterOptions)
    {
        $configuration = new \TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration();
        $configuration->setTypeConverterOptions(\TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter::class, $typeConverterOptions);
        return $configuration;
    }

    /**
     * @param int $numberOfResults
     * @param Matcher $howOftenIsGetFirstCalled
     * @return \stdClass
     */
    public function setupMockQuery($numberOfResults, $howOftenIsGetFirstCalled)
    {
        $mockClassSchema = $this->getMockBuilder(\TYPO3\CMS\Extbase\Reflection\ClassSchema::class)
            ->setConstructorArgs([\TYPO3\CMS\Extbase\Tests\Unit\Property\TypeConverter\Fixtures\Query::class])
            ->getMock();
        $this->mockReflectionService->expects($this->any())->method('getClassSchema')->with('SomeType')->will($this->returnValue($mockClassSchema));

        $mockConstraint = $this->getMockBuilder(\TYPO3\CMS\Extbase\Persistence\Generic\Qom\Comparison::class)->disableOriginalConstructor()->getMock();

        $mockObject = new \stdClass();
        $mockQuery = $this->createMock(\TYPO3\CMS\Extbase\Persistence\QueryInterface::class);
        $mockQueryResult = $this->createMock(\TYPO3\CMS\Extbase\Persistence\QueryResultInterface::class);
        $mockQueryResult->expects($this->any())->method('count')->will($this->returnValue($numberOfResults));
        $mockQueryResult->expects($howOftenIsGetFirstCalled)->method('getFirst')->will($this->returnValue($mockObject));
        $mockQuery->expects($this->any())->method('equals')->with('key1', 'value1')->will($this->returnValue($mockConstraint));
        $mockQuery->expects($this->any())->method('matching')->with($mockConstraint)->will($this->returnValue($mockQuery));
        $mockQuery->expects($this->any())->method('execute')->will($this->returnValue($mockQueryResult));

        $this->mockPersistenceManager->expects($this->any())->method('createQueryForType')->with('SomeType')->will($this->returnValue($mockQuery));

        return $mockObject;
    }

    /**
     * @test
     */
    public function convertFromShouldReturnExceptionIfNoMatchingObjectWasFound()
    {
        $this->expectException(TargetNotFoundException::class);
        $this->expectExceptionCode(1297933823);
        $this->setupMockQuery(0, $this->never());
        $this->mockReflectionService->expects($this->never())->method('getClassSchema');

        $source = [
            '__identity' => 123
        ];
        $actual = $this->converter->convertFrom($source, 'SomeType');
        $this->assertNull($actual);
    }

    /**
     * @test
     */
    public function convertFromShouldThrowExceptionIfMoreThanOneObjectWasFound()
    {
        $this->expectException(DuplicateObjectException::class);
        // @TODO expectExceptionCode is 0
        $this->setupMockQuery(2, $this->never());

        $source = [
            '__identity' => 666
        ];
        $this->mockPersistenceManager
            ->expects($this->any())
            ->method('getObjectByIdentifier')
            ->with(666)
            ->will($this->throwException(new DuplicateObjectException('testing', 1476107580)));
        $this->converter->convertFrom($source, 'SomeType');
    }

    /**
     * @test
     */
    public function convertFromShouldThrowExceptionIfObjectNeedsToBeCreatedButConfigurationIsNotSet()
    {
        $this->expectException(InvalidPropertyMappingConfigurationException::class);
        // @TODO expectExceptionCode is 0
        $source = [
            'foo' => 'bar'
        ];
        $this->converter->convertFrom($source, 'MySpecialType');
    }

    /**
     * @test
     */
    public function convertFromShouldCreateObject()
    {
        $source = [
            'propertyX' => 'bar'
        ];
        $convertedChildProperties = [
            'property1' => 'bar'
        ];
        $expectedObject = new \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSetters();
        $expectedObject->property1 = 'bar';

        $configuration = $this->buildConfiguration([PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED => true]);
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSetters::class, $convertedChildProperties, $configuration);
        $this->assertEquals($expectedObject, $result);
    }

    /**
     * @test
     */
    public function convertFromShouldThrowExceptionIfPropertyOnTargetObjectCouldNotBeSet()
    {
        $this->expectException(InvalidTargetException::class);
        $this->expectExceptionCode(1297935345);
        $source = [
            'propertyX' => 'bar'
        ];
        $object = new \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSetters();
        $convertedChildProperties = [
            'propertyNotExisting' => 'bar'
        ];
        $this->mockObjectManager->expects($this->any())->method('get')->with(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSetters::class)->will($this->returnValue($object));
        $configuration = $this->buildConfiguration([PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED => true]);
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSetters::class, $convertedChildProperties, $configuration);
        $this->assertSame($object, $result);
    }

    /**
     * @test
     */
    public function convertFromShouldCreateObjectWhenThereAreConstructorParameters(): void
    {
        $classSchemaMock = $this->createMock(ClassSchema::class);
        $classSchemaMock
            ->expects($this->any())
            ->method('getMethod')
            ->with('__construct')
            ->willReturn([
            'params' => [
                'property1' => ['optional' => false]
            ]
        ]);

        $classSchemaMock
            ->expects($this->any())
            ->method('hasConstructor')
            ->willReturn(true);

        $this->mockReflectionService
            ->expects($this->any())
            ->method('getClassSchema')
            ->with(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class)
            ->willReturn($classSchemaMock);

        $source = [
            'propertyX' => 'bar'
        ];
        $convertedChildProperties = [
            'property1' => 'param1',
            'property2' => 'bar'
        ];
        $expectedObject = new \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor('param1');
        $expectedObject->setProperty2('bar');

        $this->mockContainer->expects($this->any())->method('getImplementationClassName')->will($this->returnValue(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class));
        $configuration = $this->buildConfiguration([PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED => true]);
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class, $convertedChildProperties, $configuration);
        $this->assertEquals($expectedObject, $result);
        $this->assertEquals('bar', $expectedObject->getProperty2());
    }

    /**
     * @test
     */
    public function convertFromShouldCreateObjectWhenThereAreOptionalConstructorParameters()
    {
        $classSchemaMock = $this->createMock(ClassSchema::class);
        $classSchemaMock
            ->expects($this->any())
            ->method('getMethod')
            ->with('__construct')
            ->willReturn([
                'params' => [
                    'property1' => ['optional' => true, 'defaultValue' => 'thisIsTheDefaultValue']
                ]
            ]);

        $classSchemaMock
            ->expects($this->any())
            ->method('hasConstructor')
            ->willReturn(true);

        $this->mockReflectionService
            ->expects($this->any())
            ->method('getClassSchema')
            ->with(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class)
            ->willReturn($classSchemaMock);

        $source = [
            'propertyX' => 'bar'
        ];
        $expectedObject = new \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor('thisIsTheDefaultValue');

        $this->mockContainer->expects($this->any())->method('getImplementationClassName')->will($this->returnValue(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class));
        $configuration = $this->buildConfiguration([PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED => true]);
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class, [], $configuration);
        $this->assertEquals($expectedObject, $result);
    }

    /**
     * @test
     */
    public function convertFromShouldThrowExceptionIfRequiredConstructorParameterWasNotFound(): void
    {
        $classSchemaMock = $this->createMock(ClassSchema::class);
        $classSchemaMock
            ->expects($this->any())
            ->method('getMethod')
            ->with('__construct')
            ->willReturn([
                'params' => [
                    'property1' => ['optional' => false]
                ]
            ]);

        $classSchemaMock
            ->expects($this->any())
            ->method('hasConstructor')
            ->willReturn(true);

        $this->mockReflectionService
            ->expects($this->any())
            ->method('getClassSchema')
            ->with(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class)
            ->willReturn($classSchemaMock);

        $this->expectException(InvalidTargetException::class);
        $this->expectExceptionCode(1268734872);
        $source = [
            'propertyX' => 'bar'
        ];
        $object = new \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor('param1');
        $convertedChildProperties = [
            'property2' => 'bar'
        ];

        $this->mockContainer->expects($this->any())->method('getImplementationClassName')->will($this->returnValue(\TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class));
        $configuration = $this->buildConfiguration([PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED => true]);
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class, $convertedChildProperties, $configuration);
        $this->assertSame($object, $result);
    }

    /**
     * @test
     */
    public function convertFromShouldReturnNullForEmptyString()
    {
        $source = '';
        $result = $this->converter->convertFrom($source, \TYPO3\CMS\Extbase\Tests\Fixture\ClassWithSettersAndConstructor::class);
        $this->assertNull($result);
    }
}
