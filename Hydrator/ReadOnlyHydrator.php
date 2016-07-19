<?php

namespace steevanb\DoctrineReadOnlyHydrator\Hydrator;

use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use steevanb\DoctrineReadOnlyHydrator\Entity\ReadOnlyEntityInterface;
use steevanb\DoctrineReadOnlyHydrator\Exception\PrivateMethodShouldNotAccessPropertiesException;

class ReadOnlyHydrator extends SimpleObjectHydrator
{
    const HYDRATOR_NAME = 'readOnly';

    /** @var string[] */
    protected $proxyFilePathsCache = [];

    /** @var string[] */
    protected $proxyNamespacesCache = [];

    /** @var string[] */
    protected $proxyClassNamesCache = [];

    /**
     * @param ClassMetadata $classMetaData
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    protected function createEntity(ClassMetadata $classMetaData, array $data)
    {
        $className = $this->getEntityClassName($classMetaData, $data);
        $this->generateProxyFile($classMetaData, $data);

        require_once($this->getProxyFilePath($className));
        $proxyClassName = $this->getProxyNamespace($className) . '\\' . $this->getProxyClassName($className);
        $entity = new $proxyClassName(array_keys($data));

        return $entity;
    }

    /**
     * @param ClassMetadata $classMetaData
     * @param array $data
     * @return $this
     */
    protected function generateProxyFile(ClassMetadata $classMetaData, array $data)
    {
        $entityClassName = $this->getEntityClassName($classMetaData, $data);
        $proxyFilePath = $this->getProxyFilePath($entityClassName);
        if (file_exists($proxyFilePath) === false) {
            $proxyMethodsCode = implode("\n\n", $this->getPhpForProxyMethods($classMetaData, $entityClassName));
            $proxyNamespace = $this->getProxyNamespace($entityClassName);
            $proxyClassName = $this->getProxyClassName($entityClassName);
            $generator = static::class;
            $readOnlyInterface = ReadOnlyEntityInterface::class;

            $php = <<<PHP
<?php

namespace $proxyNamespace;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY $generator
 */
class $proxyClassName extends \\$entityClassName implements \\$readOnlyInterface
{
    protected \$loadedProperties;

    public function __construct(array \$loadedProperties)
    {
        \$this->loadedProperties = \$loadedProperties;
    }

$proxyMethodsCode

    protected function assertReadOnlyPropertiesAreLoaded(array \$properties)
    {
        foreach (\$properties as \$property) {
            if (in_array(\$property, \$this->loadedProperties) === false) {
                throw new \steevanb\DoctrineReadOnlyHydrator\Exception\PropertyNotLoadedException(\$this, \$property);
            }
        }
    }
}
PHP;
            file_put_contents($proxyFilePath, $php);
        }

        return $this;
    }

    /**
     * @param string $entityClassName
     * @return string
     */
    public function getProxyFilePath($entityClassName)
    {
        if (isset($this->proxyFilePathsCache[$entityClassName]) === false) {
            $fileName = str_replace('\\', '_', $entityClassName) . '.php';
            $this->proxyFilePathsCache[$entityClassName] = $this->getProxyDirectory() . DIRECTORY_SEPARATOR . $fileName;
        }

        return $this->proxyFilePathsCache[$entityClassName];
    }

    /**
     * @param string $entityClassName
     * @return string
     */
    protected function getProxyNamespace($entityClassName)
    {
        if (isset($this->proxyNamespacesCache[$entityClassName]) === false) {
            $this->proxyNamespacesCache[$entityClassName] =
                'ReadOnlyProxies\\' . substr($entityClassName, 0, strrpos($entityClassName, '\\'));
        }

        return $this->proxyNamespacesCache[$entityClassName];
    }

    /**
     * @param string $entityClassName
     * @return string
     */
    protected function getProxyClassName($entityClassName)
    {
        if (isset($this->proxyClassNamesCache[$entityClassName]) === false) {
            $this->proxyClassNamesCache[$entityClassName] =
                substr($entityClassName, strrpos($entityClassName, '\\') + 1);
        }

        return $this->proxyClassNamesCache[$entityClassName];
    }

    /**
     * As Doctrine\ORM\EntityManager::newHydrator() call new FooHydrator($this), we can't set parameters to Hydrator.
     * So, we will use proxyDirectory from Doctrine\Common\Proxy\AbstractProxyFactory.
     * It's directory used by Doctrine\ORM\Internal\Hydration\ObjectHydrator.
     *
     * @return string
     */
    protected function getProxyDirectory()
    {
        /** @var ProxyGenerator $proxyGenerator */
        $proxyGenerator = $this->getPrivatePropertyValue($this->_em->getProxyFactory(), 'proxyGenerator');

        $directory = $this->getPrivatePropertyValue($proxyGenerator, 'proxyDirectory');
        $readOnlyDirectory = $directory . DIRECTORY_SEPARATOR . 'ReadOnly';
        if (is_dir($readOnlyDirectory) === false) {
            mkdir($readOnlyDirectory);
        }

        return $readOnlyDirectory;
    }

    /**
     * @param \ReflectionMethod $reflectionMethod
     * @param array $properties
     * @return string|false
     */
    protected function getUsedProperties(\ReflectionMethod $reflectionMethod, $properties)
    {
        $classLines = file($reflectionMethod->getFileName());
        $methodLines = array_slice(
            $classLines,
            $reflectionMethod->getStartLine() - 1,
            $reflectionMethod->getEndLine() - $reflectionMethod->getStartLine() + 1
        );
        $code = '<?php' . "\n" . implode("\n", $methodLines) . "\n" . '?>';

        $return = [];
        $nextStringIsProperty = false;
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_VARIABLE && $token[1] === '$this') {
                    $nextStringIsProperty = true;
                } elseif ($nextStringIsProperty && $token[0] === T_STRING) {
                    $nextStringIsProperty = false;
                    if (in_array($token[1], $properties)) {
                        $return[$token[1]] = true;
                    }
                }
            }
        }

        return array_keys($return);
    }

    /**
     * @param ClassMetadata $classMetaData
     * @param string $entityClassName
     * @return array
     * @throws PrivateMethodShouldNotAccessPropertiesException
     */
    protected function getPhpForProxyMethods(ClassMetadata $classMetaData, $entityClassName)
    {
        $return = [];
        $reflectionClass = new \ReflectionClass($entityClassName);
        $properties = array_merge($classMetaData->getFieldNames(), array_keys($classMetaData->associationMappings));
        foreach ($reflectionClass->getMethods() as $method) {
            if ($method->name === '__construct') {
                continue;
            }

            $usedProperties = $this->getUsedProperties($method, $properties);
            if (count($usedProperties) > 0) {
                if ($method->isPrivate()) {
                    throw new PrivateMethodShouldNotAccessPropertiesException(
                        $entityClassName,
                        $method->name,
                        $usedProperties
                    );
                }

                $return[] = $this->getPhpForMethod($method, $usedProperties);
            }
        }

        return $return;
    }

    /**
     * @param \ReflectionMethod $reflectionMethod
     * @param array $properties
     * @return string
     */
    protected function getPhpForMethod(\ReflectionMethod $reflectionMethod, array $properties)
    {
        if ($reflectionMethod->isPublic()) {
            $signature = 'public';
        } else {
            $signature = 'protected';
        }
        $signature .= ' function ' . $reflectionMethod->name . '(';
        $parameters = [];
        foreach ($reflectionMethod->getParameters() as $parameter) {
            $parameters[] = $this->getPhpForParameter($parameter);
        }
        $signature .= implode(', ', $parameters) . ')';

        $method = $reflectionMethod->name;

        array_walk($properties, function(&$name) {
            $name = "'" . $name . "'";
        });
        $propertiesToAssert = implode(', ', $properties);

        $php = <<<PHP
    $signature
    {
        \$this->assertReadOnlyPropertiesAreLoaded(array($propertiesToAssert));

        return call_user_func_array(array('parent', '$method'), func_get_args());
    }
PHP;

        return $php;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @return string
     */
    protected function getPhpForParameter(\ReflectionParameter $parameter)
    {
        $php = null;
        if ($parameter->getClass() instanceof \ReflectionClass) {
            $php .= '\\' . $parameter->getClass()->name . ' ';
        } elseif ($parameter->isCallable()) {
            $php .= 'callable ';
        } elseif ($parameter->isArray()) {
            $php .= 'array ';
        }

        if ($parameter->isPassedByReference()) {
            $php .= '&';
        }
        $php .= '$' . $parameter->name;

        if ($parameter->isDefaultValueAvailable()) {
            $parameterDefaultValue = $parameter->getDefaultValue();
            if ($parameter->isDefaultValueConstant()) {
                $defaultValue = $parameter->getDefaultValueConstantName();
            } elseif ($parameterDefaultValue === null) {
                $defaultValue = 'null';
            } elseif (is_bool($parameterDefaultValue)) {
                $defaultValue = ($parameterDefaultValue === true) ? 'true' : 'false';
            } elseif (is_string($parameterDefaultValue)) {
                $defaultValue = '\'' . $parameterDefaultValue . '\'';
            } elseif (is_array($parameterDefaultValue)) {
                $defaultValue = 'array()';
            } else {
                $defaultValue = $parameterDefaultValue;
            }
            $php .= ' = ' . $defaultValue;
        }

        return $php;
    }
}
