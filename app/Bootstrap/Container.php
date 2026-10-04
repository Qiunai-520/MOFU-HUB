<?php

declare(strict_types=1);

namespace Mofu\Bootstrap;

use RuntimeException;

/**
 * 极简服务容器：注册惰性工厂，按需实例化并缓存单例。
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * 基于构造器类型声明的自动装配（结果缓存为单例）。
     * 无法解析的依赖（标量、未注册类型）会抛出异常。
     */
    public function autowire(string $class): mixed
    {
        if (array_key_exists($class, $this->instances)) {
            return $this->instances[$class];
        }

        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $this->instances[$class] = $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                throw new RuntimeException(sprintf(
                    'Cannot autowire parameter $%s of %s.',
                    $parameter->getName(),
                    $class,
                ));
            }

            $arguments[] = $this->get($type->getName());
        }

        return $this->instances[$class] = $reflection->newInstanceArgs($arguments);
    }
}
