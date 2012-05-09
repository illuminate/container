<?php namespace Illuminate\Container; use Closure;

class BindingResolutionException extends \Exception {}

class Container {

	/**
	 * The container's bindings.
	 *
	 * @var array
	 */
	protected $bindings = array();

	/**
	 * The container's shared instances.
	 *
	 * @var array
	 */
	protected $instances = array();

	/**
	 * Create a new container instance.
	 *
	 * @param  array  $bindings
	 */
	public function __construct($bindings = array())
	{
		$this->bindings = array();
	}

	/**
	 * Register a binding with the container.
	 *
	 * @param  string               $abstract
	 * @param  Closure|string|null  $concrete
	 * @param  bool                 $shared
	 * @return void
	 */
	public function bind($abstract, $concrete = null, $shared = false)
	{
		if (is_null($concrete)) $concrete = $abstract;

		$this->bindings[$abstract] = compact('concrete', 'shared');
	}

	/**
	 * Register a shared binding in the container.
	 *
	 * @param  string               $abstract
	 * @param  Closure|string|null  $concrete
	 * @return void
	 */
	public function shared($abstract, $concrete = null)
	{
		return $this->bind($abstract, $concrete, true);
	}

	/**
	 * Register an existing instance as shared in the container.
	 *
	 * @param  string  $abstract
	 * @param  mixed   $instance
	 * @return void
	 */
	public function instance($abstract, $instance)
	{
		$this->instances[$abstract] = $instance;
	}

	/**
	 * Resolve the given type from the container.
	 *
	 * @param  string  $abstract
	 * @return mixed
	 */
	public function make($abstract)
	{
		// If an instance of the type is currently being managed as a singleton, we will
		// just return the existing instance instead of instantiating a fresh instance
		// so the developer can keep re-using the exact same object instance from us.
		if (isset($this->instances[$abstract]))
		{
			return $this->instances[$abstract];
		}

		// If we don't have a registered resolver or concrete for the type, we'll just
		// assume the type is the concrete name and will attempt to resolve it as is
		// since the container should be able to resolve concretes automatically.
		if ( ! isset($this->bindings[$abstract]))
		{
			$concrete = $abstract;
		}
		else
		{
			$concrete = $this->bindings[$abstract]['concrete'];
		}

		// We're ready to instantiate an instance of the concrete type registered for
		// the binding. This will instantiate the type, as well as resolve any of
		// its nested dependencies recursively until they are each resolved.
		if ($concrete === $abstract or $concrete instanceof Closure)
		{
			$object = $this->build($concrete);
		}
		else
		{
			$object = $this->make($concrete);
		}

		// If the requested type is registered as a singleton, we want to cache off
		// the instance in memory so we can return it later without creating an
		// entirely new instances of the object on each subsequent request.
		if (isset($this->bindings[$abstract]['shared']))
		{
			$this->instances[$abstract] = $object;
		}

		return $object;
	}

	/**
	 * Instantiate an instance of the given type.
	 *
	 * @param  string  $concrete
	 * @return mixed
	 */
	protected function build($concrete)
	{
		// If the concrete type is actually a Closure, we will just execute it and
		// hand back the results of the function, which allows functions to be
		// used as resolvers for more fine-tuned resolution of the objects.
		if ($concrete instanceof Closure)
		{
			return $concrete();
		}

		$reflector = new \ReflectionClass($concrete);

		// If the type is not instantiable, the developer is attempting to resolve
		// an abstract type such as an Interface of Abstract Class and there is
		// no binding registered for the abstraction so we need to bail out.
		if ( ! $reflector->isInstantiable())
		{
			$message = "Target [$concrete] is not instantiable.";

			throw new BindingResolutionException($message);
		}

		$constructor = $reflector->getConstructor();

		// If there is no constructor, that means there are no dependencies and
		// we can just resolve an instance of the object right away without
		// resolving any other types or dependencies from the container.
		if (is_null($constructor))
		{
			return new $concrete;
		}

		$dependencies = $this->getDependencies($constructor->getParameters());

		return $reflector->newInstanceArgs($dependencies);
	}

	/**
	 * Resolve all of the dependencies from the ReflectionParameters.
	 *
	 * @param  array  $parameterrs
	 * @return array
	 */
	protected function getDependencies($parameters)
	{
		$dependencies = array();

		foreach ($parameters as $parameter)
		{
			$dependency = $parameter->getClass();

			// If the class is null, it means the dependency is a string or some other
			// primitive type which we can not esolve since it is not a class and
			// we'll just bomb out with an error since we have nowhere to go.
			if (is_null($dependency))
			{
				$message = "Unresolvable dependency resolving [$parameter].";

				throw new BindingResolutionException($message);
			}

			$dependencies[] = $this->make($dependency->name);
		}

		return (array) $dependencies;
	}

	/**
	 * Get the container's bindings.
	 *
	 * @return array
	 */
	public function getBindings()
	{
		return $this->bindings;
	}

}