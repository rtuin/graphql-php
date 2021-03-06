<?php
namespace GraphQL\Type;

use GraphQL\Schema;
use GraphQL\Type\Definition\Config;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;

class DefinitionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ObjectType
     */
    public $blogImage;

    /**
     * @var ObjectType
     */
    public $blogArticle;

    /**
     * @var ObjectType
     */
    public $blogAuthor;

    /**
     * @var ObjectType
     */
    public $blogMutation;

    /**
     * @var ObjectType
     */
    public $blogQuery;

    /**
     * @var ObjectType
     */
    public $objectType;

    /**
     * @var InterfaceType
     */
    public $interfaceType;

    /**
     * @var UnionType
     */
    public $unionType;

    /**
     * @var EnumType
     */
    public $enumType;

    /**
     * @var InputObjectType
     */
    public $inputObjectType;

    public function setUp()
    {
        $this->objectType = new ObjectType(['name' => 'Object']);
        $this->interfaceType = new InterfaceType(['name' => 'Interface']);
        $this->unionType = new UnionType(['name' => 'Union', 'types' => [$this->objectType]]);
        $this->enumType = new EnumType(['name' => 'Enum']);
        $this->inputObjectType = new InputObjectType(['name' => 'InputObject']);

        $this->blogImage = new ObjectType([
            'name' => 'Image',
            'fields' => [
                'url' => ['type' => Type::string()],
                'width' => ['type' => Type::int()],
                'height' => ['type' => Type::int()]
            ]
        ]);

        $this->blogAuthor = new ObjectType([
            'name' => 'Author',
            'fields' => [
                'id' => ['type' => Type::string()],
                'name' => ['type' => Type::string()],
                'pic' => [ 'type' => $this->blogImage, 'args' => [
                    'width' => ['type' => Type::int()],
                    'height' => ['type' => Type::int()]
                ]],
                'recentArticle' => ['type' => function() {return $this->blogArticle;}],
            ],
        ]);

        $this->blogArticle = new ObjectType([
            'name' => 'Article',
            'fields' => [
                'id' => ['type' => Type::string()],
                'isPublished' => ['type' => Type::boolean()],
                'author' => ['type' => $this->blogAuthor],
                'title' => ['type' => Type::string()],
                'body' => ['type' => Type::string()]
            ]
        ]);

        $this->blogQuery = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'article' => ['type' => $this->blogArticle, 'args' => [
                    'id' => ['type' => Type::string()]
                ]],
                'feed' => ['type' => new ListOfType($this->blogArticle)]
            ]
        ]);

        $this->blogMutation = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'writeArticle' => ['type' => $this->blogArticle]
            ]
        ]);
    }

    public function testDefinesAQueryOnlySchema()
    {
        $blogSchema = new Schema($this->blogQuery);

        $this->assertSame($blogSchema->getQueryType(), $this->blogQuery);

        $articleField = $this->blogQuery->getField('article');
        $this->assertSame($articleField->getType(), $this->blogArticle);
        $this->assertSame($articleField->getType()->name, 'Article');
        $this->assertSame($articleField->name, 'article');

        /** @var ObjectType $articleFieldType */
        $articleFieldType = $articleField->getType();
        $titleField = $articleFieldType->getField('title');

        $this->assertInstanceOf('GraphQL\Type\Definition\FieldDefinition', $titleField);
        $this->assertSame('title', $titleField->name);
        $this->assertSame(Type::string(), $titleField->getType());

        $authorField = $articleFieldType->getField('author');
        $this->assertInstanceOf('GraphQL\Type\Definition\FieldDefinition', $authorField);

        /** @var ObjectType $authorFieldType */
        $authorFieldType = $authorField->getType();
        $this->assertSame($this->blogAuthor, $authorFieldType);

        $recentArticleField = $authorFieldType->getField('recentArticle');
        $this->assertInstanceOf('GraphQL\Type\Definition\FieldDefinition', $recentArticleField);
        $this->assertSame($this->blogArticle, $recentArticleField->getType());

        $feedField = $this->blogQuery->getField('feed');
        $this->assertInstanceOf('GraphQL\Type\Definition\FieldDefinition', $feedField);

        /** @var ListOfType $feedFieldType */
        $feedFieldType = $feedField->getType();
        $this->assertInstanceOf('GraphQL\Type\Definition\ListOfType', $feedFieldType);
        $this->assertSame($this->blogArticle, $feedFieldType->getWrappedType());
    }

    public function testDefinesAMutationSchema()
    {
        $schema = new Schema($this->blogQuery, $this->blogMutation);

        $this->assertSame($this->blogMutation, $schema->getMutationType());
        $writeMutation = $this->blogMutation->getField('writeArticle');

        $this->assertInstanceOf('GraphQL\Type\Definition\FieldDefinition', $writeMutation);
        $this->assertSame($this->blogArticle, $writeMutation->getType());
        $this->assertSame('Article', $writeMutation->getType()->name);
        $this->assertSame('writeArticle', $writeMutation->name);
    }

    public function testIncludesInterfaceSubtypesInTheTypeMap()
    {
        $someInterface = new InterfaceType([
            'name' => 'SomeInterface',
            'fields' => []
        ]);

        $someSubtype = new ObjectType([
            'name' => 'SomeSubtype',
            'fields' => [],
            'interfaces' => [$someInterface]
        ]);

        $schema = new Schema($someInterface);
        $this->assertSame($someSubtype, $schema->getType('SomeSubtype'));
    }

    public function testStringifiesSimpleTypes()
    {
        $this->assertSame('Int', (string) Type::int());
        $this->assertSame('Article', (string) $this->blogArticle);

        $this->assertSame('Interface', (string) $this->interfaceType);
        $this->assertSame('Union', (string) $this->unionType);
        $this->assertSame('Enum', (string) $this->enumType);
        $this->assertSame('InputObject', (string) $this->inputObjectType);
        $this->assertSame('Object', (string) $this->objectType);

        $this->assertSame('Int!', (string) new NonNull(Type::int()));
        $this->assertSame('[Int]', (string) new ListOfType(Type::int()));
        $this->assertSame('[Int]!', (string) new NonNull(new ListOfType(Type::int())));
        $this->assertSame('[Int!]', (string) new ListOfType(new NonNull(Type::int())));
        $this->assertSame('[[Int]]', (string) new ListOfType(new ListOfType(Type::int())));
    }

    public function testIdentifiesInputTypes()
    {
        $expected = [
            [Type::int(), true],
            [$this->objectType, false],
            [$this->interfaceType, false],
            [$this->unionType, false],
            [$this->enumType, true],
            [$this->inputObjectType, true]
        ];

        foreach ($expected as $index => $entry) {
            $this->assertSame($entry[1], Type::isInputType($entry[0]), "Type {$entry[0]} was detected incorrectly");
        }
    }

    public function testIdentifiesOutputTypes()
    {
        $expected = [
            [Type::int(), true],
            [$this->objectType, true],
            [$this->interfaceType, true],
            [$this->unionType, true],
            [$this->enumType, true],
            [$this->inputObjectType, false]
        ];

        foreach ($expected as $index => $entry) {
            $this->assertSame($entry[1], Type::isOutputType($entry[0]), "Type {$entry[0]} was detected incorrectly");
        }
    }

    public function testProhibitsNonNullNesting()
    {
        $this->setExpectedException('\Exception');
        new NonNull(new NonNull(Type::int()));
    }

    public function testProhibitsPuttingNonObjectTypesInUnions()
    {
        $int = Type::int();

        $badUnionTypes = [
            $int,
            new NonNull($int),
            new ListOfType($int),
            $this->interfaceType,
            $this->unionType,
            $this->enumType,
            $this->inputObjectType
        ];

        Config::enableValidation();
        foreach ($badUnionTypes as $type) {
            try {
                new UnionType(['name' => 'BadUnion', 'types' => [$type]]);
                $this->fail('Expected exception not thrown');
            } catch (\Exception $e) {
                $this->assertSame(
                    'Expecting callable or instance of GraphQL\Type\Definition\ObjectType at "types:0", but got "' . get_class($type) . '"',
                    $e->getMessage()
                );
            }
        }
        Config::disableValidation();
    }
}
