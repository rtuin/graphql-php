<?php
namespace GraphQL\Language;


use GraphQL\Language\AST\Argument;
use GraphQL\Language\AST\ArrayValue;
use GraphQL\Language\AST\BooleanValue;
use GraphQL\Language\AST\Directive;
use GraphQL\Language\AST\Document;
use GraphQL\Language\AST\EnumValue;
use GraphQL\Language\AST\Field;
use GraphQL\Language\AST\FloatValue;
use GraphQL\Language\AST\FragmentDefinition;
use GraphQL\Language\AST\FragmentSpread;
use GraphQL\Language\AST\InlineFragment;
use GraphQL\Language\AST\IntValue;
use GraphQL\Language\AST\ListType;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NonNullType;
use GraphQL\Language\AST\ObjectField;
use GraphQL\Language\AST\ObjectValue;
use GraphQL\Language\AST\OperationDefinition;
use GraphQL\Language\AST\SelectionSet;
use GraphQL\Language\AST\StringValue;
use GraphQL\Language\AST\VariableDefinition;

class Printer
{
    public static function doPrint($ast)
    {
        return Visitor::visit($ast, array(
            'leave' => array(
                Node::NAME => function($node) {return $node->value . '';},
                Node::VARIABLE => function($node) {return '$' . $node->name;},
                Node::DOCUMENT => function(Document $node) {return self::join($node->definitions, "\n\n") . "\n";},
                Node::OPERATION_DEFINITION => function(OperationDefinition $node) {
                    $op = $node->operation;
                    $name = $node->name;
                    $defs = Printer::manyList('(', $node->variableDefinitions, ', ', ')');
                    $directives = self::join($node->directives, ' ');
                    $selectionSet = $node->selectionSet;
                    return !$name ? $selectionSet :
                        self::join([$op, self::join([$name, $defs]), $directives, $selectionSet], ' ');
                },
                Node::VARIABLE_DEFINITION => function(VariableDefinition $node) {
                    return self::join([$node->variable . ': ' . $node->type, $node->defaultValue], ' = ');
                },
                Node::SELECTION_SET => function(SelectionSet $node) {
                    return self::blockList($node->selections, ",\n");
                },
                Node::FIELD => function(Field $node) {
                    $r11 = self::join([
                        $node->alias,
                        $node->name
                    ], ': ');

                    $r1 = self::join([
                        $r11,
                        self::manyList('(', $node->arguments, ', ', ')')
                    ]);

                    $r2 = self::join($node->directives, ' ');

                    return self::join([
                        $r1,
                        $r2,
                        $node->selectionSet
                    ], ' ');
                },
                Node::ARGUMENT => function(Argument $node) {
                    return $node->name . ': ' . $node->value;
                },

                // Fragments
                Node::FRAGMENT_SPREAD => function(FragmentSpread $node) {
                    return self::join(['...' . $node->name, self::join($node->directives, '')], ' ');
                },
                Node::INLINE_FRAGMENT => function(InlineFragment $node) {
                    return self::join([
                        '... on',
                        $node->typeCondition,
                        self::join($node->directives, ' '),
                        $node->selectionSet
                    ], ' ');
                },
                Node::FRAGMENT_DEFINITION => function(FragmentDefinition $node) {
                    return self::join([
                        'fragment',
                        $node->name,
                        'on',
                        $node->typeCondition,
                        self::join($node->directives, ' '),
                        $node->selectionSet
                    ], ' ');
                },

                // Value
                Node::INT => function(IntValue  $node) {return $node->value;},
                Node::FLOAT => function(FloatValue $node) {return $node->value;},
                Node::STRING => function(StringValue $node) {return json_encode($node->value);},
                Node::BOOLEAN => function(BooleanValue $node) {return $node->value ? 'true' : 'false';},
                Node::ENUM => function(EnumValue $node) {return $node->value;},
                Node::ARR => function(ArrayValue $node) {return '[' . self::join($node->values, ', ') . ']';},
                Node::OBJECT => function(ObjectValue $node) {return '{' . self::join($node->fields, ', ') . '}';},
                Node::OBJECT_FIELD => function(ObjectField $node) {return $node->name . ': ' . $node->value;},

                // Directive
                Node::DIRECTIVE => function(Directive $node) {return self::join(['@' . $node->name, $node->value], ': ');},

                // Type
                Node::LIST_TYPE => function(ListType $node) {return '[' . $node->type . ']';},
                Node::NON_NULL_TYPE => function(NonNullType $node) {return $node->type . '!';}
            )
        ));
    }

    public static function blockList($list, $separator)
    {
        return self::length($list) === 0 ? null : self::indent("{\n" . self::join($list, $separator)) . "\n}";
    }

    public static function indent($maybeString)
    {
        return $maybeString ? str_replace("\n", "\n  ", $maybeString) : '';
    }

    public static function manyList($start, $list, $separator, $end)
    {
        return self::length($list) === 0 ? null : ($start . self::join($list, $separator) . $end);
    }

    public static function length($maybeArray)
    {
        return $maybeArray ? count($maybeArray) : 0;
    }

    public static function join($maybeArray, $separator = '')
    {
        return $maybeArray
            ? implode(
                $separator,
                array_filter(
                    $maybeArray,
                    function($x) { return !!$x;}
                )
            )
            : '';
    }
}
