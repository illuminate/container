<?php

use Illuminate\Container\Container;

class ContainerTest extends PHPUnit_Framework_TestCase {

	public function testClosureResolution()
	{
		$container = new Container;
		$container->bind('name', function() { return 'Taylor'; });
		$this->assertEquals('Taylor', $container->make('name'));
	}


	public function testSharedClosureResolution()
	{
		$container = new Container;
		$class = new stdClass;
		$container->shared('class', function() use ($class) { return $class; });
		$this->assertTrue($class === $container->make('class'));
	}


	public function testAutoConcreteResolution()
	{
		$container = new Container;
		$this->assertTrue($container->make('ContainerConcreteStub') instanceof ContainerConcreteStub);
	}


	public function testSharedConcreteResolution()
	{
		$container = new Container;
		$container->shared('ContainerConcreteStub');
		$var1 = $container->make('ContainerConcreteStub');
		$var2 = $container->make('ContainerConcreteStub');
		$this->assertTrue($var1 === $var2);
	}


	public function testAbstractToConcreteResolution()
	{
		$container = new Container;
		$container->bind('IContainerContractStub', 'ContainerImplementationStub');
		$class = $container->make('ContainerDependentStub');
		$this->assertTrue($class->impl instanceof ContainerImplementationStub);
	}


	public function testNestedDependencyResolution()
	{
		$container = new Container;
		$container->bind('IContainerContractStub', 'ContainerImplementationStub');
		$class = $container->make('ContainerNestedDependentStub');
		$this->assertTrue($class->inner instanceof ContainerDependentStub);
		$this->assertTrue($class->inner->impl instanceof ContainerImplementationStub);
	}


	public function testContainerIsPassedToResolvers()
	{
		$container = new Container;
		$container->bind('something', function($c) { return $c; });
		$c = $container->make('something');
		$this->assertTrue($c === $container);
	}


	public function testExtendedResolution()
	{
		$container = new Container;
		$container->bind('something', function() { return 'Taylor'; });
		$container->extend('something', function($something) { return $something.' Otwell'; });
		$this->assertEquals('Taylor Otwell', $container->make('something'));
	}


	public function testArrayAccess()
	{
		$container = new Container;
		$container['something'] = function() { return 'foo'; };
		$this->assertTrue(isset($container['something']));
		$this->assertEquals('foo', $container['something']);
		unset($container['something']);
		$this->assertFalse(isset($container['something']));
	}


	public function testAliases()
	{
		$container = new Container;
		$container['foo'] = 'bar';
		$container->alias('foo', 'baz');
		$this->assertEquals('bar', $container->make('foo'));
		$this->assertEquals('bar', $container->make('baz'));
		$container->bind(array('bam' => 'boom'), function() { return 'pow'; });
		$this->assertEquals('pow', $container->make('bam'));
		$this->assertEquals('pow', $container->make('boom'));
	}

}

class ContainerConcreteStub {}

interface IContainerContractStub {}

class ContainerImplementationStub implements IContainerContractStub {}

class ContainerDependentStub {
	public $impl;
	public function __construct(IContainerContractStub $impl)
	{
		$this->impl = $impl;
	}
}

class ContainerNestedDependentStub {
	public $inner;
	public function __construct(ContainerDependentStub $inner)
	{
		$this->inner = $inner;
	}
}