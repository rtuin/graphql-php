<?php
namespace GraphQL\Type\Definition;


use GraphQL\Utils;

class InterfaceType extends Type implements AbstractType, OutputType, CompositeType
{
    /**
     * @var array<string,FieldDefinition>
     */
    private $_fields;

    public $description;

    /**
     * @var array<GraphQLObjectType>
     */
    private $_implementations = [];

    /**
     * @var {[typeName: string]: boolean}
     */
    private $_possibleTypeNames;

    /**
     * @var callback
     */
    private $_resolveType;

    /**
     * Update the interfaces to know about this implementation.
     * This is an rare and unfortunate use of mutation in the type definition
     * implementations, but avoids an expensive "getPossibleTypes"
     * implementation for Interface types.
     *
     * @param ObjectType $impl
     * @param array<InterfaceType> $interfaces
     */
    public static function addImplementationToInterfaces(ObjectType $impl, array $interfaces)
    {
        foreach ($interfaces as $interface) {
            $interface->_implementations[] = $impl;
        }
    }

    public function __construct(array $config)
    {
        Config::validate($config, [
            'name' => Config::STRING,
            'fields' => Config::arrayOf(
                FieldDefinition::getDefinition(),
                Config::KEY_AS_NAME
            ),
            'resolveType' => Config::CALLBACK,
            'description' => Config::STRING
        ]);

        $this->name = $config['name'];
        $this->description = isset($config['description']) ? $config['description'] : null;
        $this->_fields = !empty($config['fields']) ? FieldDefinition::createMap($config['fields']) : [];
        $this->_resolveType = isset($config['resolveType']) ? $config['resolveType'] : null;
    }

    /**
     * @return array<FieldDefinition>
     */
    public function getFields()
    {
        return $this->_fields;
    }

    public function getField($name)
    {
        Utils::invariant(isset($this->_fields[$name]), 'Field "%s" is not defined for type "%s"', $name, $this->name);
        return $this->_fields[$name];
    }

    /**
     * @return array<GraphQLObjectType>
     */
    public function getPossibleTypes()
    {
        return $this->_implementations;
    }

    public function isPossibleType(ObjectType $type)
    {
        $possibleTypeNames = $this->_possibleTypeNames;
        if (!$possibleTypeNames) {
            $this->_possibleTypeNames = $possibleTypeNames = array_reduce($this->getPossibleTypes(), function(&$map, Type $possibleType) {
                $map[$possibleType->name] = true;
                return $map;
            }, []);
        }
        return !empty($possibleTypeNames[$type->name]);
    }

    /**
     * @param $value
     * @return ObjectType|null
     */
    public function resolveType($value)
    {
        $resolver = $this->_resolveType;
        return $resolver ? call_user_func($resolver, $value) : Type::getTypeOf($value, $this);
    }
}
