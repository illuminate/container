<?php namespace Illuminate; use Closure, ArrayAccess;

class BindingResolutionException extends \Exception {}

class Container implements ArrayAccess {

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
	 * The registered type aliases.
	 *
	 * @var array
	 */
	protected $aliases = array();

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
		// If the given type is actually an array, we'll assume an alias is being
		// defined and will grab the real abstract class name and register the
		// alias with the container so it can be used as a short-cut for it.
		if (is_array($abstract))
		{
			list($abstract, $alias) = $this->extractAlias($abstract);

			$this->alias($abstract, $alias);
		}

		// If no concrete type was given, we will simply set the concrete type to
		// the abstract. This allows concrete types to be registered as shared
		// without being made state their classes in both of the parameters.
		if (is_null($concrete))
		{
			$concrete = $abstract;
		}

		// If the factory is not a Closure it means it is just a class name that
		// is bound into the container to an abstract type and we'll just wrap
		// it up in a Closure to make things more convenient while extending.
		if ( ! $concrete instanceof Closure)
		{
			$concrete = function($c) use ($abstract, $concrete)
			{
				$method = ($abstract == $concrete) ? 'build' : 'make';

				return $c->$method($concrete);
			};
		}

		$this->bindings[$abstract] = compact('concrete', 'shared');
	}

	/**
	 * Register a shared binding in the container.
	 *
	 * @param  string               $abstract
	 * @param  Closure|string|null  $concrete
	 * @return void
	 */
	public function sharedBinding($abstract, $concrete = null)
	{
		return $this->bind($abstract, $concrete, true);
	}

	/**
	 * Wrap a Closure such that it is shared.
	 *
	 * @param  Closure  $closure
	 * @return Closure
	 */
	public function share(Closure $closure)
	{
		return function($container) use ($closure)
		{
			// We'll simply declare a static variable within the Closures and if
			// it has not been set we'll execute the given Closure to resolve
			// the value and return it back to the consumers of the method.
			static $object;

			if (is_null($object))
			{
				$object = $closure($container);
			}

			return $object;
		};
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
		if (is_array($abstract))
		{
			list($abstract, $alias) = $this->extractAlias($abstract);

			$this->alias($abstract, $alias);
		}

		$this->instances[$abstract] = $instance;
	}

	/**
	 * Alias a type to a shorter name.
	 *
	 * @param  string  $abstract
	 * @param  string  $alias
	 * @return void
	 */
	public function alias($abstract, $alias)
	{
		$this->aliases[$alias] = $abstract;
	}

	/**
	 * Extract the type and alias from a given definition.
	 *
	 * @param  array  $definition
	 * @return array
	 */
	protected function extractAlias(array $definition)
	{
		return array(key($definition), current($definition));
	}

	/**
	 * Extend an object definition.
	 *
	 * @param  string   $abstract
	 * @param  Closure  $callable
	 * @return Closure
	 */
	public function extend($abstract, Closure $callable)
	{
		// Only resolvers that have actually been registered may be extended so if
		// the developer attempts to extend a resolver that isn't explicitly in
		// the container we will bail on out of here and throw our exception.
		if ( ! array_key_exists($abstract, $this->bindings))
		{
			$message = "Type {$abstract} is not bound.";

			throw new \InvalidArgumentException($message);
		}

		// We'll grab the old resolver Closures and wrap it within the new one so
		// the new Closure will have the opportunity to modify the value given
		// back from the original resolver. This might make your head hurts.
		$old = $this->bindings[$abstract]['concrete'];

		return $this->newConcrete($abstract, function($c) use ($callable, $old)
		{
			return $callable($old($c), $c);
		});
	}

	/**
	 * Register a new, callable concrete for a given type.
	 *
	 * @param  string    $abstract
	 * @param  Closuore  $callable
	 * @return Closure
	 */
	protected function newConcrete($abstract, Closure $callable)
	{
		return $this->bindings[$abstract]['concrete'] = $callable;
	}

	/**
	 * Resolve the given type from the container.
	 *
	 * @param  string  $abstract
	 * @return mixed
	 */
	public function make($abstract)
	{
		if (isset($this->aliases[$abstract]))
		{
			$abstract = $this->aliases[$abstract];
		}

		// If an instance of the type is currently being managed as a singleton, we will
		// just return the existing instance instead of instantiating a new instance
		// so the developer can keep using the exact same object instance from us.
		if (isset($this->instances[$abstract]))
		{
			return $this->instances[$abstract];	
		}

		// If we don't have a registered resolver or concrete for the type, we'll just
		// assume the type is a concrete name and will attempt to resolve it as is
		// since a container should be able to resolve concretes automatically.
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
		// its nested dependencies recursively until all have gotten resolved.
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
		// entirely new instances of the object on each subsequent requests.
		if ($this->isShared($abstract))
		{
			$this->instances[$abstract] = $object;
		}

		return $object;
	}

	/**
	 * Instantiate a concrete instance of the given type.
	 *
	 * @param  string  $concrete
	 * @return mixed
	 */
	public function build($concrete)
	{
		// If the concrete type is actually a Closure, we will just execute it and
		// hand back the results of the functions, which allows functions to be
		// used as resolvers for more fine-tuned resolution of these objects.
		if ($concrete instanceof Closure)
		{
			return $concrete($this);
		}

		$reflector = new \ReflectionClass($concrete);

		// If the type is not instantiable, the developer is attempting to resolve
		// an abstract type such as an Interface of Abstract Class and there is
		// no binding registered for the abstractions so we need to bail out.
		if ( ! $reflector->isInstantiable())
		{
			$message = "Target [$concrete] is not instantiable.";

			throw new BindingResolutionException($message);
		}

		$constructor = $reflector->getConstructor();

		// If there is no constructor, that means there are no dependencies and
		// we can just resolve the instance of the object right away without
		// resolving any other types or dependencies out of the container.
		if (is_null($constructor))
		{
			return new $concrete;
		}

		$parameters = $constructor->getParameters();

		// Once we have the constructor's parameters, we can create each of the
		// dependency instances and then use the reflection instance to make
		// an instance of the class injecting the created dependencies in.
		$dependencies = $this->getDependencies($parameters);

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
			// we'll just bomb out with an error since we have no-where to go.
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
	 * Get the raw binding for a given type.
	 *
	 * @param  string  $abstract
	 * @return mixed
	 */
	public function raw($abstract)
	{
		if ( ! isset($this->bindings[$abstract]))
		{
			throw new \InvalidArgumentException("Type {$abstract} is not bound.");
		}

		return $this->bindings[$abstract];
	}

	/**
	 * Determine if a given type is shared.
	 *
	 * @param  string  $abstract
	 * @return bool
	 */
	protected function isShared($abstract)
	{
		$set = isset($this->bindings[$abstract]['shared']);

		return $set and $this->bindings[$abstract]['shared'] === true;
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

	/**
	 * Determine if a given offset exists.
	 *
	 * @param  string  $key
	 * @return bool
	 */
	public function offsetExists($key)
	{
		return isset($this->bindings[$key]);
	}

	/**
	 * Get the value at a given offset.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function offsetGet($key)
	{
		if ( ! array_key_exists($key, $this->bindings))
		{
			throw new \InvalidArgumentException("Type {$key} is not bound.");
		}

		return $this->make($key);
	}

	/**
	 * Set the value at a given offset.
	 *
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return void
	 */
	public function offsetSet($key, $value)
	{
		// If the value is not a Closure, we will make it one. This simply gives
		// more "drop-in" replacement functionality for the Pimple which this
		// container's simplest functions are base modeled and built after.
		if ( ! $value instanceof Closure)
		{
			$value = function() use ($value)
			{
				return $value;
			};
		}

		$this->bind($key, $value);
	}

	/**
	 * Unset the value at a given offset.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function offsetUnset($key)
	{
		unset($this->bindings[$key]);
	}

}