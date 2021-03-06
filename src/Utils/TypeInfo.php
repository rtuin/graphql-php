<?php
namespace GraphQL\Utils;

use GraphQL\Language\AST\Field;
use GraphQL\Language\AST\ListType;
use GraphQL\Language\AST\Name;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NonNullType;
use GraphQL\Schema;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Utils;

class TypeInfo
{
    /**
     * @param Schema $schema
     * @param $inputTypeAst
     * @return ListOfType|NonNull|Name
     * @throws \Exception
     */
    public static function typeFromAST(Schema $schema, $inputTypeAst)
    {
        if ($inputTypeAst instanceof ListType) {
            $innerType = self::typeFromAST($schema, $inputTypeAst->type);
            return $innerType ? new ListOfType($innerType) : null;
        }
        if ($inputTypeAst instanceof NonNullType) {
            $innerType = self::typeFromAST($schema, $inputTypeAst->type);
            return $innerType ? new NonNull($innerType) : null;
        }

        Utils::invariant($inputTypeAst instanceof Name, 'Must be a type name');
        return $schema->getType($inputTypeAst->value);
    }

    /**
     * Not exactly the same as the executor's definition of getFieldDef, in this
     * statically evaluated environment we do not always have an Object type,
     * and need to handle Interface and Union types.
     *
     * @return FieldDefinition
     */
    static private function _getFieldDef(Schema $schema, Type $parentType, Field $fieldAST)
    {
        $name = $fieldAST->name->value;
        $schemaMeta = Introspection::schemaMetaFieldDef();
        if ($name === $schemaMeta->name && $schema->getQueryType() === $parentType) {
            return $schemaMeta;
        }

        $typeMeta = Introspection::typeMetaFieldDef();
        if ($name === $typeMeta->name && $schema->getQueryType() === $parentType) {
            return $typeMeta;
        }
        $typeNameMeta = Introspection::typeNameMetaFieldDef();
        if ($name === $typeNameMeta->name &&
            ($parentType instanceof ObjectType ||
            $parentType instanceof InterfaceType ||
            $parentType instanceof UnionType)
        ) {
            return $typeNameMeta;
        }
        if ($parentType instanceof ObjectType ||
            $parentType instanceof InterfaceType) {
            $fields = $parentType->getFields();
            return isset($fields[$name]) ? $fields[$name] : null;
        }
        return null;
    }


    /**
     * @var Schema
     */
    private $_schema;

    /**
     * @var \SplStack<OutputType>
     */
    private $_typeStack;

    /**
     * @var \SplStack<CompositeType>
     */
    private $_parentTypeStack;

    /**
     * @var \SplStack<InputType>
     */
    private $_inputTypeStack;

    /**
     * @var \SplStack<FieldDefinition>
     */
    private $_fieldDefStack;


    public function __construct(Schema $schema)
    {
        $this->_schema = $schema;
        $this->_typeStack = [];
        $this->_parentTypeStack = [];
        $this->_inputTypeStack = [];
        $this->_fieldDefStack = [];
    }

    /**
     * @return Type
     */
    function getType()
    {
        if (!empty($this->_typeStack)) {
            return $this->_typeStack[count($this->_typeStack) - 1];
        }
        return null;
    }

    /**
     * @return Type
     */
    function getParentType()
    {
        if (!empty($this->_parentTypeStack)) {
            return $this->_parentTypeStack[count($this->_parentTypeStack) - 1];
        }
        return null;
    }

    /**
     * @return InputType
     */
    function getInputType()
    {
        if (!empty($this->_inputTypeStack)) {
            return $this->_inputTypeStack[count($this->_inputTypeStack) - 1];
        }
        return null;
    }

    /**
     * @return FieldDefinition
     */
    function getFieldDef()
    {
        if (!empty($this->_fieldDefStack)) {
            return $this->_fieldDefStack[count($this->_fieldDefStack) - 1];
        }
        return null;
    }


    function enter(Node $node)
    {
        $schema = $this->_schema;

        switch ($node->kind) {
            case Node::SELECTION_SET:
                // var $compositeType: ?GraphQLCompositeType;
                $rawType = Type::getUnmodifiedType($this->getType());
                $compositeType = null;
                if (Type::isCompositeType($rawType)) {
                    // isCompositeType is a type refining predicate, so this is safe.
                    $compositeType = $rawType;
                }
                array_push($this->_parentTypeStack, $compositeType);
                break;

            case Node::FIELD:
                $parentType = $this->getParentType();
                $fieldDef = null;
                if ($parentType) {
                    $fieldDef = self::_getFieldDef($schema, $parentType, $node);
                }
                array_push($this->_fieldDefStack, $fieldDef);
                array_push($this->_typeStack, $fieldDef ? $fieldDef->getType() : null);
                break;

            case Node::OPERATION_DEFINITION:
                $type = null;
                if ($node->operation === 'query') {
                    $type = $schema->getQueryType();
                } else if ($node->operation === 'mutation') {
                    $type = $schema->getMutationType();
                }
                array_push($this->_typeStack, $type);
                break;

            case Node::INLINE_FRAGMENT:
            case Node::FRAGMENT_DEFINITION:
                $type = $schema->getType($node->typeCondition->value);
                array_push($this->_typeStack, $type);
                break;

            case Node::VARIABLE_DEFINITION:
                array_push($this->_inputTypeStack, self::typeFromAST($schema, $node->type));
                break;

            case Node::ARGUMENT:
                $field = $this->getFieldDef();
                $argType = null;
                if ($field) {
                    $argDef = Utils::find($field->args, function($arg) use ($node) {return $arg->name === $node->name->value;});
                    if ($argDef) {
                        $argType = $argDef->getType();
                    }
                }
                array_push($this->_inputTypeStack, $argType);
                break;

            case Node::DIRECTIVE:
                $directive = $schema->getDirective($node->name->value);
                array_push($this->_inputTypeStack, $directive ? $directive->type : null);
                break;

            case Node::ARR:
                $arrayType = Type::getNullableType($this->getInputType());
                array_push(
                    $this->_inputTypeStack,
                    $arrayType instanceof ListOfType ? $arrayType->getWrappedType() : null
                );
                break;

            case Node::OBJECT_FIELD:
                $objectType = Type::getUnmodifiedType($this->getInputType());
                $fieldType = null;
                if ($objectType instanceof InputObjectType) {
                    $tmp = $objectType->getFields();
                    $inputField = isset($tmp[$node->name->value]) ? $tmp[$node->name->value] : null;
                    $fieldType = $inputField ? $inputField->getType() : null;
                }
                array_push($this->_inputTypeStack, $fieldType);
            break;
        }
    }

    function leave(Node $node)
    {
        switch ($node->kind) {
            case Node::SELECTION_SET:
                array_pop($this->_parentTypeStack);
                break;
            case Node::FIELD:
                array_pop($this->_fieldDefStack);
                array_pop($this->_typeStack);
                break;
            case Node::OPERATION_DEFINITION:
            case Node::INLINE_FRAGMENT:
            case Node::FRAGMENT_DEFINITION:
                array_pop($this->_typeStack);
                break;
            case Node::VARIABLE_DEFINITION:
                array_pop($this->_inputTypeStack);
                break;
            case Node::ARGUMENT:
                array_pop($this->_inputTypeStack);
                break;
            case Node::DIRECTIVE:
            case Node::ARR:
            case Node::OBJECT_FIELD:
                array_pop($this->_inputTypeStack);
                break;
        }
    }
}
