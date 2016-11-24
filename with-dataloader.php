<?php

use GraphQL\GraphQL;
use GraphQL\Promise\Adapter\ReactPromiseAdapter;
use GraphQL\Schema;
use GraphQL\Tests\StarWarsData;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use McGWeb\PromiseFactory\Factory\ReactPromiseFactory;
use Overblog\DataLoader\DataLoader;
use React\Promise\Promise;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/vendor/webonyx/graphql-php/tests/StarWarsData.php';

$calls = 0;
$callsIds = [];
$promiseFactory = new ReactPromiseFactory();
$batchLoadFn = function ($ids) use (&$calls, &$callsIds, $promiseFactory) {
    $callsIds[] = $ids;
    ++$calls;
    $allCharacters = StarWarsData::humans() + StarWarsData::droids();
    $characters = array_intersect_key($allCharacters, array_flip($ids));

    return $promiseFactory->createAll(array_values($characters));
};
$dataLoader = new DataLoader($batchLoadFn, $promiseFactory);
/**
 * This implements the following type system shorthand:
 *   type Character : Character {
 *     id: String!
 *     name: String
 *     friends: [Character]
 *   }
 */
$characterType = new ObjectType([
    'name' => 'Character',
    'fields' => function () use (&$characterType, $dataLoader) {
        return [
            'id' => [
                'type' => new NonNull(Type::string()),
                'description' => 'The id of the character.',
            ],
            'name' => [
                'type' => Type::string(),
                'description' => 'The name of the character.',
            ],
            'friends' => array(
                'type' => Type::listOf($characterType),
                'description' => 'The friends of the character, or an empty list if they have none.',
                'resolve' => function ($character) use ($dataLoader) {
                    $onFullFilled = function ($value) use ($dataLoader) {
                        return $dataLoader->loadMany($value['friends']);
                    };

                    if ($character instanceof Promise) {
                        return $character->then($onFullFilled);
                    } else {
                        return $onFullFilled($character);
                    }
                },
            ),
        ];
    },
]);

/**
 * This implements the following type system shorthand:
 *   type Query {
 *     character(id: String!): Character
 *   }
 *
 */
$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'character' => [
            'type' => $characterType,
            'args' => [
                'id' => [
                    'name' => 'id',
                    'description' => 'id of the character',
                    'type' => Type::nonNull(Type::string())
                ]
            ],
            'resolve' => function ($root, $args) use ($dataLoader) {
                return $dataLoader->load($args['id']);
            },
        ],
    ]
]);

GraphQL::setPromiseAdapter(new ReactPromiseAdapter());

$schema = new Schema(['query' => $queryType]);

$queries = [
    '{ character1: character(id: "1000") { name friends { name }} character2: character(id: "1002") { name friends { name }}}',
    '{ character1: character(id: "1000") { name } character2: character(id: "1002") { name }}'
];

foreach ($queries as $query) {
    $calls = 0;
    $callsIds = [];
    $dataLoader->clearAll();

    /**
     * @var \React\Promise\PromiseInterface $promise
     */
    $promise = GraphQL::execute($schema, $query);
    $data = DataLoader::await($promise);

    echo "With DataLoader:\n\n";
    echo "Query: $query\n";
    echo "Response:\n".json_encode($data, JSON_PRETTY_PRINT)."\n";
    echo "Resolver calls: ".var_export($calls, true)."\n";
    echo "calls ids: ".var_export($callsIds, true)."\n";

    echo "\n\n";
}

